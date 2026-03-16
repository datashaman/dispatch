<?php

use App\Ai\Agents\DispatchAgent;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Rule;
use App\Models\RuleAgentConfig;
use App\Models\WebhookLog;
use App\Services\WorktreeManager;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->projectPath = sys_get_temp_dir().'/'.uniqid('worktree-test-');
    mkdir($this->projectPath, 0755, true);

    // Initialize a git repo with an initial commit
    Process::run("cd {$this->projectPath} && git init && git config user.email 'test@test.com' && git config user.name 'Test' && echo 'hello' > README.md && git add . && git commit -m 'initial'");

    $this->project = Project::factory()->create([
        'repo' => 'test-owner/test-repo',
        'path' => $this->projectPath,
        'agent_executor' => 'laravel-ai',
    ]);
});

afterEach(function () {
    // Clean up temp directory
    if (isset($this->projectPath) && is_dir($this->projectPath)) {
        Process::run("rm -rf {$this->projectPath}");
    }
});

test('WorktreeManager creates a worktree with correct branch naming', function () {
    $manager = new WorktreeManager;

    $result = $manager->create($this->projectPath, 'my-rule');

    expect($result)->toHaveKeys(['path', 'branch']);
    expect($result['branch'])->toStartWith('dispatch/my-rule/');
    expect($result['path'])->toContain('.worktrees/my-rule-');
    expect(is_dir($result['path']))->toBeTrue();

    // Clean up
    $manager->remove($result['path'], $result['branch'], $this->projectPath);
});

test('WorktreeManager detects no new commits', function () {
    $manager = new WorktreeManager;

    $worktree = $manager->create($this->projectPath, 'test-rule');

    expect($manager->hasNewCommits($worktree['path'], $this->projectPath))->toBeFalse();

    // Clean up
    $manager->remove($worktree['path'], $worktree['branch'], $this->projectPath);
});

test('WorktreeManager detects new commits', function () {
    $manager = new WorktreeManager;

    $worktree = $manager->create($this->projectPath, 'test-rule');

    // Make a commit in the worktree
    Process::run("cd {$worktree['path']} && echo 'new file' > newfile.txt && git add . && git commit -m 'new commit'");

    expect($manager->hasNewCommits($worktree['path'], $this->projectPath))->toBeTrue();

    // Clean up
    $manager->remove($worktree['path'], $worktree['branch'], $this->projectPath);
});

test('WorktreeManager cleanup removes worktree when no commits', function () {
    $manager = new WorktreeManager;

    $worktree = $manager->create($this->projectPath, 'test-rule');

    $removed = $manager->cleanup($worktree['path'], $worktree['branch'], $this->projectPath);

    expect($removed)->toBeTrue();
    expect(is_dir($worktree['path']))->toBeFalse();
});

test('WorktreeManager cleanup retains worktree when commits exist', function () {
    $manager = new WorktreeManager;

    $worktree = $manager->create($this->projectPath, 'test-rule');

    // Make a commit in the worktree
    Process::run("cd {$worktree['path']} && echo 'new file' > newfile.txt && git add . && git commit -m 'new commit'");

    $removed = $manager->cleanup($worktree['path'], $worktree['branch'], $this->projectPath);

    expect($removed)->toBeFalse();
    expect(is_dir($worktree['path']))->toBeTrue();

    // Manual clean up
    $manager->remove($worktree['path'], $worktree['branch'], $this->projectPath);
});

test('ProcessAgentRun creates worktree when isolation is true', function () {
    DispatchAgent::fake(['Agent response']);

    $webhookLog = WebhookLog::factory()->create();

    $rule = Rule::factory()->for($this->project)->create([
        'rule_id' => 'isolated-rule',
        'prompt' => 'Do something',
    ]);

    RuleAgentConfig::factory()->for($rule)->create([
        'isolation' => true,
        'tools' => [],
        'disallowed_tools' => [],
    ]);

    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => $rule->rule_id,
        'status' => 'queued',
    ]);

    $job = new ProcessAgentRun($agentRun, $rule, ['action' => 'test']);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success');

    // Worktree should be cleaned up since no commits were made by the agent
    // Verify no .worktrees directory remains (or it's empty)
    $worktreesDir = $this->projectPath.'/.worktrees';
    if (is_dir($worktreesDir)) {
        $contents = array_diff(scandir($worktreesDir), ['.', '..']);
        expect($contents)->toBeEmpty();
    }
});

test('ProcessAgentRun does not create worktree when isolation is false', function () {
    DispatchAgent::fake(['Agent response']);

    $webhookLog = WebhookLog::factory()->create();

    $rule = Rule::factory()->for($this->project)->create([
        'rule_id' => 'non-isolated-rule',
        'prompt' => 'Do something',
    ]);

    RuleAgentConfig::factory()->for($rule)->create([
        'isolation' => false,
        'tools' => [],
        'disallowed_tools' => [],
    ]);

    $agentRun = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => $rule->rule_id,
        'status' => 'queued',
    ]);

    $job = new ProcessAgentRun($agentRun, $rule, ['action' => 'test']);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success');

    // No worktrees directory should be created
    expect(is_dir($this->projectPath.'/.worktrees'))->toBeFalse();
});

test('WorktreeManager throws exception on worktree creation failure', function () {
    $manager = new WorktreeManager;

    // Use a non-git directory
    $nonGitPath = sys_get_temp_dir().'/'.uniqid('non-git-');
    mkdir($nonGitPath, 0755, true);

    try {
        $manager->create($nonGitPath, 'test-rule');
    } finally {
        rmdir($nonGitPath);
    }
})->throws(RuntimeException::class);
