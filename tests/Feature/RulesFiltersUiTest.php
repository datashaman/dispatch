<?php

use App\Enums\FilterOperator;
use App\Models\Filter;
use App\Models\Project;
use App\Models\Rule;
use App\Models\RuleAgentConfig;
use App\Models\RuleOutputConfig;
use App\Models\RuleRetryConfig;
use App\Models\User;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create(['repo' => 'owner/test-repo', 'path' => '/tmp/test']);
});

test('rules page requires authentication', function () {
    auth()->logout();

    $this->get(route('rules.index', $this->project))
        ->assertRedirect(route('login'));
});

test('rules page is accessible with project', function () {
    $this->get(route('rules.index', $this->project))
        ->assertStatus(200);
});

test('lists rules ordered by sort_order', function () {
    Rule::factory()->create(['project_id' => $this->project->id, 'rule_id' => 'second-rule', 'event' => 'issues.opened', 'sort_order' => 2]);
    Rule::factory()->create(['project_id' => $this->project->id, 'rule_id' => 'first-rule', 'event' => 'issues.labeled', 'sort_order' => 1]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->assertSee('first-rule')
        ->assertSee('second-rule')
        ->assertSee('an issue is labeled')
        ->assertSee('an issue is opened');
});

test('shows empty state when no rules exist', function () {
    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->assertSee('No rules configured');
});

test('can create a new rule', function () {
    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openAddRule')
        ->set('ruleName', 'Analyze Issues')
        ->assertSet('ruleId', 'analyze-issues')
        ->set('ruleEvent', 'issues.labeled')
        ->set('rulePrompt', 'Analyze issue #{{ event.issue.number }}')
        ->set('ruleSortOrder', 1)
        ->call('saveRule');

    $rule = Rule::where('rule_id', 'analyze-issues')->first();
    expect($rule)->not->toBeNull();
    expect($rule->name)->toBe('Analyze Issues');
    expect($rule->event)->toBe('issues.labeled');
    expect($rule->prompt)->toBe('Analyze issue #{{ event.issue.number }}');
    expect($rule->sort_order)->toBe(1);
});

test('validates required fields when creating rule', function () {
    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openAddRule')
        ->set('ruleId', '')
        ->set('ruleEvent', '')
        ->call('saveRule')
        ->assertHasErrors(['ruleId', 'ruleEvent']);
});

test('validates unique rule_id per project', function () {
    Rule::factory()->create(['project_id' => $this->project->id, 'rule_id' => 'existing']);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openAddRule')
        ->set('ruleId', 'existing')
        ->set('ruleEvent', 'issues.opened')
        ->call('saveRule')
        ->assertHasErrors('ruleId');
});

test('can edit a rule', function () {
    $rule = Rule::factory()->create([
        'project_id' => $this->project->id,
        'rule_id' => 'analyze',
        'name' => 'Old Name',
        'event' => 'issues.labeled',
    ]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openEditRule', $rule->id)
        ->set('ruleName', 'New Name')
        ->set('ruleEvent', 'issues.opened')
        ->call('saveRule');

    $rule->refresh();
    expect($rule->name)->toBe('New Name');
    expect($rule->event)->toBe('issues.opened');
});

test('can delete a rule', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id, 'rule_id' => 'to-delete']);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('deleteRule', $rule->id);

    expect(Rule::find($rule->id))->toBeNull();
});

test('shows continue on error badge', function () {
    Rule::factory()->create([
        'project_id' => $this->project->id,
        'rule_id' => 'breaker',
        'event' => 'issues.labeled',
        'continue_on_error' => true,
    ]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->assertSee('Continue on error');
});

// --- Filter tests ---

test('can add a filter to a rule', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openAddFilter', $rule->id)
        ->set('filterField', 'event.label.name')
        ->set('filterOperator', 'equals')
        ->set('filterValue', 'sparky')
        ->call('saveFilter');

    $filter = Filter::where('rule_id', $rule->id)->first();
    expect($filter)->not->toBeNull();
    expect($filter->field)->toBe('event.label.name');
    expect($filter->operator)->toBe(FilterOperator::Equals);
    expect($filter->value)->toBe('sparky');
});

test('validates filter required fields', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openAddFilter', $rule->id)
        ->set('filterField', '')
        ->set('filterValue', '')
        ->call('saveFilter')
        ->assertHasErrors(['filterField', 'filterValue']);
});

test('validates filter operator against allowed set', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openAddFilter', $rule->id)
        ->set('filterField', 'event.label.name')
        ->set('filterOperator', 'invalid_op')
        ->set('filterValue', 'test')
        ->call('saveFilter')
        ->assertHasErrors('filterOperator');
});

test('can edit a filter', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id]);
    $filter = Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'event.label.name',
        'operator' => FilterOperator::Equals,
        'value' => 'old-value',
    ]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openEditFilter', $filter->id)
        ->set('filterValue', 'new-value')
        ->set('filterOperator', 'contains')
        ->call('saveFilter');

    $filter->refresh();
    expect($filter->value)->toBe('new-value');
    expect($filter->operator)->toBe(FilterOperator::Contains);
});

test('can delete a filter', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id]);
    $filter = Filter::factory()->create(['rule_id' => $rule->id]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('deleteFilter', $filter->id);

    expect(Filter::find($filter->id))->toBeNull();
});

test('displays filters for a rule', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id]);
    Filter::factory()->create([
        'rule_id' => $rule->id,
        'field' => 'event.label.name',
        'operator' => FilterOperator::Equals,
        'value' => 'sparky',
    ]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->assertSee('event.label.name')
        ->assertSee('equals')
        ->assertSee('sparky');
});

// --- Agent Config tests ---

test('can save agent config for a rule', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openAgentConfig', $rule->id)
        ->set('agentProvider', 'anthropic')
        ->set('agentModel', 'claude-sonnet-4-6')
        ->set('agentTools', 'Read, Edit, Write')
        ->set('agentIsolation', true)
        ->call('saveAgentConfig');

    $config = RuleAgentConfig::where('rule_id', $rule->id)->first();
    expect($config)->not->toBeNull();
    expect($config->provider)->toBe('anthropic');
    expect($config->model)->toBe('claude-sonnet-4-6');
    expect($config->tools)->toBe(['Read', 'Edit', 'Write']);
    expect($config->isolation)->toBeTrue();
});

test('can update existing agent config', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id]);
    RuleAgentConfig::factory()->create(['rule_id' => $rule->id, 'provider' => 'openai']);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openAgentConfig', $rule->id)
        ->set('agentProvider', 'anthropic')
        ->call('saveAgentConfig');

    expect(RuleAgentConfig::where('rule_id', $rule->id)->first()->provider)->toBe('anthropic');
});

// --- Output Config tests ---

test('can save output config for a rule', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openOutputConfig', $rule->id)
        ->set('outputLog', true)
        ->set('outputGithubComment', true)
        ->set('outputGithubReaction', 'rocket')
        ->call('saveOutputConfig');

    $config = RuleOutputConfig::where('rule_id', $rule->id)->first();
    expect($config)->not->toBeNull();
    expect($config->log)->toBeTrue();
    expect($config->github_comment)->toBeTrue();
    expect($config->github_reaction)->toBe('rocket');
});

// --- Retry Config tests ---

test('can save retry config for a rule', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openRetryConfig', $rule->id)
        ->set('retryEnabled', true)
        ->set('retryMaxAttempts', 5)
        ->set('retryDelay', 120)
        ->call('saveRetryConfig');

    $config = RuleRetryConfig::where('rule_id', $rule->id)->first();
    expect($config)->not->toBeNull();
    expect($config->enabled)->toBeTrue();
    expect($config->max_attempts)->toBe(5);
    expect($config->delay)->toBe(120);
});

// --- Prompt Preview tests ---

test('can open prompt preview with template variables', function () {
    $rule = Rule::factory()->create([
        'project_id' => $this->project->id,
        'rule_id' => 'analyze',
        'prompt' => 'Analyze issue #{{ event.issue.number }}: {{ event.issue.title }}',
    ]);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openPromptPreview', $rule->id)
        ->assertSet('showPromptPreview', true)
        ->assertSet('previewRuleId', $rule->id);
});

test('getTemplateVariables extracts variables from prompt', function () {
    $component = Volt::test('pages::rules.index', ['project' => $this->project->id]);

    $vars = $component->instance()->getTemplateVariables(
        'Issue #{{ event.issue.number }} by {{ event.issue.user.login }}'
    );

    expect($vars)->toContain('issue.number');
    expect($vars)->toContain('issue.user.login');
});

// --- Status messages ---

test('shows status message after rule creation', function () {
    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('openAddRule')
        ->set('ruleId', 'new-rule')
        ->set('ruleEvent', 'issues.opened')
        ->call('saveRule')
        ->assertSet('statusMessage', "Rule 'new-rule' created.");
});

test('shows status message after rule deletion', function () {
    $rule = Rule::factory()->create(['project_id' => $this->project->id, 'rule_id' => 'to-delete']);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->call('deleteRule', $rule->id)
        ->assertSet('statusMessage', "Rule 'to-delete' deleted.");
});

test('shows project repo in header', function () {
    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->assertSee('owner/test-repo');
});

// --- Cross-project isolation ---

test('rules from other projects are not shown', function () {
    $otherProject = Project::factory()->create(['repo' => 'other/repo']);
    Rule::factory()->create(['project_id' => $otherProject->id, 'rule_id' => 'other-rule', 'event' => 'push']);
    Rule::factory()->create(['project_id' => $this->project->id, 'rule_id' => 'my-rule', 'event' => 'issues.opened']);

    Volt::test('pages::rules.index', ['project' => $this->project->id])
        ->assertSee('my-rule')
        ->assertDontSee('other-rule');
});
