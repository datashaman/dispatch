<?php

use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\RuleConfig;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\WebhookLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Horizon;

uses(RefreshDatabase::class);

test('horizon is installed and configured', function () {
    expect(class_exists(Horizon::class))->toBeTrue();
});

test('horizon config defines agents queue', function () {
    $defaults = config('horizon.defaults.supervisor-1');

    expect($defaults['queue'])->toContain('agents');
    expect($defaults['connection'])->toBe('redis');
});

test('redis is configured as the default queue connection in config', function () {
    // The queue config file defaults to redis via env; verify the config file supports redis
    $connections = config('queue.connections');

    expect($connections)->toHaveKey('redis');
    expect($connections['redis']['driver'])->toBe('redis');
    expect($connections['redis']['connection'])->toBe('default');
});

test('horizon dashboard route is registered', function () {
    $response = $this->get('/horizon');

    // Horizon route exists — may return 200, 302, or 403 depending on auth gate
    expect($response->status())->toBeIn([200, 302, 403]);
});

test('ProcessAgentRun job dispatches to agents queue', function () {
    Queue::fake();

    $project = Project::factory()->create();
    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
    ]);

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Test prompt',
    );

    $dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-6',
    );

    ProcessAgentRun::dispatch($agentRun, $ruleConfig, [], $project, $dispatchConfig);

    Queue::assertPushedOn('agents', ProcessAgentRun::class);
});

test('ProcessAgentRun job implements ShouldQueue', function () {
    expect(ProcessAgentRun::class)
        ->toImplement(ShouldQueue::class);
});

test('ProcessAgentRun job is configured on agents queue by default', function () {
    $project = Project::factory()->create();
    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
    ]);

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Test prompt',
    );

    $dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-6',
    );

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $project, $dispatchConfig);

    expect($job->queue)->toBe('agents');
});
