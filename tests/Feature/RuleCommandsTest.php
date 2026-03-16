<?php

use App\Models\Project;
use App\Models\Rule;
use App\Models\RuleAgentConfig;
use App\Models\RuleOutputConfig;
use App\Models\RuleRetryConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('dispatch:add-rule', function () {
    test('adds a rule with required arguments', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:add-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            'event' => 'issues.labeled',
        ])
            ->expectsOutput("Rule 'analyze' added to project 'owner/repo'.")
            ->assertSuccessful();

        $this->assertDatabaseHas('rules', [
            'project_id' => $project->id,
            'rule_id' => 'analyze',
            'event' => 'issues.labeled',
            'name' => 'analyze',
            'prompt' => '',
            'circuit_break' => false,
            'sort_order' => 0,
        ]);
    });

    test('adds a rule with all options', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:add-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'review',
            'event' => 'pull_request.opened',
            '--name' => 'Code Review',
            '--prompt' => 'Review this PR: {{ event.pull_request.title }}',
            '--circuit-break' => true,
            '--sort-order' => '5',
        ])
            ->expectsOutput("Rule 'review' added to project 'owner/repo'.")
            ->assertSuccessful();

        $this->assertDatabaseHas('rules', [
            'rule_id' => 'review',
            'name' => 'Code Review',
            'event' => 'pull_request.opened',
            'prompt' => 'Review this PR: {{ event.pull_request.title }}',
            'circuit_break' => true,
            'sort_order' => 5,
        ]);
    });

    test('fails if project does not exist', function () {
        $this->artisan('dispatch:add-rule', [
            'repo' => 'owner/nonexistent',
            'rule_id' => 'analyze',
            'event' => 'issues.labeled',
        ])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });

    test('fails if rule_id already exists for project', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:add-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            'event' => 'issues.labeled',
        ])
            ->expectsOutput("Rule 'analyze' already exists for project 'owner/repo'.")
            ->assertFailed();
    });
});

describe('dispatch:update-rule', function () {
    test('updates rule fields via options', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'analyze',
            'name' => 'Old Name',
            'event' => 'issues.labeled',
            'prompt' => 'Old prompt',
            'circuit_break' => false,
            'sort_order' => 0,
        ]);

        $this->artisan('dispatch:update-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            '--name' => 'New Name',
            '--event' => 'issues.opened',
            '--prompt' => 'New prompt',
            '--circuit-break' => 'true',
            '--sort-order' => '10',
        ])
            ->expectsOutput("Rule 'analyze' updated for project 'owner/repo'.")
            ->assertSuccessful();

        $this->assertDatabaseHas('rules', [
            'rule_id' => 'analyze',
            'name' => 'New Name',
            'event' => 'issues.opened',
            'prompt' => 'New prompt',
            'circuit_break' => true,
            'sort_order' => 10,
        ]);
    });

    test('updates only provided options', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'analyze',
            'name' => 'Original Name',
            'event' => 'issues.labeled',
        ]);

        $this->artisan('dispatch:update-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            '--name' => 'Updated Name',
        ])
            ->assertSuccessful();

        $this->assertDatabaseHas('rules', [
            'rule_id' => 'analyze',
            'name' => 'Updated Name',
            'event' => 'issues.labeled',
        ]);
    });

    test('warns when no options provided', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:update-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
        ])
            ->expectsOutput('No options provided. Nothing to update.')
            ->assertSuccessful();
    });

    test('fails if project does not exist', function () {
        $this->artisan('dispatch:update-rule', [
            'repo' => 'owner/nonexistent',
            'rule_id' => 'analyze',
            '--name' => 'New Name',
        ])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });

    test('fails if rule does not exist', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:update-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'nonexistent',
            '--name' => 'New Name',
        ])
            ->expectsOutput("Rule 'nonexistent' does not exist for project 'owner/repo'.")
            ->assertFailed();
    });
});

describe('dispatch:remove-rule', function () {
    test('removes a rule with confirmation', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:remove-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
        ])
            ->expectsConfirmation("Are you sure you want to remove rule 'analyze' and all its associated configs?", 'yes')
            ->expectsOutput("Rule 'analyze' removed from project 'owner/repo'.")
            ->assertSuccessful();

        $this->assertDatabaseMissing('rules', ['id' => $rule->id]);
    });

    test('deletes associated filters and configs on removal', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);
        RuleAgentConfig::factory()->create(['rule_id' => $rule->id]);
        RuleOutputConfig::factory()->create(['rule_id' => $rule->id]);
        RuleRetryConfig::factory()->create(['rule_id' => $rule->id]);
        $rule->filters()->create([
            'filter_id' => 'f1',
            'field' => 'action',
            'operator' => 'equals',
            'value' => 'labeled',
            'sort_order' => 0,
        ]);

        $this->artisan('dispatch:remove-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
        ])
            ->expectsConfirmation("Are you sure you want to remove rule 'analyze' and all its associated configs?", 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('rules', ['id' => $rule->id]);
        $this->assertDatabaseMissing('rule_agent_configs', ['rule_id' => $rule->id]);
        $this->assertDatabaseMissing('rule_output_configs', ['rule_id' => $rule->id]);
        $this->assertDatabaseMissing('rule_retry_configs', ['rule_id' => $rule->id]);
        $this->assertDatabaseMissing('filters', ['rule_id' => $rule->id]);
    });

    test('cancels removal when confirmation is denied', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:remove-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
        ])
            ->expectsConfirmation("Are you sure you want to remove rule 'analyze' and all its associated configs?", 'no')
            ->expectsOutput('Operation cancelled.')
            ->assertSuccessful();

        $this->assertDatabaseHas('rules', ['rule_id' => 'analyze']);
    });

    test('fails if project does not exist', function () {
        $this->artisan('dispatch:remove-rule', [
            'repo' => 'owner/nonexistent',
            'rule_id' => 'analyze',
        ])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });

    test('fails if rule does not exist', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:remove-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'nonexistent',
        ])
            ->expectsOutput("Rule 'nonexistent' does not exist for project 'owner/repo'.")
            ->assertFailed();
    });
});

describe('dispatch:list-rules', function () {
    test('lists rules ordered by sort_order', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'review',
            'name' => 'Code Review',
            'event' => 'pull_request.opened',
            'circuit_break' => true,
            'sort_order' => 2,
        ]);
        Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'analyze',
            'name' => 'Analyze Issue',
            'event' => 'issues.labeled',
            'circuit_break' => false,
            'sort_order' => 1,
        ]);

        $this->artisan('dispatch:list-rules', ['repo' => 'owner/repo'])
            ->expectsTable(
                ['Rule ID', 'Name', 'Event', 'Circuit Break', 'Sort Order'],
                [
                    ['analyze', 'Analyze Issue', 'issues.labeled', 'No', 1],
                    ['review', 'Code Review', 'pull_request.opened', 'Yes', 2],
                ],
            )
            ->assertSuccessful();
    });

    test('shows message when no rules exist', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:list-rules', ['repo' => 'owner/repo'])
            ->expectsOutput("No rules found for project 'owner/repo'.")
            ->assertSuccessful();
    });

    test('fails if project does not exist', function () {
        $this->artisan('dispatch:list-rules', ['repo' => 'owner/nonexistent'])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });
});
