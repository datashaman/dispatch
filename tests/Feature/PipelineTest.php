<?php

use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\RuleConfig;
use App\Exceptions\ConfigLoadException;
use App\Exceptions\PipelineException;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\WebhookLog;
use App\Services\AgentDispatcher;
use App\Services\ConfigLoader;
use App\Services\PipelineOrchestrator;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Yaml\Yaml;

// --- PipelineOrchestrator ---

test('orchestrator detects pipeline when rules have depends_on', function () {
    $orchestrator = new PipelineOrchestrator;

    $withDeps = collect([
        new RuleConfig(id: 'a', event: 'issues.opened', prompt: 'test'),
        new RuleConfig(id: 'b', event: 'issues.opened', prompt: 'test', dependsOn: ['a']),
    ]);

    $withoutDeps = collect([
        new RuleConfig(id: 'a', event: 'issues.opened', prompt: 'test'),
        new RuleConfig(id: 'b', event: 'issues.opened', prompt: 'test'),
    ]);

    expect($orchestrator->hasPipeline($withDeps))->toBeTrue();
    expect($orchestrator->hasPipeline($withoutDeps))->toBeFalse();
});

test('orchestrator sorts rules topologically', function () {
    $orchestrator = new PipelineOrchestrator;

    $rules = collect([
        new RuleConfig(id: 'c', event: 'issues.opened', prompt: 'test', dependsOn: ['a', 'b']),
        new RuleConfig(id: 'a', event: 'issues.opened', prompt: 'test'),
        new RuleConfig(id: 'b', event: 'issues.opened', prompt: 'test', dependsOn: ['a']),
    ]);

    $sorted = $orchestrator->resolve($rules);

    $ids = $sorted->pluck('id')->all();
    expect($ids[0])->toBe('a');
    expect(array_search('b', $ids))->toBeLessThan(array_search('c', $ids));
});

test('orchestrator detects circular dependencies', function () {
    $orchestrator = new PipelineOrchestrator;

    $rules = collect([
        new RuleConfig(id: 'a', event: 'issues.opened', prompt: 'test', dependsOn: ['b']),
        new RuleConfig(id: 'b', event: 'issues.opened', prompt: 'test', dependsOn: ['a']),
    ]);

    $orchestrator->resolve($rules);
})->throws(PipelineException::class, 'Circular dependency');

test('orchestrator throws when dependency not matched', function () {
    $orchestrator = new PipelineOrchestrator;

    $rules = collect([
        new RuleConfig(id: 'a', event: 'issues.opened', prompt: 'test', dependsOn: ['missing']),
    ]);

    $orchestrator->resolve($rules);
})->throws(PipelineException::class, 'not matched');

test('orchestrator handles independent rules in pipeline', function () {
    $orchestrator = new PipelineOrchestrator;

    $rules = collect([
        new RuleConfig(id: 'a', event: 'issues.opened', prompt: 'test'),
        new RuleConfig(id: 'b', event: 'issues.opened', prompt: 'test'),
        new RuleConfig(id: 'c', event: 'issues.opened', prompt: 'test', dependsOn: ['a']),
    ]);

    $sorted = $orchestrator->resolve($rules);
    $ids = $sorted->pluck('id')->all();

    // 'a' must come before 'c', 'b' can be anywhere
    expect(array_search('a', $ids))->toBeLessThan(array_search('c', $ids));
    expect($ids)->toContain('b');
});

test('orchestrator throws on duplicate rule IDs', function () {
    $orchestrator = new PipelineOrchestrator;

    $rules = collect([
        new RuleConfig(id: 'a', event: 'issues.opened', prompt: 'test'),
        new RuleConfig(id: 'a', event: 'issues.opened', prompt: 'test duplicate'),
    ]);

    $orchestrator->resolve($rules);
})->throws(PipelineException::class, 'Duplicate rule IDs');

// --- ConfigLoader depends_on parsing ---

test('config loader parses depends_on field', function () {
    $projectPath = sys_get_temp_dir().'/dispatch-pipeline-test-'.uniqid();
    mkdir($projectPath, 0755, true);

    file_put_contents($projectPath.'/dispatch.yml', Yaml::dump([
        'version' => 1,
        'agent' => ['name' => 'test', 'executor' => 'laravel-ai'],
        'rules' => [
            ['id' => 'analyze', 'event' => 'issues.opened', 'prompt' => 'Analyze the issue.'],
            ['id' => 'implement', 'event' => 'issues.opened', 'prompt' => 'Implement.', 'depends_on' => ['analyze']],
        ],
    ]));

    $loader = app(ConfigLoader::class);
    $config = $loader->loadFromDisk($projectPath);

    expect($config->rules[0]->dependsOn)->toBe([]);
    expect($config->rules[1]->dependsOn)->toBe(['analyze']);

    unlink($projectPath.'/dispatch.yml');
    rmdir($projectPath);
});

test('config loader handles single string depends_on', function () {
    $projectPath = sys_get_temp_dir().'/dispatch-pipeline-test-'.uniqid();
    mkdir($projectPath, 0755, true);

    file_put_contents($projectPath.'/dispatch.yml', Yaml::dump([
        'version' => 1,
        'agent' => ['name' => 'test', 'executor' => 'laravel-ai'],
        'rules' => [
            ['id' => 'a', 'event' => 'issues.opened', 'prompt' => 'First.'],
            ['id' => 'b', 'event' => 'issues.opened', 'prompt' => 'Second.', 'depends_on' => 'a'],
        ],
    ]));

    $loader = app(ConfigLoader::class);
    $config = $loader->loadFromDisk($projectPath);

    expect($config->rules[1]->dependsOn)->toBe(['a']);

    unlink($projectPath.'/dispatch.yml');
    rmdir($projectPath);
});

test('config loader validates depends_on elements are strings', function () {
    $projectPath = sys_get_temp_dir().'/dispatch-pipeline-test-'.uniqid();
    mkdir($projectPath, 0755, true);

    // Write raw YAML with a nested mapping as a depends_on entry
    $yaml = <<<'YAML'
version: 1
agent:
  name: test
  executor: laravel-ai
rules:
  - id: a
    event: issues.opened
    prompt: First.
  - id: b
    event: issues.opened
    prompt: Second.
    depends_on:
      - nested_key: value
YAML;

    file_put_contents($projectPath.'/dispatch.yml', $yaml);

    $loader = app(ConfigLoader::class);

    expect(fn () => $loader->loadFromDisk($projectPath))
        ->toThrow(ConfigLoadException::class, 'must be a string');

    unlink($projectPath.'/dispatch.yml');
    rmdir($projectPath);
});

// --- AgentDispatcher pipeline integration ---

test('dispatcher dispatches pipeline rules in dependency order via chain', function () {
    Bus::fake();

    $webhookLog = WebhookLog::factory()->create();
    $project = Project::factory()->create();
    $config = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        rules: [],
    );

    $rules = collect([
        new RuleConfig(id: 'step2', event: 'issues.opened', prompt: 'Step 2', dependsOn: ['step1']),
        new RuleConfig(id: 'step1', event: 'issues.opened', prompt: 'Step 1'),
    ]);

    $dispatcher = app(AgentDispatcher::class);
    $results = $dispatcher->dispatch($webhookLog, $rules, ['test' => true], $project, $config);

    // Both should be queued, step1 first
    expect($results)->toHaveCount(2);
    expect($results[0]['rule'])->toBe('step1');
    expect($results[1]['rule'])->toBe('step2');
    expect($results[1]['pipeline'])->toBeTrue();

    // Verify chain was dispatched (not individual dispatches)
    Bus::assertChained([
        ProcessAgentRun::class,
        ProcessAgentRun::class,
    ]);

    // Verify the step2 AgentRun has upstream_run_ids pointing to step1's run
    $step1Run = AgentRun::where('rule_id', 'step1')->first();
    $step2Run = AgentRun::where('rule_id', 'step2')->first();

    expect($step2Run->upstream_run_ids)->toBe(['step1' => $step1Run->id]);
    expect($step1Run->upstream_run_ids)->toBeNull();
});

test('dependent job loads upstream outputs at execution time', function () {
    $webhookLog = WebhookLog::factory()->create();

    // Create a completed upstream run with output
    $upstreamRun = AgentRun::factory()->completed()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'step1',
        'output' => 'Analysis result from step1',
    ]);

    // Create the dependent run with upstream_run_ids
    $dependentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'step2',
        'upstream_run_ids' => ['step1' => $upstreamRun->id],
        'status' => 'queued',
    ]);

    $rule = new RuleConfig(id: 'step2', event: 'issues.opened', prompt: 'Step 2', dependsOn: ['step1']);
    $project = Project::factory()->create();
    $config = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        rules: [],
    );

    $payload = ['test' => true];

    $job = new ProcessAgentRun($dependentRun, $rule, $payload, $project, $config);

    // Use reflection to call the protected injectUpstreamOutputs method
    $reflection = new ReflectionMethod($job, 'injectUpstreamOutputs');
    $reflection->invoke($job);

    expect($job->payload['upstream_outputs'])->toBe([
        'step1' => 'Analysis result from step1',
    ]);
});

test('dependent job skips when upstream has failed', function () {
    $webhookLog = WebhookLog::factory()->create();

    // Create a failed upstream run
    $upstreamRun = AgentRun::factory()->failed()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'step1',
    ]);

    // Create the dependent run with upstream_run_ids
    $dependentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'step2',
        'upstream_run_ids' => ['step1' => $upstreamRun->id],
        'status' => 'queued',
    ]);

    $rule = new RuleConfig(id: 'step2', event: 'issues.opened', prompt: 'Step 2', dependsOn: ['step1']);
    $project = Project::factory()->create();
    $config = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        rules: [],
    );

    $job = new ProcessAgentRun($dependentRun, $rule, ['test' => true], $project, $config);

    // Use reflection to check hasFailedUpstream
    $reflection = new ReflectionMethod($job, 'hasFailedUpstream');
    expect($reflection->invoke($job))->toBeTrue();
});

test('dispatcher handles circular dependency gracefully', function () {
    $webhookLog = WebhookLog::factory()->create();
    $project = Project::factory()->create();
    $config = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        rules: [],
    );

    $rules = collect([
        new RuleConfig(id: 'a', event: 'issues.opened', prompt: 'A', dependsOn: ['b']),
        new RuleConfig(id: 'b', event: 'issues.opened', prompt: 'B', dependsOn: ['a']),
    ]);

    $dispatcher = app(AgentDispatcher::class);
    $results = $dispatcher->dispatch($webhookLog, $rules, ['test' => true], $project, $config);

    // Both rules should be skipped due to pipeline error
    expect($results)->toHaveCount(2);
    expect($results[0]['status'])->toBe('skipped');
    expect($results[0]['reason'])->toBe('pipeline_error');
    expect($results[1]['status'])->toBe('skipped');
});

test('dispatcher dispatches normally when no dependencies', function () {
    Bus::fake([ProcessAgentRun::class]);

    $webhookLog = WebhookLog::factory()->create();
    $project = Project::factory()->create();
    $config = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        rules: [],
    );

    $rules = collect([
        new RuleConfig(id: 'a', event: 'issues.opened', prompt: 'A'),
        new RuleConfig(id: 'b', event: 'issues.opened', prompt: 'B'),
    ]);

    $dispatcher = app(AgentDispatcher::class);
    $results = $dispatcher->dispatch($webhookLog, $rules, ['test' => true], $project, $config);

    expect($results)->toHaveCount(2);
    expect($results[0]['status'])->toBe('queued');
    expect($results[1]['status'])->toBe('queued');
    // No pipeline flag
    expect($results[0])->not->toHaveKey('pipeline');

    Bus::assertDispatched(ProcessAgentRun::class, 2);
});
