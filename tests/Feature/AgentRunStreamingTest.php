<?php

use App\Events\AgentRunUpdated;
use App\Models\AgentRun;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Livewire\Volt\Volt;

test('AgentRunUpdated broadcasts on public channel', function () {
    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
    ]);

    $event = new AgentRunUpdated($agentRun);

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(Channel::class);
    expect($channels[0]->name)->toBe("agent-run.{$agentRun->id}");
});

test('AgentRunUpdated includes run data in broadcast payload', function () {
    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'success',
        'output' => 'Agent completed.',
        'tokens_used' => 1500,
        'cost' => 0.0045,
        'duration_ms' => 5000,
        'error' => null,
    ]);

    $event = new AgentRunUpdated($agentRun);
    $payload = $event->broadcastWith();

    expect($payload['id'])->toBe($agentRun->id);
    expect($payload['status'])->toBe('success');
    expect($payload['output'])->toBe('Agent completed.');
    expect($payload['tokens_used'])->toBe(1500);
    expect((float) $payload['cost'])->toBe(0.0045);
    expect($payload['duration_ms'])->toBe(5000);
    expect($payload['error'])->toBeNull();
});

test('AgentRunUpdated dispatched when agent run status changes to running', function () {
    Event::fake([AgentRunUpdated::class]);

    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
    ]);

    $agentRun->update(['status' => 'running']);
    AgentRunUpdated::dispatch($agentRun);

    Event::assertDispatched(AgentRunUpdated::class, function ($event) use ($agentRun) {
        return $event->agentRun->id === $agentRun->id
            && $event->agentRun->status === 'running';
    });
});

test('AgentRunUpdated dispatched when agent run completes', function () {
    Event::fake([AgentRunUpdated::class]);

    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
    ]);

    $agentRun->update(['status' => 'success', 'output' => 'Done.']);
    AgentRunUpdated::dispatch($agentRun);

    Event::assertDispatched(AgentRunUpdated::class, function ($event) use ($agentRun) {
        return $event->agentRun->id === $agentRun->id
            && $event->agentRun->status === 'success';
    });
});

test('AgentRunUpdated dispatched when agent run fails', function () {
    Event::fake([AgentRunUpdated::class]);

    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
    ]);

    $agentRun->update(['status' => 'failed', 'error' => 'Timeout']);
    AgentRunUpdated::dispatch($agentRun);

    Event::assertDispatched(AgentRunUpdated::class, function ($event) use ($agentRun) {
        return $event->agentRun->id === $agentRun->id
            && $event->agentRun->status === 'failed';
    });
});

test('webhook show page lists agent run IDs for streaming', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $webhookLog = WebhookLog::factory()->create();
    $run1 = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'rule-a',
        'status' => 'running',
    ]);
    $run2 = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'rule-b',
        'status' => 'queued',
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $webhookLog->id])
        ->assertSee('rule-a')
        ->assertSee('rule-b')
        ->assertSee('running')
        ->assertSee('queued');
});
