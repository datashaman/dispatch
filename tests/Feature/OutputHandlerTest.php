<?php

use App\DataTransferObjects\OutputConfig;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\WebhookLog;
use App\Services\OutputHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->project = Project::factory()->create(['repo' => 'owner/repo', 'path' => '/tmp/test']);
    $this->webhookLog = WebhookLog::factory()->create(['repo' => 'owner/repo']);
    $this->agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'success',
        'output' => 'Agent analysis complete. The code looks good.',
    ]);
    $this->handler = new OutputHandler;
});

test('log output is always saved to agent_runs.output by default', function () {
    $outputConfig = new OutputConfig(log: true, githubComment: false);

    Process::fake();

    $this->handler->handle($this->agentRun, $outputConfig, []);

    // Output is already stored in agent_runs.output by ProcessAgentRun — no process calls needed
    Process::assertNothingRan();
    expect($this->agentRun->output)->toBe('Agent analysis complete. The code looks good.');
});

test('github_comment posts comment on issue via gh CLI', function () {
    $outputConfig = new OutputConfig(githubComment: true);

    Process::fake();

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 42],
    ];

    $this->handler->handle($this->agentRun, $outputConfig, $payload);

    Process::assertRan(function ($process) {
        return $process->command[0] === 'gh'
            && $process->command[1] === 'api'
            && $process->command[3] === 'POST'
            && str_contains($process->command[4], '/repos/owner/repo/issues/42/comments')
            && in_array('--input', $process->command)
            && in_array('-', $process->command);
    });
});

test('github_comment posts comment on pull request via gh CLI', function () {
    $outputConfig = new OutputConfig(githubComment: true);

    Process::fake();

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'pull_request' => ['number' => 99],
    ];

    $this->handler->handle($this->agentRun, $outputConfig, $payload);

    Process::assertRan(function ($process) {
        return str_contains($process->command[4], '/repos/owner/repo/issues/99/comments');
    });
});

test('github_comment posts comment on discussion via gh CLI', function () {
    $outputConfig = new OutputConfig(githubComment: true);

    Process::fake();

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'discussion' => ['number' => 7],
    ];

    $this->handler->handle($this->agentRun, $outputConfig, $payload);

    Process::assertRan(function ($process) {
        return str_contains($process->command[4], '/repos/owner/repo/discussions/7/comments');
    });
});

test('addReaction adds reaction to comment via gh CLI', function () {
    Process::fake();

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 42],
        'comment' => ['id' => 12345],
    ];

    $this->handler->addReaction('eyes', $payload);

    Process::assertRan(function ($process) {
        return $process->command[0] === 'gh'
            && $process->command[1] === 'api'
            && $process->command[3] === 'POST'
            && str_contains($process->command[4], '/repos/owner/repo/issues/comments/12345/reactions')
            && str_contains($process->command[6], 'eyes');
    });
});

test('addReaction uses pulls/comments for PR review comments', function () {
    Process::fake();

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'pull_request' => ['number' => 99],
        'comment' => ['id' => 67890],
    ];

    $this->handler->addReaction('rocket', $payload);

    Process::assertRan(function ($process) {
        return str_contains($process->command[4], '/repos/owner/repo/pulls/comments/67890/reactions');
    });
});

test('addReaction uses discussions/comments for discussion comments', function () {
    Process::fake();

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'discussion' => ['number' => 7],
        'comment' => ['id' => 11111],
    ];

    $this->handler->addReaction('heart', $payload);

    Process::assertRan(function ($process) {
        return str_contains($process->command[4], '/repos/owner/repo/discussions/comments/11111/reactions');
    });
});

test('no output config with github_comment disabled does nothing', function () {
    Process::fake();

    $outputConfig = new OutputConfig(log: true, githubComment: false);

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 42],
    ];

    $this->handler->handle($this->agentRun, $outputConfig, $payload);

    Process::assertNothingRan();
});

test('github_comment without repo logs warning', function () {
    $outputConfig = new OutputConfig(githubComment: true);

    Process::fake();
    Log::shouldReceive('warning')->once()->with('Could not determine GitHub resource for comment', Mockery::any());

    $this->handler->handle($this->agentRun, $outputConfig, []);

    Process::assertNothingRan();
});

test('addReaction without comment logs warning', function () {
    Process::fake();
    Log::shouldReceive('warning')->once()->with('No comment ID found in payload for GitHub reaction');

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 42],
    ];

    $this->handler->addReaction('eyes', $payload);

    Process::assertNothingRan();
});

test('both github_comment and github_reaction can be configured together', function () {
    $outputConfig = new OutputConfig(githubComment: true, githubReaction: 'eyes');

    Process::fake();

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 42],
        'comment' => ['id' => 12345],
    ];

    $this->handler->handle($this->agentRun, $outputConfig, $payload);

    // Should run the comment process
    Process::assertRan(function ($process) {
        return str_contains($process->command[4] ?? '', '/comments');
    });
});

test('resolveGitHubResource returns correct type for each resource', function () {
    $handler = new OutputHandler;

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
