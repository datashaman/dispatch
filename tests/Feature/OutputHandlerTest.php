<?php

use App\DataTransferObjects\OutputConfig;
use App\Models\AgentRun;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\WebhookLog;
use App\Services\GitHubAppService;
use App\Services\OutputHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->installation = GitHubInstallation::factory()->create([
        'installation_id' => 12345,
        'account_login' => 'owner',
    ]);
    $this->project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test',
        'github_installation_id' => $this->installation->id,
    ]);
    $this->webhookLog = WebhookLog::factory()->create(['repo' => 'owner/repo']);
    $this->agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'success',
        'output' => 'Agent analysis complete. The code looks good.',
    ]);

    $this->basePayload = [
        'repository' => ['full_name' => 'owner/repo'],
        'installation' => ['id' => 12345],
    ];

    $mockAppService = Mockery::mock(GitHubAppService::class);
    $mockAppService->shouldReceive('getInstallationToken')->andReturn('fake-token');
    app()->instance(GitHubAppService::class, $mockAppService);
});

test('log output is always saved to agent_runs.output by default', function () {
    $outputConfig = new OutputConfig(log: true, githubComment: false);

    Http::fake();

    app(OutputHandler::class)->handle($this->agentRun, $outputConfig, []);

    Http::assertNothingSent();
    expect($this->agentRun->output)->toBe('Agent analysis complete. The code looks good.');
});

test('github_comment posts comment on issue via GitHub API', function () {
    Http::fake([

        'api.github.com/repos/owner/repo/issues/42/comments' => Http::response(['id' => 1], 201),
    ]);

    $outputConfig = new OutputConfig(githubComment: true);
    $payload = array_merge($this->basePayload, ['issue' => ['number' => 42]]);

    app(OutputHandler::class)->handle($this->agentRun, $outputConfig, $payload);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/repos/owner/repo/issues/42/comments')
            && $request->method() === 'POST'
            && $request['body'] === 'Agent analysis complete. The code looks good.';
    });
});

test('github_comment posts comment on pull request via GitHub API', function () {
    Http::fake([

        'api.github.com/repos/owner/repo/issues/99/comments' => Http::response(['id' => 1], 201),
    ]);

    $outputConfig = new OutputConfig(githubComment: true);
    $payload = array_merge($this->basePayload, ['pull_request' => ['number' => 99]]);

    app(OutputHandler::class)->handle($this->agentRun, $outputConfig, $payload);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/repos/owner/repo/issues/99/comments'));
});

test('github_comment posts comment on discussion via GitHub API', function () {
    Http::fake([

        'api.github.com/repos/owner/repo/discussions/7/comments' => Http::response(['id' => 1], 201),
    ]);

    $outputConfig = new OutputConfig(githubComment: true);
    $payload = array_merge($this->basePayload, ['discussion' => ['number' => 7]]);

    app(OutputHandler::class)->handle($this->agentRun, $outputConfig, $payload);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/repos/owner/repo/discussions/7/comments'));
});

test('addReaction adds reaction to comment via GitHub API', function () {
    Http::fake([

        'api.github.com/repos/owner/repo/issues/comments/12345/reactions' => Http::response(['id' => 1], 201),
    ]);

    $payload = array_merge($this->basePayload, [
        'issue' => ['number' => 42],
        'comment' => ['id' => 12345],
    ]);

    app(OutputHandler::class)->addReaction('eyes', $payload);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/repos/owner/repo/issues/comments/12345/reactions')
            && $request['content'] === 'eyes';
    });
});

test('addReaction uses pulls/comments for PR review comments', function () {
    Http::fake([

        'api.github.com/repos/owner/repo/pulls/comments/67890/reactions' => Http::response(['id' => 1], 201),
    ]);

    $payload = array_merge($this->basePayload, [
        'pull_request' => ['number' => 99],
        'comment' => ['id' => 67890],
    ]);

    app(OutputHandler::class)->addReaction('rocket', $payload);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/repos/owner/repo/pulls/comments/67890/reactions'));
});

test('addReaction uses discussions/comments for discussion comments', function () {
    Http::fake([

        'api.github.com/repos/owner/repo/discussions/comments/11111/reactions' => Http::response(['id' => 1], 201),
    ]);

    $payload = array_merge($this->basePayload, [
        'discussion' => ['number' => 7],
        'comment' => ['id' => 11111],
    ]);

    app(OutputHandler::class)->addReaction('heart', $payload);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/repos/owner/repo/discussions/comments/11111/reactions'));
});

test('no output config with github_comment disabled does nothing', function () {
    Http::fake();

    $outputConfig = new OutputConfig(log: true, githubComment: false);
    $payload = array_merge($this->basePayload, ['issue' => ['number' => 42]]);

    app(OutputHandler::class)->handle($this->agentRun, $outputConfig, $payload);

    Http::assertNothingSent();
});

test('github_comment without repo logs warning', function () {
    $outputConfig = new OutputConfig(githubComment: true);

    Http::fake();
    Log::shouldReceive('warning')->once()->with('Could not determine GitHub resource for comment', Mockery::any());

    app(OutputHandler::class)->handle($this->agentRun, $outputConfig, []);

    Http::assertNothingSent();
});

test('addReaction falls back to issue reaction when no comment', function () {
    Http::fake([

        'api.github.com/repos/owner/repo/issues/42/reactions' => Http::response(['id' => 1], 201),
    ]);

    $payload = array_merge($this->basePayload, ['issue' => ['number' => 42]]);

    app(OutputHandler::class)->addReaction('eyes', $payload);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/repos/owner/repo/issues/42/reactions'));
});

test('both github_comment and github_reaction can be configured together', function () {
    Http::fake([

        'api.github.com/*' => Http::response(['id' => 1], 201),
    ]);

    $outputConfig = new OutputConfig(githubComment: true, githubReaction: 'eyes');
    $payload = array_merge($this->basePayload, [
        'issue' => ['number' => 42],
        'comment' => ['id' => 12345],
    ]);

    app(OutputHandler::class)->handle($this->agentRun, $outputConfig, $payload);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/comments'));
});

test('resolveGitHubResource returns correct type for each resource', function () {
    $handler = app(OutputHandler::class);

    // Issue
    $result = $handler->resolveGitHubResource(['issue' => ['number' => 1]]);
    expect($result)->toBe(['type' => 'issues', 'number' => 1]);

    // Pull request
    $result = $handler->resolveGitHubResource(['pull_request' => ['number' => 2]]);
    expect($result)->toBe(['type' => 'issues', 'number' => 2]);

    // Discussion
    $result = $handler->resolveGitHubResource(['discussion' => ['number' => 3]]);
    expect($result)->toBe(['type' => 'discussions', 'number' => 3]);

    // Unknown
    $result = $handler->resolveGitHubResource(['push' => ['ref' => 'main']]);
    expect($result)->toBeNull();
});

test('github_comment logs error when no installation found', function () {
    Http::fake();

    // Create a project without a GitHub installation
    $project = Project::factory()->create([
        'repo' => 'other/repo',
        'path' => '/tmp/other',
        'github_installation_id' => null,
    ]);

    $outputConfig = new OutputConfig(githubComment: true);
    $payload = [
        'repository' => ['full_name' => 'other/repo'],
        'issue' => ['number' => 1],
    ];

    Log::shouldReceive('error')->once()->with('No GitHub installation found for repo', Mockery::any());

    app(OutputHandler::class)->handle($this->agentRun, $outputConfig, $payload);

    Http::assertNothingSent();
});

test('installation ID from payload takes precedence over project lookup', function () {
    $mockAppService = Mockery::mock(GitHubAppService::class);
    $mockAppService->shouldReceive('getInstallationToken')
        ->with(99999)
        ->once()
        ->andReturn('fake-token');
    app()->instance(GitHubAppService::class, $mockAppService);

    Http::fake([
        'api.github.com/repos/owner/repo/issues/42/comments' => Http::response(['id' => 1], 201),
    ]);

    $outputConfig = new OutputConfig(githubComment: true);
    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'installation' => ['id' => 99999], // Different from project's installation
        'issue' => ['number' => 42],
    ];

    app(OutputHandler::class)->handle($this->agentRun, $outputConfig, $payload);

    // The mock asserts getInstallationToken was called with 99999, not 12345
    Http::assertSent(fn ($request) => str_contains($request->url(), '/repos/owner/repo/issues/42/comments'));
});
