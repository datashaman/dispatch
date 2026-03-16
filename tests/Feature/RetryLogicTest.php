<?php

use App\Ai\Agents\DispatchAgent;
use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\RetryConfig;
use App\DataTransferObjects\RuleConfig;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\WebhookLog;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_retry_');
    mkdir($this->tempDir, 0755, true);

    $this->project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => $this->tempDir,
    ]);

    $this->dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-6',
    );

    $this->webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);
});

afterEach(function () {
    @unlink($this->tempDir.'/dispatch.yml');
    @rmdir($this->tempDir);
});

test('retry config sets job tries and backoff', function () {
    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
        retry: new RetryConfig(enabled: true, maxAttempts: 5, delay: 30),
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $this->project, $this->dispatchConfig);

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe(30);
});

test('job defaults to 1 try when retry is not configured', function () {
    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $this->project, $this->dispatchConfig);

    expect($job->tries)->toBe(1)
        ->and($job->backoff)->toBe(0);
});

test('job defaults to 1 try when retry is disabled', function () {
    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
        retry: new RetryConfig(enabled: false, maxAttempts: 5, delay: 30),
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $this->project, $this->dispatchConfig);

    expect($job->tries)->toBe(1)
        ->and($job->backoff)->toBe(0);
});

test('attempt number is logged on agent_run', function () {
    DispatchAgent::fake(['Success']);

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $this->project, $this->dispatchConfig);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->attempt)->toBe(1)
        ->and($agentRun->status)->toBe('success');
});

test('failed execution throws when retry is enabled for queue worker to retry', function () {
    DispatchAgent::fake(fn () => throw new RuntimeException('Transient failure'));

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
        retry: new RetryConfig(enabled: true, maxAttempts: 3, delay: 10),
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $this->project, $this->dispatchConfig);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Transient failure');

    $agentRun->refresh();
    expect($agentRun->status)->toBe('failed')
        ->and($agentRun->error)->toBe('Transient failure');
});

test('failed execution does not throw when retry is not configured', function () {
    DispatchAgent::fake(fn () => throw new RuntimeException('Permanent failure'));

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $this->project, $this->dispatchConfig);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('failed')
        ->and($agentRun->error)->toBe('Permanent failure');
});

test('failed() method updates agent_run to failed status', function () {
    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'attempt' => 3,
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $this->project, $this->dispatchConfig);
    $job->failed(new RuntimeException('Final failure after retries'));

    $agentRun->refresh();
    expect($agentRun->status)->toBe('failed')
        ->and($agentRun->error)->toBe('Final failure after retries');
});

test('successful execution does not throw even with retry enabled', function () {
    DispatchAgent::fake(['All good']);

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
        retry: new RetryConfig(enabled: true, maxAttempts: 3, delay: 10),
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $this->project, $this->dispatchConfig);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success')
        ->and($agentRun->output)->toBe('All good');
});

test('retry config uses default values from DTO', function () {
    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
        retry: new RetryConfig(enabled: true, maxAttempts: 3, delay: 60),
    );

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $this->project, $this->dispatchConfig);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(60);
});
