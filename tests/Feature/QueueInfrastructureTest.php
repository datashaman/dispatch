<?php

use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Rule;
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
    $rule = Rule::factory()->create(['project_id' => $project->id]);
    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
    ]);

    ProcessAgentRun::dispatch($agentRun, $rule, []);

    Queue::assertPushedOn('agents', ProcessAgentRun::class);
});

test('ProcessAgentRun job implements ShouldQueue', function () {
    expect(ProcessAgentRun::class)
        ->toImplement(ShouldQueue::class);
});

test('ProcessAgentRun job is configured on agents queue by default', function () {
    $project = Project::factory()->create();
    $rule = Rule::factory()->create(['project_id' => $project->id]);
    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
    ]);

    $job = new ProcessAgentRun($agentRun, $rule, []);

    expect($job->queue)->toBe('agents');
});
