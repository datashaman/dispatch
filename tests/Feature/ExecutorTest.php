<?php

use App\Ai\Agents\DispatchAgent;
use App\Contracts\Executor;
use App\DataTransferObjects\AgentConfig;
use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\ExecutionResult;
use App\DataTransferObjects\RuleConfig;
use App\Executors\LaravelAiExecutor;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;

test('Executor interface has execute method', function () {
    $executor = app(LaravelAiExecutor::class);

    expect($executor)->toBeInstanceOf(Executor::class);
});

test('ExecutionResult DTO holds execution result data', function () {
    $result = new ExecutionResult(
        status: 'success',
        output: 'Agent output text',
        tokensUsed: 150,
        cost: '0.001500',
        durationMs: 2500,
    );

    expect($result->status)->toBe('success')
        ->and($result->output)->toBe('Agent output text')
        ->and($result->tokensUsed)->toBe(150)
        ->and($result->cost)->toBe('0.001500')
        ->and($result->durationMs)->toBe(2500)
        ->and($result->error)->toBeNull();
});

test('ExecutionResult DTO holds failure data', function () {
    $result = new ExecutionResult(
        status: 'failed',
        error: 'API error occurred',
        durationMs: 100,
    );

    expect($result->status)->toBe('failed')
        ->and($result->error)->toBe('API error occurred')
        ->and($result->output)->toBeNull()
        ->and($result->tokensUsed)->toBeNull();
});

test('LaravelAiExecutor executes agent and returns success result', function () {
    DispatchAgent::fake(['Agent response output']);

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

    $executor = app(LaravelAiExecutor::class);
    $result = $executor->execute($agentRun, 'Analyze this code', [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
    ]);

    expect($result)->toBeInstanceOf(ExecutionResult::class)
        ->and($result->status)->toBe('success')
        ->and($result->output)->toBe('Agent response output')
        ->and($result->tokensUsed)->toBeInt()
        ->and($result->durationMs)->toBeInt();
});

test('LaravelAiExecutor returns failure result on exception', function () {
    DispatchAgent::fake(fn () => throw new RuntimeException('API rate limit exceeded'));

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

    $executor = app(LaravelAiExecutor::class);
    $result = $executor->execute($agentRun, 'Analyze this code', [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
    ]);

    expect($result->status)->toBe('failed')
        ->and($result->error)->toBe('API rate limit exceeded')
        ->and($result->output)->toBeNull()
        ->and($result->durationMs)->toBeInt();
});

test('LaravelAiExecutor loads system prompt from instructions file', function () {
    $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_test_');
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/INSTRUCTIONS.md', 'You are a code reviewer.');

    DispatchAgent::fake(['Review complete']);

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

    $executor = app(LaravelAiExecutor::class);
    $result = $executor->execute($agentRun, 'Review this PR', [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
        'instructions_file' => 'INSTRUCTIONS.md',
        'project_path' => $tempDir,
    ]);

    expect($result->status)->toBe('success')
        ->and($result->output)->toBe('Review complete');

    DispatchAgent::assertPrompted('Review this PR');

    // Cleanup
    File::deleteDirectory($tempDir);
});

test('LaravelAiExecutor uses default prompt when instructions file is missing', function () {
    DispatchAgent::fake(['Default response']);

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

    $executor = app(LaravelAiExecutor::class);
    $result = $executor->execute($agentRun, 'Do something', [
        'instructions_file' => 'nonexistent.md',
        'project_path' => '/nonexistent/path',
    ]);

    expect($result->status)->toBe('success')
        ->and($result->output)->toBe('Default response');
});

test('LaravelAiExecutor passes provider to agent prompt', function () {
    DispatchAgent::fake(['Provider-specific response']);

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

    $executor = app(LaravelAiExecutor::class);
    $result = $executor->execute($agentRun, 'Hello', [
        'provider' => 'openai',
        'model' => 'gpt-4o',
    ]);

    expect($result->status)->toBe('success');

    DispatchAgent::assertPrompted('Hello');
});

test('ProcessAgentRun job uses executor to process agent run', function () {
    DispatchAgent::fake(['Execution complete']);

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test-project',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-20250514',
    ]);

    $dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-20250514',
    );

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Analyze issue #{{ event.issue.number }}',
    );

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

    $job = new ProcessAgentRun($agentRun, $ruleConfig, ['issue' => ['number' => 42]], $project, $dispatchConfig);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success')
        ->and($agentRun->output)->toBe('Execution complete')
        ->and($agentRun->tokens_used)->toBeInt()
        ->and($agentRun->duration_ms)->toBeInt();
});

test('ProcessAgentRun job resolves agent config with project-level fallback', function () {
    DispatchAgent::fake(['Fallback response']);

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test-project',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-20250514',
        'agent_instructions_file' => 'AGENT.md',
    ]);

    $dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentInstructionsFile: 'AGENT.md',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-20250514',
    );

    // No rule-level agent config — should fall back to project-level
    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
    );

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

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $project, $dispatchConfig);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success');

    DispatchAgent::assertPrompted('Do something');
});

test('ProcessAgentRun job uses rule-level agent config when available', function () {
    DispatchAgent::fake(['Rule-level response']);

    $tmpDir = sys_get_temp_dir().'/'.uniqid('dispatch-test-');
    mkdir($tmpDir);
    Process::run(['git', 'init', $tmpDir]);

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => $tmpDir,
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-20250514',
    ]);

    $dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-20250514',
    );

    // Rule-level agent config overrides project-level
    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Review this',
        agent: new AgentConfig(
            provider: 'openai',
            model: 'gpt-4o',
            maxTokens: 4096,
            tools: ['read', 'write'],
            disallowedTools: ['bash'],
            isolation: true,
        ),
    );

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

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $project, $dispatchConfig);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success')
        ->and($agentRun->output)->toBe('Rule-level response');
});

test('ProcessAgentRun job updates agent_run with failure on exception', function () {
    DispatchAgent::fake(fn () => throw new RuntimeException('Provider error'));

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test-project',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-20250514',
    ]);

    $dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-20250514',
    );

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'Do something',
    );

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

    $job = new ProcessAgentRun($agentRun, $ruleConfig, [], $project, $dispatchConfig);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('failed')
        ->and($agentRun->error)->toBe('Provider error')
        ->and($agentRun->duration_ms)->toBeInt();
});

test('ProcessAgentRun job renders prompt template with payload', function () {
    DispatchAgent::fake(['Rendered result']);

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test-project',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-20250514',
    ]);

    $dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-20250514',
    );

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'issues.opened',
        prompt: 'Review issue "{{ event.issue.title }}" by {{ event.issue.user.login }}',
    );

    $webhookLog = WebhookLog::create([
        'event_type' => 'issues.opened',
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

    $payload = [
        'issue' => [
            'title' => 'Bug in login',
            'user' => ['login' => 'octocat'],
        ],
    ];

    $job = new ProcessAgentRun($agentRun, $ruleConfig, $payload, $project, $dispatchConfig);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success');

    DispatchAgent::assertPrompted('Review issue "Bug in login" by octocat');
});

test('DispatchAgent implements Agent and HasTools interfaces', function () {
    $agent = new DispatchAgent('You are helpful.', []);

    expect($agent)->toBeInstanceOf(Agent::class)
        ->and($agent)->toBeInstanceOf(HasTools::class)
        ->and($agent->instructions())->toBe('You are helpful.')
        ->and(iterator_to_array($agent->tools()))->toBe([]);
});
