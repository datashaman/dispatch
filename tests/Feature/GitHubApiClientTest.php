<?php

use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Services\GitHubApiClient;
use App\Services\GitHubAppService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->installation = GitHubInstallation::factory()->create([
        'installation_id' => 12345,
        'account_login' => 'owner',
    ]);

    $mockAppService = Mockery::mock(GitHubAppService::class);
    $mockAppService->shouldReceive('getInstallationToken')->andReturn('fake-token');
    app()->instance(GitHubAppService::class, $mockAppService);
});

test('postComment sends POST request to correct endpoint', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/issues/42/comments' => Http::response(['id' => 1], 201),
    ]);

    $client = app(GitHubApiClient::class);
    $result = $client->postComment('owner/repo', 'issues', 42, 'Hello world', 12345);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/repos/owner/repo/issues/42/comments')
            && $request->method() === 'POST'
            && $request['body'] === 'Hello world'
            && str_contains($request->header('Authorization')[0] ?? '', 'Bearer fake-token');
    });
});

test('postComment returns false on failure', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/issues/42/comments' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $client = app(GitHubApiClient::class);
    $result = $client->postComment('owner/repo', 'issues', 42, 'Hello world', 12345);

    expect($result)->toBeFalse();
});

test('addCommentReaction sends reaction to comment endpoint', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/issues/comments/999/reactions' => Http::response(['id' => 1], 201),
    ]);

    $client = app(GitHubApiClient::class);
    $result = $client->addCommentReaction('owner/repo', 'issues/comments', 999, 'eyes', 12345);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/repos/owner/repo/issues/comments/999/reactions')
            && $request['content'] === 'eyes';
    });
});

test('addIssueReaction sends reaction to issue endpoint', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/issues/42/reactions' => Http::response(['id' => 1], 201),
    ]);

    $client = app(GitHubApiClient::class);
    $result = $client->addIssueReaction('owner/repo', 'issues', 42, 'rocket', 12345);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/repos/owner/repo/issues/42/reactions')
            && $request['content'] === 'rocket';
    });
});

test('resolveInstallationId returns installation_id from project', function () {
    Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test',
        'github_installation_id' => $this->installation->id,
    ]);

    $client = app(GitHubApiClient::class);
    $result = $client->resolveInstallationId('owner/repo');

    expect($result)->toBe(12345);
});

test('resolveInstallationId returns null when project has no installation', function () {
    Project::factory()->create([
        'repo' => 'other/repo',
        'path' => '/tmp/other',
        'github_installation_id' => null,
    ]);

    $client = app(GitHubApiClient::class);
    $result = $client->resolveInstallationId('other/repo');

    expect($result)->toBeNull();
});

test('resolveInstallationId returns null when project not found', function () {
    $client = app(GitHubApiClient::class);
    $result = $client->resolveInstallationId('nonexistent/repo');

    expect($result)->toBeNull();
});

test('requests include correct GitHub API headers', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/issues/1/comments' => Http::response(['id' => 1], 201),
    ]);

    $client = app(GitHubApiClient::class);
    $client->postComment('owner/repo', 'issues', 1, 'test', 12345);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/issues/1/comments')) {
            return false;
        }

        return str_contains($request->header('Accept')[0] ?? '', 'application/vnd.github+json')
            && ($request->header('X-GitHub-Api-Version')[0] ?? '') === '2022-11-28';
    });
});
