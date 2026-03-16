<?php

use App\Ai\Agents\DispatchAgent;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Rule;
use App\Models\RuleRetryConfig;
use App\Models\WebhookLog;

beforeEach(function () {
    $this->project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test-project',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-20250514',
    ]);

    $this->rule = Rule::factory()->create([
        'project_id' => $this->project->id,
        'rule_id' => 'test-rule',
        'event' => 'push',
        'prompt' => 'Do something',
    ]);

    $this->webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);
});

test('retry config sets job tries and backoff', function () {
    RuleRetryConfig::create([
        'rule_id' => $this->rule->id,
        'enabled' => true,
        'max_attempts' => 5,
        'delay' => 30,
    ]);

    $this->rule->refresh();

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $this->rule, []);

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe(30);
});

test('job defaults to 1 try when retry is not configured', function () {
    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $this->rule, []);

    expect($job->tries)->toBe(1)
        ->and($job->backoff)->toBe(0);
});

test('job defaults to 1 try when retry is disabled', function () {
    RuleRetryConfig::create([
        'rule_id' => $this->rule->id,
        'enabled' => false,
        'max_attempts' => 5,
        'delay' => 30,
    ]);

    $this->rule->refresh();

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $this->rule, []);

    expect($job->tries)->toBe(1)
        ->and($job->backoff)->toBe(0);
});

test('attempt number is logged on agent_run', function () {
    DispatchAgent::fake(['Success']);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $this->rule, []);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->attempt)->toBe(1)
        ->and($agentRun->status)->toBe('success');
});

test('failed execution throws when retry is enabled for queue worker to retry', function () {
    DispatchAgent::fake(fn () => throw new RuntimeException('Transient failure'));

    RuleRetryConfig::create([
        'rule_id' => $this->rule->id,
        'enabled' => true,
        'max_attempts' => 3,
        'delay' => 10,
    ]);

    $this->rule->refresh();

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $this->rule, []);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Transient failure');

    $agentRun->refresh();
    expect($agentRun->status)->toBe('failed')
        ->and($agentRun->error)->toBe('Transient failure');
});

test('failed execution does not throw when retry is not configured', function () {
    DispatchAgent::fake(fn () => throw new RuntimeException('Permanent failure'));

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $this->rule, []);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('failed')
        ->and($agentRun->error)->toBe('Permanent failure');
});

test('failed() method updates agent_run to failed status', function () {
    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'running',
        'attempt' => 3,
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $this->rule, []);
    $job->failed(new RuntimeException('Final failure after retries'));

    $agentRun->refresh();
    expect($agentRun->status)->toBe('failed')
        ->and($agentRun->error)->toBe('Final failure after retries');
});

test('successful execution does not throw even with retry enabled', function () {
    DispatchAgent::fake(['All good']);

    RuleRetryConfig::create([
        'rule_id' => $this->rule->id,
        'enabled' => true,
        'max_attempts' => 3,
        'delay' => 10,
    ]);

    $this->rule->refresh();

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $this->rule, []);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success')
        ->and($agentRun->output)->toBe('All good');
});

test('retry config uses default values from database', function () {
    RuleRetryConfig::create([
        'rule_id' => $this->rule->id,
        'enabled' => true,
        'max_attempts' => 3,
        'delay' => 60,
    ]);

    $this->rule->refresh();

    $agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $job = new ProcessAgentRun($agentRun, $this->rule, []);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(60);
});
