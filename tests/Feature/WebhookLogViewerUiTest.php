<?php

use App\Models\AgentRun;
use App\Models\User;
use App\Models\WebhookLog;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('webhook logs page is accessible to authenticated users', function () {
    $this->get(route('webhooks.index'))
        ->assertStatus(200);
});

test('webhook logs page requires authentication', function () {
    auth()->logout();

    $this->get(route('webhooks.index'))
        ->assertRedirect(route('login'));
});

test('lists webhook logs with event type, repo, status, and timestamp', function () {
    WebhookLog::factory()->create([
        'event_type' => 'issues.labeled',
        'repo' => 'owner/test-repo',
        'status' => 'processed',
        'matched_rules' => ['analyze', 'review'],
        'created_at' => now(),
    ]);

    Volt::test('pages::webhooks.index')
        ->assertSee('issues.labeled')
        ->assertSee('owner/test-repo')
        ->assertSee('processed')
        ->assertSee('2 rules');
});

test('shows empty state when no webhook logs exist', function () {
    Volt::test('pages::webhooks.index')
        ->assertSee('No webhook logs');
});

test('filters logs by repo', function () {
    WebhookLog::factory()->create(['repo' => 'owner/repo-a', 'event_type' => 'issues.labeled']);
    WebhookLog::factory()->create(['repo' => 'owner/repo-b', 'event_type' => 'issues.labeled']);

    $component = Volt::test('pages::webhooks.index')
        ->set('filterRepo', 'owner/repo-a')
        ->assertSee('owner/repo-a');

    $logs = $component->instance()->getLogs();
    expect($logs)->toHaveCount(1);
    expect($logs->first()->repo)->toBe('owner/repo-a');
});

test('filters logs by event type', function () {
    WebhookLog::factory()->create(['event_type' => 'issues.labeled', 'repo' => 'owner/repo']);
    WebhookLog::factory()->create(['event_type' => 'pull_request.opened', 'repo' => 'owner/repo']);

    $component = Volt::test('pages::webhooks.index')
        ->set('filterEventType', 'issues.labeled');

    $logs = $component->instance()->getLogs();
    expect($logs)->toHaveCount(1);
    expect($logs->first()->event_type)->toBe('issues.labeled');
});

test('filters logs by status', function () {
    WebhookLog::factory()->create(['status' => 'processed', 'event_type' => 'issues.labeled']);
    WebhookLog::factory()->create(['status' => 'error', 'event_type' => 'issues.labeled']);

    $component = Volt::test('pages::webhooks.index')
        ->set('filterStatus', 'processed');

    $logs = $component->instance()->getLogs();
    expect($logs)->toHaveCount(1);
    expect($logs->first()->status)->toBe('processed');
});

test('clears all filters', function () {
    Volt::test('pages::webhooks.index')
        ->set('filterRepo', 'owner/repo')
        ->set('filterEventType', 'push')
        ->set('filterStatus', 'error')
        ->call('clearFilters')
        ->assertSet('filterRepo', '')
        ->assertSet('filterEventType', '')
        ->assertSet('filterStatus', '');
});

test('webhook log detail page is accessible', function () {
    $log = WebhookLog::factory()->create([
        'event_type' => 'issues.labeled',
        'repo' => 'owner/test-repo',
        'status' => 'processed',
        'payload' => ['action' => 'labeled', 'issue' => ['number' => 42]],
        'matched_rules' => ['analyze'],
    ]);

    $this->get(route('webhooks.show', $log))
        ->assertStatus(200);
});

test('webhook log detail page requires authentication', function () {
    $log = WebhookLog::factory()->create();

    auth()->logout();

    $this->get(route('webhooks.show', $log))
        ->assertRedirect(route('login'));
});

test('webhook log detail shows full payload', function () {
    $log = WebhookLog::factory()->create([
        'event_type' => 'issues.labeled',
        'repo' => 'owner/test-repo',
        'payload' => ['action' => 'labeled', 'issue' => ['number' => 42, 'title' => 'Test Issue']],
        'matched_rules' => ['analyze'],
        'status' => 'processed',
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $log->id])
        ->assertSee('issues.labeled')
        ->assertSee('owner/test-repo')
        ->assertSee('Test Issue')
        ->assertSee('analyze');
});

test('webhook log detail shows agent runs with status and metrics', function () {
    $log = WebhookLog::factory()->create([
        'event_type' => 'issues.labeled',
        'status' => 'processed',
    ]);

    AgentRun::factory()->completed()->create([
        'webhook_log_id' => $log->id,
        'rule_id' => 'analyze',
        'tokens_used' => 1500,
        'cost' => 0.0150,
        'duration_ms' => 5000,
    ]);

    AgentRun::factory()->failed()->create([
        'webhook_log_id' => $log->id,
        'rule_id' => 'review',
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $log->id])
        ->assertSee('analyze')
        ->assertSee('review')
        ->assertSee('success')
        ->assertSee('failed')
        ->assertSee('1,500')
        ->assertSee('$0.0150')
        ->assertSee('5.0s');
});

test('agent run detail modal shows output and error', function () {
    $log = WebhookLog::factory()->create(['status' => 'processed']);

    $run = AgentRun::factory()->create([
        'webhook_log_id' => $log->id,
        'rule_id' => 'analyze',
        'status' => 'failed',
        'output' => 'Partial agent output here',
        'error' => 'Connection timed out',
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $log->id])
        ->call('viewAgentRun', $run->id)
        ->assertSet('showAgentRunDetail', true)
        ->assertSet('viewingAgentRunId', $run->id)
        ->assertSee('Partial agent output here')
        ->assertSee('Connection timed out');
});

test('webhook log detail shows error message', function () {
    $log = WebhookLog::factory()->create([
        'status' => 'error',
        'error' => 'Missing repository in payload',
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $log->id])
        ->assertSee('Missing repository in payload');
});

test('webhook log detail enables polling for in-progress runs', function () {
    $log = WebhookLog::factory()->create(['status' => 'processed']);

    AgentRun::factory()->create([
        'webhook_log_id' => $log->id,
        'rule_id' => 'analyze',
        'status' => 'running',
    ]);

    $component = Volt::test('pages::webhooks.show', ['webhookLog' => $log->id]);

    expect($component->instance()->hasInProgressRuns())->toBeTrue();
});

test('webhook log detail does not poll when no in-progress runs', function () {
    $log = WebhookLog::factory()->create(['status' => 'processed']);

    AgentRun::factory()->completed()->create([
        'webhook_log_id' => $log->id,
        'rule_id' => 'analyze',
    ]);

    $component = Volt::test('pages::webhooks.show', ['webhookLog' => $log->id]);

    expect($component->instance()->hasInProgressRuns())->toBeFalse();
});

test('webhook log not found shows error', function () {
    Volt::test('pages::webhooks.show', ['webhookLog' => 99999])
        ->assertSee('Webhook log not found');
});
