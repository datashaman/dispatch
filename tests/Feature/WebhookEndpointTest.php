<?php

use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function webhookPayload(array $overrides = []): array
{
    return array_merge([
        'action' => 'labeled',
        'repository' => [
            'full_name' => 'owner/repo',
        ],
        'issue' => [
            'number' => 1,
            'title' => 'Test issue',
        ],
        'label' => [
            'name' => 'bug',
        ],
        'sender' => [
            'login' => 'testuser',
        ],
    ], $overrides);
}

function signPayload(string $payload, string $secret): string
{
    return 'sha256='.hash_hmac('sha256', $payload, $secret);
}

it('returns 400 when X-GitHub-Event header is missing', function (): void {
    $response = $this->postJson('/api/webhook', webhookPayload());

    $response->assertStatus(400)
        ->assertJson([
            'ok' => false,
            'error' => 'Missing X-GitHub-Event header',
        ]);
});

it('handles ping event', function (): void {
    config(['services.github.verify_webhook_signature' => false]);

    $response = $this->postJson('/api/webhook', [], [
        'X-GitHub-Event' => 'ping',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'ping',
        ]);

    $this->assertDatabaseHas('webhook_logs', [
        'event_type' => 'ping',
        'status' => 'received',
    ]);
});

it('combines event and action into event type', function (): void {
    config(['services.github.verify_webhook_signature' => false]);

    $response = $this->postJson('/api/webhook', webhookPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'issues.labeled',
        ]);
});

it('uses event name only when no action in payload', function (): void {
    config(['services.github.verify_webhook_signature' => false]);

    $payload = webhookPayload();
    unset($payload['action']);

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'push',
        ]);
});

it('logs every incoming webhook to webhook_logs table', function (): void {
    config(['services.github.verify_webhook_signature' => false]);

    $this->postJson('/api/webhook', webhookPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $this->assertDatabaseCount('webhook_logs', 1);

    $log = WebhookLog::first();
    expect($log->event_type)->toBe('issues.labeled')
        ->and($log->repo)->toBe('owner/repo')
        ->and($log->status)->toBe('received')
        ->and($log->payload)->toBeArray()
        ->and($log->payload['action'])->toBe('labeled');
});

it('returns webhook_log_id in response', function (): void {
    config(['services.github.verify_webhook_signature' => false]);

    $response = $this->postJson('/api/webhook', webhookPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['ok', 'event', 'webhook_log_id']);

    $log = WebhookLog::first();
    $response->assertJson(['webhook_log_id' => $log->id]);
});

it('verifies webhook signature when enabled', function (): void {
    $secret = 'test-webhook-secret';
    config([
        'services.github.verify_webhook_signature' => true,
        'services.github.webhook_secret' => $secret,
    ]);

    $payload = json_encode(webhookPayload());
    $signature = signPayload($payload, $secret);

    $response = $this->call('POST', '/api/webhook', [], [], [], [
        'HTTP_X_GITHUB_EVENT' => 'issues',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'issues.labeled',
        ]);
});

it('returns 401 on invalid signature', function (): void {
    config([
        'services.github.verify_webhook_signature' => true,
        'services.github.webhook_secret' => 'correct-secret',
    ]);

    $payload = json_encode(webhookPayload());
    $signature = signPayload($payload, 'wrong-secret');

    $response = $this->call('POST', '/api/webhook', [], [], [], [
        'HTTP_X_GITHUB_EVENT' => 'issues',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertStatus(401)
        ->assertJson([
            'ok' => false,
            'error' => 'Invalid webhook signature',
        ]);

    $this->assertDatabaseHas('webhook_logs', [
        'status' => 'error',
        'error' => 'Invalid signature',
    ]);
});

it('returns 401 when signature header is missing but verification is enabled', function (): void {
    config([
        'services.github.verify_webhook_signature' => true,
        'services.github.webhook_secret' => 'some-secret',
    ]);

    $response = $this->postJson('/api/webhook', webhookPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'ok' => false,
        ]);
});

it('skips signature verification when VERIFY_WEBHOOK_SIGNATURE is false', function (): void {
    config([
        'services.github.verify_webhook_signature' => false,
        'services.github.webhook_secret' => null,
    ]);

    $response = $this->postJson('/api/webhook', webhookPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'issues.labeled',
        ]);
});

it('returns 401 when webhook secret is not configured but verification is enabled', function (): void {
    config([
        'services.github.verify_webhook_signature' => true,
        'services.github.webhook_secret' => null,
    ]);

    $response = $this->postJson('/api/webhook', webhookPayload(), [
        'X-GitHub-Event' => 'issues',
        'X-Hub-Signature-256' => 'sha256=something',
    ]);

    $response->assertStatus(401);
});

it('logs ping events to webhook_logs', function (): void {
    config(['services.github.verify_webhook_signature' => false]);

    $this->postJson('/api/webhook', ['zen' => 'Keep it logically awesome.'], [
        'X-GitHub-Event' => 'ping',
    ]);

    $this->assertDatabaseHas('webhook_logs', [
        'event_type' => 'ping',
        'status' => 'received',
    ]);
});

it('handles webhooks without repository info', function (): void {
    config(['services.github.verify_webhook_signature' => false]);

    $response = $this->postJson('/api/webhook', ['action' => 'completed'], [
        'X-GitHub-Event' => 'check_suite',
    ]);

    $response->assertOk();

    $log = WebhookLog::first();
    expect($log->repo)->toBeNull();
});
