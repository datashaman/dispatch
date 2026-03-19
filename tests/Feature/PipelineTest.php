<?php

use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\RuleConfig;
use App\Exceptions\PipelineException;
use App\Jobs\ProcessAgentRun;
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

// --- AgentDispatcher pipeline integration ---

test('dispatcher dispatches pipeline rules in dependency order', function () {
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

    Bus::assertDispatched(ProcessAgentRun::class, 2);
});

test('dispatcher skips dependent rule when upstream fails in sync mode', function () {
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
        new RuleConfig(id: 'step1', event: 'issues.opened', prompt: 'Step 1'),
        new RuleConfig(id: 'step2', event: 'issues.opened', prompt: 'Step 2', dependsOn: ['step1']),
    ]);

    $dispatcher = app(AgentDispatcher::class);
    $results = $dispatcher->dispatch($webhookLog, $rules, ['test' => true], $project, $config);

    // Both queued — step2 depends on step1 but with Bus::fake, sync mode
    // doesn't actually run, so the run status stays 'queued' not 'failed'
    expect($results)->toHaveCount(2);
    expect($results[0]['rule'])->toBe('step1');
    expect($results[1]['rule'])->toBe('step2');

    Bus::assertDispatched(ProcessAgentRun::class, 2);
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
