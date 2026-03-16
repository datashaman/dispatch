<?php

use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function selfLoopPayload(string $senderLogin = 'dispatch-bot', array $overrides = []): array
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
        'sender' => [
            'login' => $senderLogin,
        ],
    ], $overrides);
}

it('skips processing when sender matches GITHUB_BOT_USERNAME', function (): void {
    config([
        'services.github.verify_webhook_signature' => false,
        'services.github.bot_username' => 'dispatch-bot',
    ]);

    $response = $this->postJson('/api/webhook', selfLoopPayload('dispatch-bot'), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'issues.labeled',
            'skipped' => 'self-loop',
        ]);
});

it('logs self-loop events with a note', function (): void {
    config([
        'services.github.verify_webhook_signature' => false,
        'services.github.bot_username' => 'dispatch-bot',
    ]);

    $this->postJson('/api/webhook', selfLoopPayload('dispatch-bot'), [
        'X-GitHub-Event' => 'issues',
    ]);

    $this->assertDatabaseCount('webhook_logs', 1);

    $log = WebhookLog::first();
    expect($log->event_type)->toBe('issues.labeled')
        ->and($log->status)->toBe('received')
        ->and($log->error)->toBe('Self-loop detected');
});

it('processes events normally when sender does not match bot username', function (): void {
    config([
        'services.github.verify_webhook_signature' => false,
        'services.github.bot_username' => 'dispatch-bot',
    ]);

    $response = $this->postJson('/api/webhook', selfLoopPayload('human-user'), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'issues.labeled',
        ]);

    $response->assertJsonMissing(['skipped' => 'self-loop']);
});

it('processes events normally when GITHUB_BOT_USERNAME is not configured', function (): void {
    config([
        'services.github.verify_webhook_signature' => false,
        'services.github.bot_username' => null,
    ]);

    $response = $this->postJson('/api/webhook', selfLoopPayload('dispatch-bot'), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'issues.labeled',
        ]);

    $response->assertJsonMissing(['skipped' => 'self-loop']);
});

it('handles self-loop detection for events without action', function (): void {
    config([
        'services.github.verify_webhook_signature' => false,
        'services.github.bot_username' => 'dispatch-bot',
    ]);

    $payload = selfLoopPayload('dispatch-bot');
    unset($payload['action']);

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'push',
            'skipped' => 'self-loop',
        ]);
});
