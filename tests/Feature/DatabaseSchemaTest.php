<?php

use App\Enums\FilterOperator;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\WebhookLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('projects table has required columns', function () {
    $project = Project::factory()->create([
        'repo' => 'datashaman/sparky-nano',
        'path' => '/home/user/Projects/datashaman/sparky-nano',
    ]);

    expect($project->repo)->toBe('datashaman/sparky-nano')
        ->and($project->path)->toBe('/home/user/Projects/datashaman/sparky-nano');
});

test('project repo is unique', function () {
    Project::factory()->create(['repo' => 'datashaman/sparky-nano']);

    expect(fn () => Project::factory()->create(['repo' => 'datashaman/sparky-nano']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('project has agent config columns', function () {
    $project = Project::factory()->create([
        'agent_name' => 'sparky',
        'agent_executor' => 'laravel-ai',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-6',
        'agent_instructions_file' => 'SPARKY.md',
        'agent_secrets' => ['api_key' => 'ANTHROPIC_API_KEY'],
    ]);

    expect($project->agent_name)->toBe('sparky')
        ->and($project->agent_executor)->toBe('laravel-ai')
        ->and($project->agent_provider)->toBe('anthropic')
        ->and($project->agent_model)->toBe('claude-sonnet-4-6')
        ->and($project->agent_instructions_file)->toBe('SPARKY.md')
        ->and($project->agent_secrets)->toBe(['api_key' => 'ANTHROPIC_API_KEY']);
});

test('filter operator enum has expected values', function () {
    $allowedOperators = array_column(FilterOperator::cases(), 'value');

    expect($allowedOperators)->toBe([
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'starts_with',
        'ends_with',
        'matches',
    ]);
});

test('webhook logs table has required columns', function () {
    $log = WebhookLog::factory()->create([
        'event_type' => 'issues.labeled',
        'repo' => 'datashaman/sparky-nano',
        'payload' => ['action' => 'labeled', 'issue' => ['number' => 1]],
        'matched_rules' => ['analyze'],
        'status' => 'processed',
        'error' => null,
    ]);

    expect($log->event_type)->toBe('issues.labeled')
        ->and($log->repo)->toBe('datashaman/sparky-nano')
        ->and($log->payload)->toBe(['action' => 'labeled', 'issue' => ['number' => 1]])
        ->and($log->matched_rules)->toBe(['analyze'])
        ->and($log->status)->toBe('processed')
        ->and($log->error)->toBeNull();
});

test('webhook log has many agent runs', function () {
    $log = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create(['webhook_log_id' => $log->id]);

    expect($log->agentRuns)->toHaveCount(1)
        ->and($log->agentRuns->first()->id)->toBe($run->id);
});

test('agent runs table has required columns', function () {
    $log = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $log->id,
        'rule_id' => 'analyze',
        'status' => 'success',
        'output' => 'Analysis complete',
        'tokens_used' => 1500,
        'cost' => 0.015000,
        'duration_ms' => 5000,
        'error' => null,
    ]);

    expect($run->rule_id)->toBe('analyze')
        ->and($run->status)->toBe('success')
        ->and($run->output)->toBe('Analysis complete')
        ->and($run->tokens_used)->toBe(1500)
        ->and((float) $run->cost)->toBe(0.015)
        ->and($run->duration_ms)->toBe(5000)
        ->and($run->error)->toBeNull();
});

test('agent run belongs to webhook log', function () {
    $log = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create(['webhook_log_id' => $log->id]);

    expect($run->webhookLog->id)->toBe($log->id);
});

test('factories create valid models', function () {
    $project = Project::factory()->create();
    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create(['webhook_log_id' => $webhookLog->id]);

    expect($project)->toBeInstanceOf(Project::class)
        ->and($webhookLog)->toBeInstanceOf(WebhookLog::class)
        ->and($agentRun)->toBeInstanceOf(AgentRun::class);
});
