<?php

use App\Enums\FilterOperator;
use App\Models\AgentRun;
use App\Models\Filter;
use App\Models\Project;
use App\Models\Rule;
use App\Models\RuleAgentConfig;
use App\Models\RuleOutputConfig;
use App\Models\RuleRetryConfig;
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

test('project has many rules', function () {
    $project = Project::factory()->create();
    $rule = Rule::factory()->create(['project_id' => $project->id]);

    expect($project->rules)->toHaveCount(1)
        ->and($project->rules->first()->id)->toBe($rule->id);
});

test('rules table has required columns', function () {
    $project = Project::factory()->create();
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'rule_id' => 'analyze',
        'name' => 'Analyze Issue',
        'event' => 'issues.labeled',
        'circuit_break' => true,
        'prompt' => 'Analyze this issue',
        'sort_order' => 1,
    ]);

    expect($rule->rule_id)->toBe('analyze')
        ->and($rule->name)->toBe('Analyze Issue')
        ->and($rule->event)->toBe('issues.labeled')
        ->and($rule->circuit_break)->toBeTrue()
        ->and($rule->prompt)->toBe('Analyze this issue')
        ->and($rule->sort_order)->toBe(1);
});

test('rule belongs to project', function () {
    $project = Project::factory()->create();
    $rule = Rule::factory()->create(['project_id' => $project->id]);

    expect($rule->project->id)->toBe($project->id);
});

test('rule has one agent config', function () {
    $rule = Rule::factory()->create();
    $agentConfig = RuleAgentConfig::factory()->create([
        'rule_id' => $rule->id,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 4096,
        'tools' => ['read', 'glob', 'grep'],
        'disallowed_tools' => ['edit', 'write'],
        'isolation' => true,
    ]);

    $rule->refresh();

    expect($rule->agentConfig->id)->toBe($agentConfig->id)
        ->and($rule->agentConfig->provider)->toBe('anthropic')
        ->and($rule->agentConfig->model)->toBe('claude-sonnet-4-6')
        ->and($rule->agentConfig->max_tokens)->toBe(4096)
        ->and($rule->agentConfig->tools)->toBe(['read', 'glob', 'grep'])
        ->and($rule->agentConfig->disallowed_tools)->toBe(['edit', 'write'])
        ->and($rule->agentConfig->isolation)->toBeTrue();
});

test('rule has one output config', function () {
    $rule = Rule::factory()->create();
    $outputConfig = RuleOutputConfig::factory()->create([
        'rule_id' => $rule->id,
        'log' => true,
        'github_comment' => true,
        'github_reaction' => 'eyes',
    ]);

    $rule->refresh();

    expect($rule->outputConfig->id)->toBe($outputConfig->id)
        ->and($rule->outputConfig->log)->toBeTrue()
        ->and($rule->outputConfig->github_comment)->toBeTrue()
        ->and($rule->outputConfig->github_reaction)->toBe('eyes');
});

test('rule has one retry config', function () {
    $rule = Rule::factory()->create();
    $retryConfig = RuleRetryConfig::factory()->create([
        'rule_id' => $rule->id,
        'enabled' => true,
        'max_attempts' => 5,
        'delay' => 120,
    ]);

    $rule->refresh();

    expect($rule->retryConfig->id)->toBe($retryConfig->id)
        ->and($rule->retryConfig->enabled)->toBeTrue()
        ->and($rule->retryConfig->max_attempts)->toBe(5)
        ->and($rule->retryConfig->delay)->toBe(120);
});

test('rule has many filters', function () {
    $rule = Rule::factory()->create();
    $filter = Filter::factory()->create([
        'rule_id' => $rule->id,
        'filter_id' => 'filter-1',
        'field' => 'event.label.name',
        'operator' => FilterOperator::Equals,
        'value' => 'sparky',
        'sort_order' => 0,
    ]);

    expect($rule->filters)->toHaveCount(1)
        ->and($rule->filters->first()->id)->toBe($filter->id);
});

test('filter operator is cast to FilterOperator enum', function () {
    $filter = Filter::factory()->create([
        'operator' => FilterOperator::Contains,
    ]);

    expect($filter->operator)->toBe(FilterOperator::Contains);
});

test('filter operator validates against allowed set', function () {
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

test('deleting a project cascades to rules', function () {
    $project = Project::factory()->create();
    $rule = Rule::factory()->create(['project_id' => $project->id]);

    $project->delete();

    expect(Rule::find($rule->id))->toBeNull();
});

test('deleting a rule cascades to related configs and filters', function () {
    $rule = Rule::factory()->create();
    RuleAgentConfig::factory()->create(['rule_id' => $rule->id]);
    RuleOutputConfig::factory()->create(['rule_id' => $rule->id]);
    RuleRetryConfig::factory()->create(['rule_id' => $rule->id]);
    Filter::factory()->create(['rule_id' => $rule->id]);

    $rule->delete();

    expect(RuleAgentConfig::where('rule_id', $rule->id)->count())->toBe(0)
        ->and(RuleOutputConfig::where('rule_id', $rule->id)->count())->toBe(0)
        ->and(RuleRetryConfig::where('rule_id', $rule->id)->count())->toBe(0)
        ->and(Filter::where('rule_id', $rule->id)->count())->toBe(0);
});

test('rules are ordered by sort_order on project relationship', function () {
    $project = Project::factory()->create();
    Rule::factory()->create(['project_id' => $project->id, 'sort_order' => 2, 'rule_id' => 'second']);
    Rule::factory()->create(['project_id' => $project->id, 'sort_order' => 1, 'rule_id' => 'first']);
    Rule::factory()->create(['project_id' => $project->id, 'sort_order' => 3, 'rule_id' => 'third']);

    $project->refresh();

    expect($project->rules->pluck('rule_id')->toArray())->toBe(['first', 'second', 'third']);
});

test('factories create valid models', function () {
    $project = Project::factory()->create();
    $rule = Rule::factory()->create(['project_id' => $project->id]);
    $agentConfig = RuleAgentConfig::factory()->create(['rule_id' => $rule->id]);
    $outputConfig = RuleOutputConfig::factory()->create(['rule_id' => $rule->id]);
    $retryConfig = RuleRetryConfig::factory()->create(['rule_id' => $rule->id]);
    $filter = Filter::factory()->create(['rule_id' => $rule->id]);
    $webhookLog = WebhookLog::factory()->create();
    $agentRun = AgentRun::factory()->create(['webhook_log_id' => $webhookLog->id]);

    expect($project)->toBeInstanceOf(Project::class)
        ->and($rule)->toBeInstanceOf(Rule::class)
        ->and($agentConfig)->toBeInstanceOf(RuleAgentConfig::class)
        ->and($outputConfig)->toBeInstanceOf(RuleOutputConfig::class)
        ->and($retryConfig)->toBeInstanceOf(RuleRetryConfig::class)
        ->and($filter)->toBeInstanceOf(Filter::class)
        ->and($webhookLog)->toBeInstanceOf(WebhookLog::class)
        ->and($agentRun)->toBeInstanceOf(AgentRun::class);
});
