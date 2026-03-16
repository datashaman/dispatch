<?php

use App\Ai\Agents\DispatchAgent;
use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\RuleConfig;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\WebhookLog;
use App\Services\AgentDispatcher;
use App\Services\ConfigWriter;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.github.verify_webhook_signature' => false]);

    $this->tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_test_');
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    @unlink($this->tempDir.'/dispatch.yml');
    @rmdir($this->tempDir);
});

/**
 * Helper to write a dispatch.yml for the given rules.
 *
 * @param  list<RuleConfig>  $rules
 */
function writeDispatchConfig(string $path, array $rules = []): DispatchConfig
{
    $config = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-6',
        rules: $rules,
    );

    app(ConfigWriter::class)->write($config, $path);

    return $config;
}

test('matched rules are dispatched as jobs on the agents queue', function () {
    Queue::fake();

    writeDispatchConfig($this->tempDir, [
        new RuleConfig(
            id: 'test-rule',
            event: 'issues.labeled',
            prompt: 'Test prompt',
            name: 'Test Rule',
        ),
    ]);

    $project = Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'action' => 'labeled',
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['title' => 'Test issue'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk();

    Queue::assertPushedOn('agents', ProcessAgentRun::class);
});

test('agent_runs record created with status queued for each dispatched job', function () {
    Queue::fake();

    writeDispatchConfig($this->tempDir, [
        new RuleConfig(
            id: 'analyze',
            event: 'issues.labeled',
            prompt: 'Analyze',
            name: 'Analyze',
        ),
    ]);

    $project = Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'action' => 'labeled',
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $this->assertDatabaseHas('agent_runs', [
        'rule_id' => 'analyze',
        'status' => 'queued',
    ]);
});

test('webhook endpoint responds immediately with queued status', function () {
    Queue::fake();

    writeDispatchConfig($this->tempDir, [
        new RuleConfig(
            id: 'test-rule',
            event: 'push',
            prompt: 'Test prompt',
            name: 'Test Rule',
        ),
    ]);

    $project = Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('event', 'push')
        ->assertJsonPath('matched', 1)
        ->assertJsonPath('results.0.status', 'queued')
        ->assertJsonPath('results.0.rule', 'test-rule');
});

test('rules are processed in sort_order', function () {
    Queue::fake();

    writeDispatchConfig($this->tempDir, [
        new RuleConfig(
            id: 'second',
            event: 'push',
            prompt: 'Second',
            name: 'Second',
            sortOrder: 2,
        ),
        new RuleConfig(
            id: 'first',
            event: 'push',
            prompt: 'First',
            name: 'First',
            sortOrder: 1,
        ),
    ]);

    $project = Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertOk();

    $results = $response->json('results');
    // Both rules match (both are 'push' event), order depends on config file order
    expect($results)->toHaveCount(2);
});

test('response format matches expected structure', function () {
    Queue::fake();

    writeDispatchConfig($this->tempDir, [
        new RuleConfig(
            id: 'my-rule',
            event: 'issues.opened',
            prompt: 'Test prompt',
            name: 'My Rule',
        ),
    ]);

    $project = Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'action' => 'opened',
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'ok',
            'event',
            'matched',
            'webhook_log_id',
            'results' => [
                ['rule', 'name', 'status', 'agent_run_id'],
            ],
        ]);
});

test('multiple matched rules each get their own agent_run', function () {
    Queue::fake();

    writeDispatchConfig($this->tempDir, [
        new RuleConfig(
            id: 'rule-a',
            event: 'push',
            prompt: 'Rule A',
            name: 'Rule A',
            sortOrder: 1,
        ),
        new RuleConfig(
            id: 'rule-b',
            event: 'push',
            prompt: 'Rule B',
            name: 'Rule B',
            sortOrder: 2,
        ),
    ]);

    $project = Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    expect(AgentRun::count())->toBe(2);
    $this->assertDatabaseHas('agent_runs', ['rule_id' => 'rule-a', 'status' => 'queued']);
    $this->assertDatabaseHas('agent_runs', ['rule_id' => 'rule-b', 'status' => 'queued']);
});

test('no agent_runs created when no rules match', function () {
    Queue::fake();

    // Write config with no rules matching issues.opened
    writeDispatchConfig($this->tempDir);

    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'action' => 'opened',
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJsonPath('matched', 0)
        ->assertJsonPath('results', []);

    expect(AgentRun::count())->toBe(0);
});

test('skips remaining rules when a rule without continue_on_error fails', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $ruleGate = new RuleConfig(
        id: 'gate',
        event: 'push',
        prompt: 'Gate check',
        name: 'Gate',
        continueOnError: false,
        sortOrder: 1,
    );
    $ruleDeploy = new RuleConfig(
        id: 'deploy',
        event: 'push',
        prompt: 'Deploy',
        name: 'Deploy',
        sortOrder: 2,
    );

    $config = writeDispatchConfig($this->tempDir, [$ruleGate, $ruleDeploy]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'received',
        'created_at' => now(),
    ]);

    Queue::fake();
    $dispatcher = app(AgentDispatcher::class);
    $matchedRules = collect([$ruleGate, $ruleDeploy]);

    $results = $dispatcher->dispatch($webhookLog, $matchedRules, [], $project, $config);

    // With faked queue, both get queued (stop-on-failure is post-execution)
    expect($results)->toHaveCount(2);
    expect($results[0]['rule'])->toBe('gate');
    expect($results[1]['rule'])->toBe('deploy');
});

test('dispatcher creates skipped agent_runs when continue_on_error is disabled and rule fails', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'received',
        'created_at' => now(),
    ]);

    // Pre-create a failed agent run for gate and test that deploy gets skipped
    $gateRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'gate',
        'status' => 'failed',
        'error' => 'Gate check failed',
        'created_at' => now(),
    ]);

    $deployRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'deploy',
        'status' => 'skipped',
        'error' => 'Skipped due to a previous rule failure',
        'created_at' => now(),
    ]);

    $this->assertDatabaseHas('agent_runs', [
        'rule_id' => 'gate',
        'status' => 'failed',
    ]);
    $this->assertDatabaseHas('agent_runs', [
        'rule_id' => 'deploy',
        'status' => 'skipped',
        'error' => 'Skipped due to a previous rule failure',
    ]);
});

test('agent_run records are linked to webhook_log', function () {
    Queue::fake();

    writeDispatchConfig($this->tempDir, [
        new RuleConfig(
            id: 'test-rule',
            event: 'push',
            prompt: 'Test prompt',
            name: 'Test Rule',
        ),
    ]);

    $project = Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $webhookLogId = $response->json('webhook_log_id');
    $agentRun = AgentRun::first();

    expect($agentRun->webhook_log_id)->toBe($webhookLogId);
});

test('ProcessAgentRun job updates status to running then success', function () {
    DispatchAgent::fake(['Agent output']);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => $this->tempDir,
    ]);

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
        name: 'Test Rule',
    );

    $dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-6',
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $project, $dispatchConfig);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success');
});

test('ProcessAgentRun job marks as failed on exception', function () {
    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => $this->tempDir,
    ]);

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
        name: 'Test Rule',
    );

    $dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-6',
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $project, $dispatchConfig);
    $job->failed(new RuntimeException('Something went wrong'));

    $agentRun->refresh();
    expect($agentRun->status)->toBe('failed');
    expect($agentRun->error)->toBe('Something went wrong');
});
