<?php

use App\Contracts\Executor;
use App\DataTransferObjects\ExecutionResult;
use App\Executors\ClaudeCliExecutor;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Rule;
use App\Models\WebhookLog;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

test('ClaudeCliExecutor implements Executor interface', function () {
    $executor = new ClaudeCliExecutor;

    expect($executor)->toBeInstanceOf(Executor::class);
});

test('ClaudeCliExecutor executes claude CLI and returns success result', function () {
    Process::fake([
        '*' => Process::result(
            output: 'Agent analysis complete',
            exitCode: 0,
        ),
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'created_at' => now(),
    ]);

    $executor = new ClaudeCliExecutor;
    $result = $executor->execute($agentRun, 'Analyze this code', [
        'project_path' => '/tmp/test-project',
    ]);

    expect($result)->toBeInstanceOf(ExecutionResult::class)
        ->and($result->status)->toBe('success')
        ->and($result->output)->toBe('Agent analysis complete')
        ->and($result->durationMs)->toBeInt();

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;

        return is_array($command)
            && $command[0] === 'claude'
            && in_array('--print', $command)
            && in_array('--prompt', $command)
            && in_array('Analyze this code', $command);
    });
});

test('ClaudeCliExecutor returns failure result on non-zero exit code', function () {
    Process::fake([
        '*' => Process::result(
            output: 'Partial output',
            errorOutput: 'Error: rate limit exceeded',
            exitCode: 1,
        ),
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'created_at' => now(),
    ]);

    $executor = new ClaudeCliExecutor;
    $result = $executor->execute($agentRun, 'Do something', [
        'project_path' => '/tmp/test-project',
    ]);

    expect($result->status)->toBe('failed')
        ->and($result->output)->toBe('Partial output')
        ->and($result->error)->toBe('Error: rate limit exceeded')
        ->and($result->durationMs)->toBeInt();
});

test('ClaudeCliExecutor returns failure result on exception', function () {
    Process::fake([
        '*' => Process::result(
            exitCode: 127,
            errorOutput: '',
        ),
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'created_at' => now(),
    ]);

    $executor = new ClaudeCliExecutor;
    $result = $executor->execute($agentRun, 'Hello', [
        'project_path' => '/tmp/test-project',
    ]);

    expect($result->status)->toBe('failed')
        ->and($result->durationMs)->toBeInt();
});

test('ClaudeCliExecutor passes allowed tools as CLI flags', function () {
    Process::fake([
        '*' => Process::result(output: 'Done', exitCode: 0),
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'created_at' => now(),
    ]);

    $executor = new ClaudeCliExecutor;
    $executor->execute($agentRun, 'Review code', [
        'project_path' => '/tmp/test-project',
        'tools' => ['Read', 'Glob', 'Grep'],
    ]);

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;
        if (! is_array($command)) {
            return false;
        }

        $allowedToolIndices = array_keys(array_filter($command, fn ($v) => $v === '--allowedTools'));
        $toolValues = [];
        foreach ($allowedToolIndices as $idx) {
            $toolValues[] = $command[$idx + 1] ?? null;
        }

        return in_array('Read', $toolValues)
            && in_array('Glob', $toolValues)
            && in_array('Grep', $toolValues);
    });
});

test('ClaudeCliExecutor passes disallowed tools as CLI flags', function () {
    Process::fake([
        '*' => Process::result(output: 'Done', exitCode: 0),
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'created_at' => now(),
    ]);

    $executor = new ClaudeCliExecutor;
    $executor->execute($agentRun, 'Review code', [
        'project_path' => '/tmp/test-project',
        'disallowed_tools' => ['Bash', 'Write'],
    ]);

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;
        if (! is_array($command)) {
            return false;
        }

        $disallowedToolIndices = array_keys(array_filter($command, fn ($v) => $v === '--disallowedTools'));
        $toolValues = [];
        foreach ($disallowedToolIndices as $idx) {
            $toolValues[] = $command[$idx + 1] ?? null;
        }

        return in_array('Bash', $toolValues)
            && in_array('Write', $toolValues);
    });
});

test('ClaudeCliExecutor passes model flag', function () {
    Process::fake([
        '*' => Process::result(output: 'Done', exitCode: 0),
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'created_at' => now(),
    ]);

    $executor = new ClaudeCliExecutor;
    $executor->execute($agentRun, 'Hello', [
        'project_path' => '/tmp/test-project',
        'model' => 'claude-sonnet-4-20250514',
    ]);

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;
        if (! is_array($command)) {
            return false;
        }

        $modelIdx = array_search('--model', $command);

        return $modelIdx !== false && ($command[$modelIdx + 1] ?? null) === 'claude-sonnet-4-20250514';
    });
});

test('ClaudeCliExecutor loads system prompt from instructions file', function () {
    $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_cli_test_');
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/INSTRUCTIONS.md', 'You are a code reviewer.');

    Process::fake([
        '*' => Process::result(output: 'Review done', exitCode: 0),
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'created_at' => now(),
    ]);

    $executor = new ClaudeCliExecutor;
    $result = $executor->execute($agentRun, 'Review this PR', [
        'project_path' => $tempDir,
        'instructions_file' => 'INSTRUCTIONS.md',
    ]);

    expect($result->status)->toBe('success')
        ->and($result->output)->toBe('Review done');

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;
        if (! is_array($command)) {
            return false;
        }

        $systemIdx = array_search('--system-prompt', $command);

        return $systemIdx !== false && ($command[$systemIdx + 1] ?? null) === 'You are a code reviewer.';
    });

    // Cleanup
    unlink($tempDir.'/INSTRUCTIONS.md');
    rmdir($tempDir);
});

test('ClaudeCliExecutor omits system prompt when instructions file is missing', function () {
    Process::fake([
        '*' => Process::result(output: 'Default response', exitCode: 0),
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'created_at' => now(),
    ]);

    $executor = new ClaudeCliExecutor;
    $result = $executor->execute($agentRun, 'Do something', [
        'project_path' => '/nonexistent/path',
        'instructions_file' => 'nonexistent.md',
    ]);

    expect($result->status)->toBe('success');

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;

        return is_array($command) && ! in_array('--system-prompt', $command);
    });
});

test('ClaudeCliExecutor sets working directory to project path', function () {
    Process::fake([
        '*' => Process::result(output: 'Done', exitCode: 0),
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'created_at' => now(),
    ]);

    $executor = new ClaudeCliExecutor;
    $executor->execute($agentRun, 'Hello', [
        'project_path' => '/my/project/path',
    ]);

    Process::assertRan(function (PendingProcess $process) {
        return $process->path === '/my/project/path';
    });
});

test('ProcessAgentRun job resolves ClaudeCliExecutor when executor is claude-cli', function () {
    Process::fake([
        '*' => Process::result(output: 'CLI output', exitCode: 0),
    ]);

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test-project',
        'agent_executor' => 'claude-cli',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-20250514',
    ]);

    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'rule_id' => 'test-rule',
        'event' => 'push',
        'prompt' => 'Analyze this code',
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $rule, []);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success')
        ->and($agentRun->output)->toBe('CLI output')
        ->and($agentRun->duration_ms)->toBeInt();

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;

        return is_array($command) && $command[0] === 'claude';
    });
});

test('ClaudeCliExecutor uses output format text flag', function () {
    Process::fake([
        '*' => Process::result(output: 'Done', exitCode: 0),
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'created_at' => now(),
    ]);

    $executor = new ClaudeCliExecutor;
    $executor->execute($agentRun, 'Hello', [
        'project_path' => '/tmp/test-project',
    ]);

    Process::assertRan(function (PendingProcess $process) {
        $command = $process->command;
        if (! is_array($command)) {
            return false;
        }

        $formatIdx = array_search('--output-format', $command);

        return $formatIdx !== false && ($command[$formatIdx + 1] ?? null) === 'text';
    });
});
