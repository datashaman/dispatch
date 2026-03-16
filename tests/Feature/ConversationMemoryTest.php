<?php

use App\Ai\Agents\DispatchAgent;
use App\Executors\ClaudeCliExecutor;
use App\Executors\LaravelAiExecutor;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Rule;
use App\Models\WebhookLog;
use App\Services\ConversationMemory;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;

// --- ConversationMemory::deriveThreadKey ---

test('derives thread key from issue payload', function () {
    $memory = new ConversationMemory;

    $key = $memory->deriveThreadKey([
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 42],
    ]);

    expect($key)->toBe('owner/repo:issue:42');
});

test('derives thread key from pull request payload', function () {
    $memory = new ConversationMemory;

    $key = $memory->deriveThreadKey([
        'repository' => ['full_name' => 'owner/repo'],
        'pull_request' => ['number' => 99],
    ]);

    expect($key)->toBe('owner/repo:pr:99');
});

test('derives thread key from discussion payload', function () {
    $memory = new ConversationMemory;

    $key = $memory->deriveThreadKey([
        'repository' => ['full_name' => 'owner/repo'],
        'discussion' => ['number' => 7],
    ]);

    expect($key)->toBe('owner/repo:discussion:7');
});

test('pull request takes precedence over issue in thread key', function () {
    $memory = new ConversationMemory;

    $key = $memory->deriveThreadKey([
        'repository' => ['full_name' => 'owner/repo'],
        'pull_request' => ['number' => 10],
        'issue' => ['number' => 5],
    ]);

    expect($key)->toBe('owner/repo:pr:10');
});

test('returns null for payload without repository', function () {
    $memory = new ConversationMemory;

    expect($memory->deriveThreadKey([]))->toBeNull();
});

test('returns null for payload without identifiable resource', function () {
    $memory = new ConversationMemory;

    $key = $memory->deriveThreadKey([
        'repository' => ['full_name' => 'owner/repo'],
    ]);

    expect($key)->toBeNull();
});

// --- ConversationMemory::retrieveHistory ---

test('retrieves prior conversation history for a thread', function () {
    $webhookLog1 = WebhookLog::create([
        'event_type' => 'issue_comment.created',
        'repo' => 'owner/repo',
        'payload' => [
            'repository' => ['full_name' => 'owner/repo'],
            'issue' => ['number' => 42],
            'comment' => ['body' => 'First comment'],
        ],
        'status' => 'processed',
        'created_at' => now()->subMinutes(10),
    ]);

    $run1 = AgentRun::create([
        'webhook_log_id' => $webhookLog1->id,
        'rule_id' => 'analyze',
        'status' => 'success',
        'output' => 'Analysis of first comment',
        'created_at' => now()->subMinutes(10),
    ]);

    $webhookLog2 = WebhookLog::create([
        'event_type' => 'issue_comment.created',
        'repo' => 'owner/repo',
        'payload' => [
            'repository' => ['full_name' => 'owner/repo'],
            'issue' => ['number' => 42],
            'comment' => ['body' => 'Second comment'],
        ],
        'status' => 'processed',
        'created_at' => now()->subMinutes(5),
    ]);

    $run2 = AgentRun::create([
        'webhook_log_id' => $webhookLog2->id,
        'rule_id' => 'analyze',
        'status' => 'success',
        'output' => 'Analysis of second comment',
        'created_at' => now()->subMinutes(5),
    ]);

    $memory = new ConversationMemory;
    $history = $memory->retrieveHistory('owner/repo:issue:42');

    expect($history)->toHaveCount(4) // 2 user + 2 assistant messages
        ->and($history[0]['role'])->toBe('user')
        ->and($history[1]['role'])->toBe('assistant')
        ->and($history[1]['content'])->toBe('Analysis of first comment')
        ->and($history[2]['role'])->toBe('user')
        ->and($history[3]['role'])->toBe('assistant')
        ->and($history[3]['content'])->toBe('Analysis of second comment');
});

test('excludes current run from conversation history', function () {
    $webhookLog = WebhookLog::create([
        'event_type' => 'issue_comment.created',
        'repo' => 'owner/repo',
        'payload' => [
            'repository' => ['full_name' => 'owner/repo'],
            'issue' => ['number' => 42],
            'comment' => ['body' => 'A comment'],
        ],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $run = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'analyze',
        'status' => 'success',
        'output' => 'Some output',
        'created_at' => now(),
    ]);

    $memory = new ConversationMemory;
    $history = $memory->retrieveHistory('owner/repo:issue:42', $run->id);

    expect($history)->toBeEmpty();
});

test('does not include failed runs in history', function () {
    $webhookLog = WebhookLog::create([
        'event_type' => 'issue_comment.created',
        'repo' => 'owner/repo',
        'payload' => [
            'repository' => ['full_name' => 'owner/repo'],
            'issue' => ['number' => 42],
        ],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'analyze',
        'status' => 'failed',
        'error' => 'API error',
        'created_at' => now(),
    ]);

    $memory = new ConversationMemory;
    $history = $memory->retrieveHistory('owner/repo:issue:42');

    expect($history)->toBeEmpty();
});

test('does not mix threads from different issues', function () {
    $webhookLog1 = WebhookLog::create([
        'event_type' => 'issue_comment.created',
        'repo' => 'owner/repo',
        'payload' => [
            'repository' => ['full_name' => 'owner/repo'],
            'issue' => ['number' => 42],
            'comment' => ['body' => 'Comment on 42'],
        ],
        'status' => 'processed',
        'created_at' => now()->subMinutes(5),
    ]);

    AgentRun::create([
        'webhook_log_id' => $webhookLog1->id,
        'rule_id' => 'analyze',
        'status' => 'success',
        'output' => 'Output for 42',
        'created_at' => now()->subMinutes(5),
    ]);

    $webhookLog2 = WebhookLog::create([
        'event_type' => 'issue_comment.created',
        'repo' => 'owner/repo',
        'payload' => [
            'repository' => ['full_name' => 'owner/repo'],
            'issue' => ['number' => 99],
            'comment' => ['body' => 'Comment on 99'],
        ],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    AgentRun::create([
        'webhook_log_id' => $webhookLog2->id,
        'rule_id' => 'analyze',
        'status' => 'success',
        'output' => 'Output for 99',
        'created_at' => now(),
    ]);

    $memory = new ConversationMemory;
    $history = $memory->retrieveHistory('owner/repo:issue:42');

    expect($history)->toHaveCount(2) // 1 user + 1 assistant
        ->and($history[1]['content'])->toBe('Output for 42');
});

test('returns empty history for unknown thread', function () {
    $memory = new ConversationMemory;
    $history = $memory->retrieveHistory('owner/repo:issue:999');

    expect($history)->toBeEmpty();
});

// --- ConversationMemory::formatAsText ---

test('formats conversation history as text', function () {
    $memory = new ConversationMemory;

    $messages = [
        ['role' => 'user', 'content' => 'What is this issue about?'],
        ['role' => 'assistant', 'content' => 'This issue is about a bug.'],
    ];

    $text = $memory->formatAsText($messages);

    expect($text)->toContain('## Prior Conversation History')
        ->and($text)->toContain('### User')
        ->and($text)->toContain('What is this issue about?')
        ->and($text)->toContain('### Assistant')
        ->and($text)->toContain('This issue is about a bug.');
});

test('returns empty string for empty history', function () {
    $memory = new ConversationMemory;

    expect($memory->formatAsText([]))->toBe('');
});

// --- LaravelAiExecutor with conversation history ---

test('LaravelAiExecutor passes conversation history to DispatchAgent', function () {
    DispatchAgent::fake(['Response with context']);

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

    $conversationHistory = [
        ['role' => 'user', 'content' => 'Prior question'],
        ['role' => 'assistant', 'content' => 'Prior answer'],
    ];

    $executor = app(LaravelAiExecutor::class);
    $result = $executor->execute($agentRun, 'New question', [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
    ], $conversationHistory);

    expect($result->status)->toBe('success')
        ->and($result->output)->toBe('Response with context');
});

test('LaravelAiExecutor works without conversation history', function () {
    DispatchAgent::fake(['Simple response']);

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
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
    ]);

    expect($result->status)->toBe('success');
});

// --- ClaudeCliExecutor with conversation history ---

test('ClaudeCliExecutor prepends conversation history to prompt', function () {
    Process::fake([
        '*' => Process::result(output: 'CLI response', exitCode: 0),
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

    $conversationHistory = [
        ['role' => 'user', 'content' => 'Prior question'],
        ['role' => 'assistant', 'content' => 'Prior answer'],
    ];

    $executor = new ClaudeCliExecutor;
    $result = $executor->execute($agentRun, 'New question', [
        'project_path' => '/tmp/test-project',
    ], $conversationHistory);

    expect($result->status)->toBe('success');

    Process::assertRan(function ($process) {
        $command = $process->command;
        $promptIndex = array_search('--prompt', $command);

        if ($promptIndex === false) {
            return false;
        }

        $promptValue = $command[$promptIndex + 1];

        return str_contains($promptValue, '## Prior Conversation History')
            && str_contains($promptValue, 'Prior question')
            && str_contains($promptValue, 'Prior answer')
            && str_contains($promptValue, '## Current Request')
            && str_contains($promptValue, 'New question');
    });
});

test('ClaudeCliExecutor sends plain prompt without history', function () {
    Process::fake([
        '*' => Process::result(output: 'CLI response', exitCode: 0),
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
    $result = $executor->execute($agentRun, 'Simple prompt', [
        'project_path' => '/tmp/test-project',
    ]);

    expect($result->status)->toBe('success');

    Process::assertRan(function ($process) {
        $command = $process->command;
        $promptIndex = array_search('--prompt', $command);

        if ($promptIndex === false) {
            return false;
        }

        $promptValue = $command[$promptIndex + 1];

        return $promptValue === 'Simple prompt';
    });
});

// --- ProcessAgentRun with conversation memory ---

test('ProcessAgentRun loads conversation history from prior runs', function () {
    DispatchAgent::fake(['Response with history']);

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test-project',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-20250514',
    ]);

    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'rule_id' => 'analyze',
        'event' => 'issue_comment.created',
        'prompt' => 'Respond to: {{ event.comment.body }}',
    ]);

    // Create a prior successful run on the same issue
    $priorWebhookLog = WebhookLog::create([
        'event_type' => 'issue_comment.created',
        'repo' => 'owner/repo',
        'payload' => [
            'repository' => ['full_name' => 'owner/repo'],
            'issue' => ['number' => 42],
            'comment' => ['body' => 'First question'],
        ],
        'status' => 'processed',
        'created_at' => now()->subMinutes(10),
    ]);

    AgentRun::create([
        'webhook_log_id' => $priorWebhookLog->id,
        'rule_id' => 'analyze',
        'status' => 'success',
        'output' => 'Answer to first question',
        'created_at' => now()->subMinutes(10),
    ]);

    // Create current webhook and run
    $currentWebhookLog = WebhookLog::create([
        'event_type' => 'issue_comment.created',
        'repo' => 'owner/repo',
        'payload' => [
            'repository' => ['full_name' => 'owner/repo'],
            'issue' => ['number' => 42],
            'comment' => ['body' => 'Follow-up question'],
        ],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $currentRun = AgentRun::create([
        'webhook_log_id' => $currentWebhookLog->id,
        'rule_id' => 'analyze',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 42],
        'comment' => ['body' => 'Follow-up question'],
    ];

    $job = new ProcessAgentRun($currentRun, $rule, $payload);
    $job->handle();

    $currentRun->refresh();
    expect($currentRun->status)->toBe('success')
        ->and($currentRun->output)->toBe('Response with history');
});

test('ProcessAgentRun works when no prior conversation exists', function () {
    DispatchAgent::fake(['Fresh response']);

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test-project',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-20250514',
    ]);

    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'rule_id' => 'analyze',
        'event' => 'issues.opened',
        'prompt' => 'Analyze issue',
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'issues.opened',
        'repo' => 'owner/repo',
        'payload' => [
            'repository' => ['full_name' => 'owner/repo'],
            'issue' => ['number' => 1],
        ],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'analyze',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $payload = [
        'repository' => ['full_name' => 'owner/repo'],
        'issue' => ['number' => 1],
    ];

    $job = new ProcessAgentRun($agentRun, $rule, $payload);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success');
});

test('ProcessAgentRun works with payloads without thread scope', function () {
    DispatchAgent::fake(['Push response']);

    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'path' => '/tmp/test-project',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-20250514',
    ]);

    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'rule_id' => 'analyze',
        'event' => 'push',
        'prompt' => 'Analyze push',
    ]);

    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => ['ref' => 'refs/heads/main'],
        'status' => 'processed',
        'created_at' => now(),
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'analyze',
        'status' => 'queued',
        'created_at' => now(),
    ]);

    $payload = ['ref' => 'refs/heads/main'];

    $job = new ProcessAgentRun($agentRun, $rule, $payload);
    $job->handle();

    $agentRun->refresh();
    expect($agentRun->status)->toBe('success');
});

// --- DispatchAgent Conversational interface ---

test('DispatchAgent implements Conversational interface', function () {
    $agent = new DispatchAgent('System prompt');

    expect($agent)->toBeInstanceOf(Conversational::class)
        ->and(iterator_to_array($agent->messages()))->toBeEmpty();
});

test('DispatchAgent accepts conversation history', function () {
    $agent = new DispatchAgent('System prompt');

    $agent->withConversationHistory([
        ['role' => 'user', 'content' => 'Prior question'],
        ['role' => 'assistant', 'content' => 'Prior answer'],
    ]);

    $messages = iterator_to_array($agent->messages());

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBeInstanceOf(Message::class)
        ->and($messages[1])->toBeInstanceOf(AssistantMessage::class);
});
