<?php

use App\Models\AgentRun;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\WorktreeManager;
use Illuminate\Support\Facades\Process;
use Livewire\Volt\Volt;

// --- WorktreeManager getDiff (uses real git repos like WorktreeIsolationTest) ---

beforeEach(function () {
    $this->diffProjectPath = sys_get_temp_dir().'/'.uniqid('diff-test-');
    mkdir($this->diffProjectPath, 0755, true);

    Process::run("cd {$this->diffProjectPath} && git init && git config user.email 'test@test.com' && git config user.name 'Test' && echo 'hello' > README.md && git add . && git commit -m 'initial'");
});

afterEach(function () {
    if (isset($this->diffProjectPath) && is_dir($this->diffProjectPath)) {
        Process::run("rm -rf {$this->diffProjectPath}");
    }
});

test('getDiff returns diff when worktree has commits', function () {
    $manager = new WorktreeManager;
    $worktree = $manager->create($this->diffProjectPath, 'diff-rule');

    // Make a change and commit in the worktree
    Process::run("cd {$worktree['path']} && echo 'new content' > newfile.txt && git add . && git commit -m 'add newfile'");

    $diff = $manager->getDiff($worktree['path'], $this->diffProjectPath);

    expect($diff)->not->toBeNull();
    expect($diff)->toContain('diff --git');
    expect($diff)->toContain('newfile.txt');
    expect($diff)->toContain('+new content');

    $manager->remove($worktree['path'], $worktree['branch'], $this->diffProjectPath);
});

test('getDiff returns null when worktree has no commits', function () {
    $manager = new WorktreeManager;
    $worktree = $manager->create($this->diffProjectPath, 'diff-rule');

    $diff = $manager->getDiff($worktree['path'], $this->diffProjectPath);

    expect($diff)->toBeNull();

    $manager->remove($worktree['path'], $worktree['branch'], $this->diffProjectPath);
});

// --- Agent run diff display ---

test('agent run stores and retrieves diff data', function () {
    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test',
        'status' => 'success',
        'diff' => "diff --git a/file.txt b/file.txt\n--- a/file.txt\n+++ b/file.txt\n@@ -1 +1 @@\n-old\n+new",
    ]);

    $run->refresh();
    expect($run->diff)->toContain('diff --git');
    expect($run->diff)->toContain('+new');
    expect($run->diff)->toContain('-old');
});

test('webhook show page displays diff in agent run detail', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test',
        'status' => 'success',
        'diff' => "diff --git a/file.txt b/file.txt\n--- a/file.txt\n+++ b/file.txt\n@@ -1 +1 @@\n-old line\n+new line",
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $webhookLog->id])
        ->call('viewAgentRun', $run->id)
        ->assertSee('Diff')
        ->assertSee('old line')
        ->assertSee('new line');
});

test('webhook show page hides diff section when no diff', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $webhookLog = WebhookLog::factory()->create();
    AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test',
        'status' => 'success',
        'diff' => null,
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $webhookLog->id])
        ->assertDontSee('diff --git');
});
