<?php

use App\Enums\FilterOperator;
use App\Exceptions\RuleMatchingException;
use App\Models\Filter;
use App\Models\Project;
use App\Models\Rule;
use App\Models\WebhookLog;
use App\Services\RuleMatchingEngine;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->engine = new RuleMatchingEngine;
    config(['services.github.verify_webhook_signature' => false]);
});

it('throws exception when project not found', function () {
    $this->engine->match('nonexistent/repo', 'issues.labeled', []);
})->throws(RuleMatchingException::class, "Project not found for repo 'nonexistent/repo'");

it('returns empty collection when no rules match event type', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    Rule::factory()->create(['project_id' => $project->id, 'event' => 'push']);

    $result = $this->engine->match('owner/repo', 'issues.labeled', []);

    expect($result)->toBeEmpty();
});

it('matches rule by exact event type', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issues.labeled',
        'rule_id' => 'analyze',
    ]);

    $result = $this->engine->match('owner/repo', 'issues.labeled', []);

    expect($result)->toHaveCount(1)
        ->and($result->first()->rule_id)->toBe('analyze');
});

it('returns multiple matching rules in sort_order', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issues.labeled',
        'rule_id' => 'second',
        'sort_order' => 2,
    ]);
    Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issues.labeled',
        'rule_id' => 'first',
        'sort_order' => 1,
    ]);

    $result = $this->engine->match('owner/repo', 'issues.labeled', []);

    expect($result)->toHaveCount(2)
        ->and($result[0]->rule_id)->toBe('first')
        ->and($result[1]->rule_id)->toBe('second');
});

it('matches rule with no filters', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'push',
        'rule_id' => 'no-filters',
    ]);

    $result = $this->engine->match('owner/repo', 'push', []);

    expect($result)->toHaveCount(1);
});

it('filters using equals operator', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issues.labeled',
        'rule_id' => 'labeled',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'label.name',
        'operator' => FilterOperator::Equals,
        'value' => 'sparky',
    ]);

    $match = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'sparky']]);
    $noMatch = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'other']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using not_equals operator', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issues.labeled',
        'rule_id' => 'not-bug',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'label.name',
        'operator' => FilterOperator::NotEquals,
        'value' => 'bug',
    ]);

    $match = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'feature']]);
    $noMatch = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'bug']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using contains operator', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issue_comment.created',
        'rule_id' => 'mention',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'comment.body',
        'operator' => FilterOperator::Contains,
        'value' => '@sparky',
    ]);

    $match = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'Hey @sparky please review']]);
    $noMatch = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'Just a comment']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using not_contains operator', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issue_comment.created',
        'rule_id' => 'no-skip',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'comment.body',
        'operator' => FilterOperator::NotContains,
        'value' => '[skip]',
    ]);

    $match = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'Normal comment']]);
    $noMatch = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'Comment [skip] this']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using starts_with operator', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issue_comment.created',
        'rule_id' => 'command',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'comment.body',
        'operator' => FilterOperator::StartsWith,
        'value' => '/deploy',
    ]);

    $match = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => '/deploy production']]);
    $noMatch = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'Please /deploy']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using ends_with operator', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issues.labeled',
        'rule_id' => 'priority',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'label.name',
        'operator' => FilterOperator::EndsWith,
        'value' => '-priority',
    ]);

    $match = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'high-priority']]);
    $noMatch = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'priority-low']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using matches (regex) operator', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issue_comment.created',
        'rule_id' => 'version',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'comment.body',
        'operator' => FilterOperator::Matches,
        'value' => '/^v\d+\.\d+\.\d+$/',
    ]);

    $match = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'v1.2.3']]);
    $noMatch = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'version 1.2.3']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('requires all filters to pass (AND logic)', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issue_comment.created',
        'rule_id' => 'implement',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'comment.body',
        'operator' => FilterOperator::Contains,
        'value' => '@sparky',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'comment.body',
        'operator' => FilterOperator::Contains,
        'value' => 'implement',
    ]);

    $match = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => '@sparky implement this']]);
    $partialMatch = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => '@sparky review this']]);

    expect($match)->toHaveCount(1)
        ->and($partialMatch)->toBeEmpty();
});

it('resolves dot-path fields with event prefix', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issues.labeled',
        'rule_id' => 'with-prefix',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'event.label.name',
        'operator' => FilterOperator::Equals,
        'value' => 'sparky',
    ]);

    $result = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'sparky']]);

    expect($result)->toHaveCount(1);
});

it('resolves deeply nested dot-path fields', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $rule = Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issues.opened',
        'rule_id' => 'nested',
    ]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'issue.user.login',
        'operator' => FilterOperator::Equals,
        'value' => 'octocat',
    ]);

    $result = $this->engine->match('owner/repo', 'issues.opened', ['issue' => ['user' => ['login' => 'octocat']]]);

    expect($result)->toHaveCount(1);
});

it('treats missing field path as empty string', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'push',
        'rule_id' => 'missing-field',
    ]);
    Filter::factory()->create([
        'rule_id' => Rule::where('rule_id', 'missing-field')->first()->id,
        'field' => 'nonexistent.path',
        'operator' => FilterOperator::Equals,
        'value' => 'something',
    ]);

    $result = $this->engine->match('owner/repo', 'push', ['other' => 'data']);

    expect($result)->toBeEmpty();
});

it('does not match rules from other projects', function () {
    Project::factory()->create(['repo' => 'owner/repo1']);
    $project2 = Project::factory()->create(['repo' => 'owner/repo2']);
    Rule::factory()->create([
        'project_id' => $project2->id,
        'event' => 'push',
        'rule_id' => 'other-project',
    ]);

    $result = $this->engine->match('owner/repo1', 'push', []);

    expect($result)->toBeEmpty();
});

it('integrates with webhook controller for missing project', function () {
    $payload = [
        'action' => 'labeled',
        'repository' => ['full_name' => 'nonexistent/repo'],
        'label' => ['name' => 'sparky'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'error' => "Project not found for repo 'nonexistent/repo'",
        ]);
});

it('integrates with webhook controller for matching rules', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issues.labeled',
        'rule_id' => 'analyze',
    ]);

    $payload = [
        'action' => 'labeled',
        'repository' => ['full_name' => 'owner/repo'],
        'label' => ['name' => 'sparky'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'event' => 'issues.labeled',
            'matched' => 1,
        ]);
});

it('integrates with webhook controller for no matching rules', function () {
    Project::factory()->create(['repo' => 'owner/repo']);

    $payload = [
        'action' => 'opened',
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'event' => 'issues.opened',
            'matched' => 0,
        ]);
});

it('logs error when project not found', function () {
    Log::shouldReceive('error')
        ->once()
        ->with("Rule matching: project not found for repo 'nonexistent/repo'");

    expect(fn () => $this->engine->match('nonexistent/repo', 'push', []))
        ->toThrow(RuleMatchingException::class);
});

it('updates webhook log with matched rules and processed status', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    Rule::factory()->create([
        'project_id' => $project->id,
        'event' => 'issues.labeled',
        'rule_id' => 'analyze',
    ]);

    $payload = [
        'action' => 'labeled',
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $log = WebhookLog::latest('id')->first();
    expect($log->status)->toBe('processed')
        ->and($log->matched_rules)->toBe(['analyze']);
});
