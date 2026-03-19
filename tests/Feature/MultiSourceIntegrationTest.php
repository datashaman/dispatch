<?php

use App\Models\Project;
use App\Models\WebhookLog;
use App\Services\EventSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/'.uniqid('multi-source-test-');
    File::makeDirectory($this->tempDir, 0755, true);

    file_put_contents($this->tempDir.'/dispatch.yml', Yaml::dump([
        'version' => 1,
        'agent' => ['name' => 'test', 'executor' => 'laravel-ai'],
        'rules' => [],
    ]));

    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir, 'source' => 'github']);
});

afterEach(function () {
    File::deleteDirectory($this->tempDir);
});

it('routes github webhooks through the registry', function () {
    config(['services.github.verify_webhook_signature' => false]);

    $response = $this->postJson('/api/webhook', [
        'action' => 'opened',
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 1, 'title' => 'Test'],
        'sender' => ['login' => 'testuser'],
    ], [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'issues.opened',
        ]);

    $this->assertDatabaseHas('webhook_logs', [
        'event_type' => 'issues.opened',
        'source' => 'github',
        'repo' => 'owner/repo',
    ]);
});

it('stores source column on webhook logs', function () {
    config(['services.github.verify_webhook_signature' => false]);

    $this->postJson('/api/webhook', [
        'action' => 'opened',
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 1, 'title' => 'Test'],
        'sender' => ['login' => 'testuser'],
    ], [
        'X-GitHub-Event' => 'issues',
    ]);

    $log = WebhookLog::first();
    expect($log->source)->toBe('github');
});

it('returns 400 for unknown event sources', function () {
    $response = $this->postJson('/api/webhook', [
        'action' => 'opened',
    ]);

    $response->assertStatus(400);
});

it('registry has both github and gitlab registered', function () {
    $registry = app(EventSourceRegistry::class);

    expect($registry->sources())->toContain('github')
        ->and($registry->sources())->toContain('gitlab');
});

it('preserves existing webhook endpoint behavior with signature verification', function () {
    $secret = 'test-webhook-secret';
    config([
        'services.github.verify_webhook_signature' => true,
        'services.github.webhook_secret' => $secret,
    ]);

    $payload = json_encode([
        'action' => 'labeled',
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 1, 'title' => 'Test'],
        'label' => ['name' => 'bug'],
        'sender' => ['login' => 'testuser'],
    ]);

    $signature = 'sha256='.hash_hmac('sha256', $payload, $secret);

    $response = $this->call('POST', '/api/webhook', [], [], [], [
        'HTTP_X_GITHUB_EVENT' => 'issues',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk()
        ->assertJson(['ok' => true, 'event' => 'issues.labeled']);
});

it('preserves self-loop detection', function () {
    config([
        'services.github.verify_webhook_signature' => false,
        'services.github.bot_username' => 'dispatch-bot[bot]',
    ]);

    $response = $this->postJson('/api/webhook', [
        'action' => 'created',
        'repository' => ['full_name' => 'owner/repo'],
        'sender' => ['login' => 'dispatch-bot[bot]'],
    ], [
        'X-GitHub-Event' => 'issue_comment',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'skipped' => 'self-loop',
        ]);
});

it('preserves ping event handling', function () {
    config(['services.github.verify_webhook_signature' => false]);

    $response = $this->postJson('/api/webhook', ['zen' => 'Keep it simple.'], [
        'X-GitHub-Event' => 'ping',
    ]);

    $response->assertOk()
        ->assertJson(['ok' => true, 'event' => 'ping']);
});
