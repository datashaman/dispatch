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

test('commitFile creates new file when it does not exist', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/contents/dispatch.yml*' => Http::sequence()
            ->push(['message' => 'Not Found'], 404)
            ->push(['commit' => ['sha' => 'abc123'], 'content' => []], 201),
    ]);

    $client = app(GitHubApiClient::class);
    $result = $client->commitFile('owner/repo', 'dispatch.yml', 'version: 1', 'chore: update config', 12345, 'my-branch');

    expect($result['success'])->toBeTrue();
    expect($result['commit_sha'])->toBe('abc123');

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && str_contains($request->url(), '/repos/owner/repo/contents/dispatch.yml')
            && $request['message'] === 'chore: update config'
            && $request['branch'] === 'my-branch'
            && ! isset($request['sha']);
    });
});

test('commitFile updates existing file with sha', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/contents/dispatch.yml*' => Http::sequence()
            ->push(['sha' => 'existing-sha-123', 'content' => ''], 200)
            ->push(['commit' => ['sha' => 'new-commit-sha'], 'content' => []], 200),
    ]);

    $client = app(GitHubApiClient::class);
    $result = $client->commitFile('owner/repo', 'dispatch.yml', 'version: 2', 'chore: update', 12345);

    expect($result['success'])->toBeTrue();
    expect($result['commit_sha'])->toBe('new-commit-sha');

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && $request['sha'] === 'existing-sha-123';
    });
});

test('commitFile returns error on PUT failure', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/contents/dispatch.yml*' => Http::sequence()
            ->push(['message' => 'Not Found'], 404)
            ->push(['message' => 'Conflict'], 409),
    ]);

    $client = app(GitHubApiClient::class);
    $result = $client->commitFile('owner/repo', 'dispatch.yml', 'content', 'msg', 12345);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('409');
    expect($result['commit_sha'])->toBeNull();
});

test('commitFile returns error when GET fails with non-404', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/contents/dispatch.yml*' => Http::response(['message' => 'Rate limited'], 429),
    ]);

    $client = app(GitHubApiClient::class);
    $result = $client->commitFile('owner/repo', 'dispatch.yml', 'content', 'msg', 12345);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('429');
    expect($result['commit_sha'])->toBeNull();
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
