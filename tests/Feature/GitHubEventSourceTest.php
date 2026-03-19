<?php

use App\EventSources\GitHub\GitHubEventSource;
use App\EventSources\GitHub\GitHubThreadKeyDeriver;
use Illuminate\Http\Request;

it('validates requests with X-GitHub-Event header', function () {
    $source = new GitHubEventSource;

    $validRequest = Request::create('/webhook', 'POST');
    $validRequest->headers->set('X-GitHub-Event', 'issues');

    $invalidRequest = Request::create('/webhook', 'POST');

    expect($source->validates($validRequest))->toBeTrue()
        ->and($source->validates($invalidRequest))->toBeFalse();
});

it('extracts event type with action', function () {
    $source = new GitHubEventSource;

    $request = Request::create('/webhook', 'POST', ['action' => 'opened']);
    $request->headers->set('X-GitHub-Event', 'issues');

    expect($source->eventType($request))->toBe('issues.opened');
});

it('extracts event type without action', function () {
    $source = new GitHubEventSource;

    $request = Request::create('/webhook', 'POST');
    $request->headers->set('X-GitHub-Event', 'push');

    expect($source->eventType($request))->toBe('push');
});

it('returns source name as github', function () {
    $source = new GitHubEventSource;

    expect($source->name())->toBe('github');
});

it('verifies webhook signature', function () {
    $source = new GitHubEventSource;

    config([
        'services.github.verify_webhook_signature' => true,
        'services.github.webhook_secret' => 'test-secret',
    ]);

    $payload = json_encode(['action' => 'opened']);
    $signature = 'sha256='.hash_hmac('sha256', $payload, 'test-secret');

    $request = Request::create('/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
    $request->headers->set('X-GitHub-Event', 'issues');
    $request->headers->set('X-Hub-Signature-256', $signature);

    expect($source->verifySignature($request))->toBeTrue();
});

it('rejects invalid webhook signature', function () {
    $source = new GitHubEventSource;

    config([
        'services.github.verify_webhook_signature' => true,
        'services.github.webhook_secret' => 'correct-secret',
    ]);

    $payload = json_encode(['action' => 'opened']);
    $signature = 'sha256='.hash_hmac('sha256', $payload, 'wrong-secret');

    $request = Request::create('/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
    $request->headers->set('X-GitHub-Event', 'issues');
    $request->headers->set('X-Hub-Signature-256', $signature);

    expect($source->verifySignature($request))->toBeFalse();
});

it('skips signature verification when disabled', function () {
    $source = new GitHubEventSource;

    config(['services.github.verify_webhook_signature' => false]);

    $request = Request::create('/webhook', 'POST');
    $request->headers->set('X-GitHub-Event', 'issues');

    expect($source->verifySignature($request))->toBeTrue();
});

it('detects self-loop from bot username', function () {
    $source = new GitHubEventSource;

    config(['services.github.bot_username' => 'dispatch-bot[bot]']);

    $request = Request::create('/webhook', 'POST', [
        'sender' => ['login' => 'dispatch-bot[bot]'],
    ]);

    expect($source->isSelfLoop($request))->toBeTrue();
});

it('does not detect self-loop for other users', function () {
    $source = new GitHubEventSource;

    config(['services.github.bot_username' => 'dispatch-bot[bot]']);

    $request = Request::create('/webhook', 'POST', [
        'sender' => ['login' => 'human-user'],
    ]);

    expect($source->isSelfLoop($request))->toBeFalse();
});

it('detects ping events', function () {
    $source = new GitHubEventSource;

    $pingRequest = Request::create('/webhook', 'POST');
    $pingRequest->headers->set('X-GitHub-Event', 'ping');

    $otherRequest = Request::create('/webhook', 'POST');
    $otherRequest->headers->set('X-GitHub-Event', 'issues');

    expect($source->isPing($pingRequest))->toBeTrue()
        ->and($source->isPing($otherRequest))->toBeFalse();
});

it('normalizes payload as-is for github', function () {
    $source = new GitHubEventSource;

    $request = Request::create('/webhook', 'POST', [
        'action' => 'opened',
        'issue' => ['number' => 1],
    ]);

    expect($source->normalizePayload($request))->toBe([
        'action' => 'opened',
        'issue' => ['number' => 1],
    ]);
});

it('derives thread key for issues', function () {
    $deriver = new GitHubThreadKeyDeriver;

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 42],
    ];

    expect($deriver->deriveKey('issues.opened', $payload))->toBe('owner/repo:issue:42');
});

it('derives thread key for pull requests', function () {
    $deriver = new GitHubThreadKeyDeriver;

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'pull_request' => ['number' => 99],
    ];

    expect($deriver->deriveKey('pull_request.opened', $payload))->toBe('owner/repo:pr:99');
});

it('derives thread key for discussions', function () {
    $deriver = new GitHubThreadKeyDeriver;

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'discussion' => ['number' => 7],
    ];

    expect($deriver->deriveKey('discussion.created', $payload))->toBe('owner/repo:discussion:7');
});

it('returns null thread key when no resource found', function () {
    $deriver = new GitHubThreadKeyDeriver;

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
    ];

    expect($deriver->deriveKey('push', $payload))->toBeNull();
});
