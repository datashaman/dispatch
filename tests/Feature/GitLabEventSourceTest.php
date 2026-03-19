<?php

use App\EventSources\GitLab\GitLabEventSource;
use App\EventSources\GitLab\GitLabThreadKeyDeriver;
use Illuminate\Http\Request;

it('validates requests with X-Gitlab-Event header', function () {
    $source = new GitLabEventSource;

    $validRequest = Request::create('/webhook', 'POST');
    $validRequest->headers->set('X-Gitlab-Event', 'Issue Hook');

    $invalidRequest = Request::create('/webhook', 'POST');

    expect($source->validates($validRequest))->toBeTrue()
        ->and($source->validates($invalidRequest))->toBeFalse();
});

it('extracts event type from issue hook', function () {
    $source = new GitLabEventSource;

    $request = Request::create('/webhook', 'POST', [
        'object_attributes' => ['action' => 'open'],
    ]);
    $request->headers->set('X-Gitlab-Event', 'Issue Hook');

    expect($source->eventType($request))->toBe('issues.open');
});

it('extracts event type from merge request hook', function () {
    $source = new GitLabEventSource;

    $request = Request::create('/webhook', 'POST', [
        'object_attributes' => ['action' => 'open'],
    ]);
    $request->headers->set('X-Gitlab-Event', 'Merge Request Hook');

    expect($source->eventType($request))->toBe('merge_request.open');
});

it('extracts event type from push hook without action', function () {
    $source = new GitLabEventSource;

    $request = Request::create('/webhook', 'POST');
    $request->headers->set('X-Gitlab-Event', 'Push Hook');

    expect($source->eventType($request))->toBe('push');
});

it('returns source name as gitlab', function () {
    $source = new GitLabEventSource;

    expect($source->name())->toBe('gitlab');
});

it('verifies webhook token', function () {
    $source = new GitLabEventSource;

    config(['services.gitlab.webhook_secret' => 'my-secret-token']);

    $validRequest = Request::create('/webhook', 'POST');
    $validRequest->headers->set('X-Gitlab-Token', 'my-secret-token');

    $invalidRequest = Request::create('/webhook', 'POST');
    $invalidRequest->headers->set('X-Gitlab-Token', 'wrong-token');

    expect($source->verifyToken($validRequest))->toBeTrue()
        ->and($source->verifyToken($invalidRequest))->toBeFalse();
});

it('rejects missing token when secret is configured', function () {
    $source = new GitLabEventSource;

    config(['services.gitlab.webhook_secret' => 'my-secret-token']);

    $request = Request::create('/webhook', 'POST');
    $request->headers->set('X-Gitlab-Event', 'Issue Hook');

    expect($source->verifyToken($request))->toBeFalse()
        ->and($source->verifyWebhook($request))->toBeFalse();
});

it('verifyWebhook delegates to verifyToken', function () {
    $source = new GitLabEventSource;

    config(['services.gitlab.webhook_secret' => 'my-secret-token']);

    $validRequest = Request::create('/webhook', 'POST');
    $validRequest->headers->set('X-Gitlab-Event', 'Issue Hook');
    $validRequest->headers->set('X-Gitlab-Token', 'my-secret-token');

    $invalidRequest = Request::create('/webhook', 'POST');
    $invalidRequest->headers->set('X-Gitlab-Event', 'Issue Hook');
    $invalidRequest->headers->set('X-Gitlab-Token', 'wrong');

    expect($source->verifyWebhook($validRequest))->toBeTrue()
        ->and($source->verifyWebhook($invalidRequest))->toBeFalse();
});

it('returns appropriate verification error messages', function () {
    $source = new GitLabEventSource;

    $noTokenRequest = Request::create('/webhook', 'POST');
    $noTokenRequest->headers->set('X-Gitlab-Event', 'Issue Hook');

    $badTokenRequest = Request::create('/webhook', 'POST');
    $badTokenRequest->headers->set('X-Gitlab-Event', 'Issue Hook');
    $badTokenRequest->headers->set('X-Gitlab-Token', 'wrong');

    expect($source->verificationError($noTokenRequest))->toBe('Missing X-Gitlab-Token header')
        ->and($source->verificationError($badTokenRequest))->toBe('Invalid webhook token');
});

it('passes verification when no secret configured', function () {
    $source = new GitLabEventSource;

    config(['services.gitlab.webhook_secret' => null]);

    $request = Request::create('/webhook', 'POST');

    expect($source->verifyToken($request))->toBeTrue();
});

it('normalizes issue payload', function () {
    $source = new GitLabEventSource;

    $request = Request::create('/webhook', 'POST', [
        'object_attributes' => [
            'iid' => 42,
            'title' => 'Test Issue',
            'description' => 'Issue body',
            'state' => 'opened',
            'url' => 'https://gitlab.com/owner/repo/-/issues/42',
            'action' => 'open',
        ],
        'project' => [
            'path_with_namespace' => 'owner/repo',
            'web_url' => 'https://gitlab.com/owner/repo',
        ],
        'user' => [
            'username' => 'testuser',
        ],
    ]);
    $request->headers->set('X-Gitlab-Event', 'Issue Hook');

    $normalized = $source->normalizePayload($request);

    expect($normalized['repository']['full_name'])->toBe('owner/repo')
        ->and($normalized['issue']['number'])->toBe(42)
        ->and($normalized['issue']['title'])->toBe('Test Issue')
        ->and($normalized['issue']['body'])->toBe('Issue body')
        ->and($normalized['issue']['user']['login'])->toBe('testuser')
        ->and($normalized['action'])->toBe('open')
        ->and($normalized['sender']['login'])->toBe('testuser');
});

it('normalizes merge request payload', function () {
    $source = new GitLabEventSource;

    $request = Request::create('/webhook', 'POST', [
        'object_attributes' => [
            'iid' => 99,
            'title' => 'Test MR',
            'description' => 'MR body',
            'state' => 'opened',
            'url' => 'https://gitlab.com/owner/repo/-/merge_requests/99',
            'source_branch' => 'feature',
            'target_branch' => 'main',
            'action' => 'open',
            'last_commit' => ['id' => 'abc123'],
        ],
        'project' => [
            'path_with_namespace' => 'owner/repo',
            'web_url' => 'https://gitlab.com/owner/repo',
        ],
        'user' => [
            'username' => 'testuser',
        ],
    ]);
    $request->headers->set('X-Gitlab-Event', 'Merge Request Hook');

    $normalized = $source->normalizePayload($request);

    expect($normalized['repository']['full_name'])->toBe('owner/repo')
        ->and($normalized['pull_request']['number'])->toBe(99)
        ->and($normalized['pull_request']['title'])->toBe('Test MR')
        ->and($normalized['pull_request']['head']['ref'])->toBe('feature')
        ->and($normalized['pull_request']['base']['ref'])->toBe('main')
        ->and($normalized['pull_request']['user']['login'])->toBe('testuser');
});

it('normalizes note payload on issue', function () {
    $source = new GitLabEventSource;

    $request = Request::create('/webhook', 'POST', [
        'object_attributes' => [
            'id' => 1,
            'note' => 'A comment',
            'url' => 'https://gitlab.com/owner/repo/-/issues/42#note_1',
            'noteable_type' => 'Issue',
        ],
        'issue' => [
            'iid' => 42,
            'title' => 'Test Issue',
            'description' => 'Issue body',
        ],
        'project' => [
            'path_with_namespace' => 'owner/repo',
            'web_url' => 'https://gitlab.com/owner/repo',
        ],
        'user' => [
            'username' => 'testuser',
        ],
    ]);
    $request->headers->set('X-Gitlab-Event', 'Note Hook');

    $normalized = $source->normalizePayload($request);

    expect($normalized['repository']['full_name'])->toBe('owner/repo')
        ->and($normalized['comment']['body'])->toBe('A comment')
        ->and($normalized['comment']['user']['login'])->toBe('testuser')
        ->and($normalized['issue']['number'])->toBe(42)
        ->and($normalized['action'])->toBe('created');
});

it('normalizes push payload', function () {
    $source = new GitLabEventSource;

    $request = Request::create('/webhook', 'POST', [
        'ref' => 'refs/heads/main',
        'before' => '000000',
        'after' => 'abc123',
        'user_username' => 'testuser',
        'user_name' => 'Test User',
        'project' => [
            'path_with_namespace' => 'owner/repo',
            'web_url' => 'https://gitlab.com/owner/repo',
        ],
        'commits' => [
            ['id' => 'abc123', 'message' => 'Fix bug', 'author' => ['name' => 'Test User']],
        ],
    ]);
    $request->headers->set('X-Gitlab-Event', 'Push Hook');

    $normalized = $source->normalizePayload($request);

    expect($normalized['repository']['full_name'])->toBe('owner/repo')
        ->and($normalized['ref'])->toBe('refs/heads/main')
        ->and($normalized['head_commit']['message'])->toBe('Fix bug')
        ->and($normalized['pusher']['name'])->toBe('Test User')
        ->and($normalized['sender']['login'])->toBe('testuser');
});

it('derives thread key from gitlab issue payload', function () {
    $deriver = new GitLabThreadKeyDeriver;

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 42],
    ];

    expect($deriver->deriveKey('issues.open', $payload))->toBe('owner/repo:issue:42');
});

it('derives thread key from gitlab merge request payload', function () {
    $deriver = new GitLabThreadKeyDeriver;

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'pull_request' => ['number' => 99],
    ];

    expect($deriver->deriveKey('merge_request.open', $payload))->toBe('owner/repo:pr:99');
});

it('derives thread key from raw gitlab object_attributes', function () {
    $deriver = new GitLabThreadKeyDeriver;

    $payload = [
        'project' => ['path_with_namespace' => 'owner/repo'],
        'object_attributes' => [
            'iid' => 42,
            'source_branch' => 'feature',
        ],
    ];

    expect($deriver->deriveKey('merge_request.open', $payload))->toBe('owner/repo:pr:42');
});

it('returns null thread key for push events', function () {
    $deriver = new GitLabThreadKeyDeriver;

    $payload = [
        'project' => ['path_with_namespace' => 'owner/repo'],
    ];

    expect($deriver->deriveKey('push', $payload))->toBeNull();
});
