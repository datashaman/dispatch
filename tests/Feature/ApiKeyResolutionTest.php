<?php

use App\DataTransferObjects\AgentConfig;
use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\RuleConfig;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookLog;

beforeEach(function () {
    $this->webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $this->agentRun = AgentRun::create([
        'webhook_log_id' => $this->webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
        'created_at' => now(),
    ]);
});

test('resolveApiKey returns project-level per-provider key first', function () {
    $user = User::factory()->create([
        'api_keys' => ['anthropic' => 'sk-user-anthropic'],
    ]);

    $project = Project::factory()->create([
        'agent_provider' => 'anthropic',
        'agent_secrets' => ['anthropic' => 'sk-project-anthropic'],
        'user_id' => $user->id,
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['api_key'])->toBe('sk-project-anthropic');
});

test('resolveApiKey falls back to legacy project api_key', function () {
    $project = Project::factory()->create([
        'agent_secrets' => ['api_key' => 'sk-legacy-key'],
        'agent_provider' => 'anthropic',
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['api_key'])->toBe('sk-legacy-key');
});

test('resolveApiKey falls back to user-level key when no project key', function () {
    $user = User::factory()->create([
        'api_keys' => ['anthropic' => 'sk-user-anthropic'],
    ]);

    $project = Project::factory()->create([
        'agent_secrets' => null,
        'agent_provider' => 'anthropic',
        'user_id' => $user->id,
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['api_key'])->toBe('sk-user-anthropic');
});

test('resolveApiKey returns null when no keys configured', function () {
    $user = User::factory()->create([
        'api_keys' => null,
    ]);

    $project = Project::factory()->create([
        'agent_secrets' => null,
        'agent_provider' => 'anthropic',
        'user_id' => $user->id,
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['api_key'])->toBeNull();
});

test('resolveAgentConfig uses rule-level provider over project and user', function () {
    $user = User::factory()->create([
        'default_provider' => 'gemini',
        'default_model' => 'gemini-2.5-pro',
    ]);

    $project = Project::factory()->create([
        'agent_provider' => 'openai',
        'agent_model' => 'gpt-4.1',
        'user_id' => $user->id,
    ]);

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'test',
        agent: new AgentConfig(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
        ),
    );

    $job = makeJob($this->agentRun, $project, ruleConfig: $ruleConfig);
    $config = invokeResolveAgentConfig($job);

    expect($config['provider'])->toBe('anthropic')
        ->and($config['model'])->toBe('claude-sonnet-4-6');
});

test('resolveAgentConfig uses project-level provider when no rule-level', function () {
    $user = User::factory()->create([
        'default_provider' => 'gemini',
        'default_model' => 'gemini-2.5-pro',
    ]);

    $project = Project::factory()->create([
        'agent_provider' => 'openai',
        'agent_model' => 'gpt-4.1',
        'user_id' => $user->id,
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['provider'])->toBe('openai')
        ->and($config['model'])->toBe('gpt-4.1');
});

test('resolveAgentConfig uses user-level defaults when no rule or project config', function () {
    $user = User::factory()->create([
        'default_provider' => 'gemini',
        'default_model' => 'gemini-2.5-pro',
    ]);

    $project = Project::factory()->create([
        'agent_provider' => null,
        'agent_model' => null,
        'user_id' => $user->id,
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['provider'])->toBe('gemini')
        ->and($config['model'])->toBe('gemini-2.5-pro');
});

test('resolveAgentConfig returns null provider and model when nothing configured', function () {
    $user = User::factory()->create([
        'default_provider' => null,
        'default_model' => null,
    ]);

    $project = Project::factory()->create([
        'agent_provider' => null,
        'agent_model' => null,
        'user_id' => $user->id,
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['provider'])->toBeNull()
        ->and($config['model'])->toBeNull();
});

test('user api_keys are encrypted at rest', function () {
    $user = User::factory()->create([
        'api_keys' => ['anthropic' => 'sk-secret-123'],
    ]);

    // Raw DB value should be encrypted, not plaintext
    $rawValue = DB::table('users')->where('id', $user->id)->value('api_keys');

    expect($rawValue)->not->toContain('sk-secret-123')
        ->and($rawValue)->not->toBeNull();

    // Eloquent access should decrypt
    $user->refresh();
    expect($user->api_keys['anthropic'])->toBe('sk-secret-123');
});

test('resolveApiKey matches key to resolved provider', function () {
    $user = User::factory()->create([
        'api_keys' => ['anthropic' => 'sk-user-anthropic'],
    ]);

    $project = Project::factory()->create([
        'agent_secrets' => ['openai' => 'sk-openai-project'],
        'agent_provider' => 'openai',
        'user_id' => $user->id,
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    // Should use the openai key since provider is openai
    expect($config['api_key'])->toBe('sk-openai-project')
        ->and($config['provider'])->toBe('openai');
});

test('resolveAgentConfig resolves cheapest model strategy', function () {
    $project = Project::factory()->create([
        'agent_provider' => 'anthropic',
        'agent_model' => 'cheapest',
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['provider'])->toBe('anthropic')
        ->and($config['model'])->toBe('claude-haiku-4-5-20251001');
});

test('resolveAgentConfig resolves smartest model strategy', function () {
    $project = Project::factory()->create([
        'agent_provider' => 'anthropic',
        'agent_model' => 'smartest',
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['provider'])->toBe('anthropic')
        ->and($config['model'])->toBe('claude-opus-4-6');
});

test('resolveAgentConfig passes through explicit model unchanged', function () {
    $project = Project::factory()->create([
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-6',
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['model'])->toBe('claude-sonnet-4-6');
});

test('resolveApiKey returns null when project has no user', function () {
    $project = Project::factory()->create([
        'agent_secrets' => null,
        'agent_provider' => 'anthropic',
        'user_id' => null,
    ]);

    $job = makeJob($this->agentRun, $project);
    $config = invokeResolveAgentConfig($job);

    expect($config['api_key'])->toBeNull();
});

// --- Helpers ---

function makeJob(
    AgentRun $agentRun,
    Project $project,
    ?RuleConfig $ruleConfig = null,
    ?DispatchConfig $dispatchConfig = null,
): ProcessAgentRun {
    $ruleConfig ??= new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'test prompt',
    );

    $dispatchConfig ??= new DispatchConfig(
        version: 1,
        agentProvider: $project->agent_provider,
        agentModel: $project->agent_model,
    );

    return new ProcessAgentRun(
        agentRun: $agentRun,
        ruleConfig: $ruleConfig,
        payload: ['action' => 'test'],
        project: $project,
        dispatchConfig: $dispatchConfig,
    );
}

function invokeResolveAgentConfig(ProcessAgentRun $job): array
{
    $reflection = new ReflectionMethod($job, 'resolveAgentConfig');

    return $reflection->invoke($job);
}
