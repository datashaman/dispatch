<?php

use App\Enums\FilterOperator;
use App\Models\Filter;
use App\Models\Project;
use App\Models\Rule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('dispatch:add-filter', function () {
    test('adds a filter with all options', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:add-filter', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            '--filter-id' => 'label-check',
            '--field' => 'event.label.name',
            '--operator' => 'equals',
            '--value' => 'sparky',
            '--sort-order' => '1',
        ])
            ->expectsOutput("Filter added to rule 'analyze' on project 'owner/repo'.")
            ->assertSuccessful();

        $this->assertDatabaseHas('filters', [
            'rule_id' => $rule->id,
            'filter_id' => 'label-check',
            'field' => 'event.label.name',
            'operator' => 'equals',
            'value' => 'sparky',
            'sort_order' => 1,
        ]);
    });

    test('adds a filter without filter-id', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:add-filter', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            '--field' => 'event.action',
            '--operator' => 'contains',
            '--value' => 'opened',
        ])
            ->assertSuccessful();

        $this->assertDatabaseHas('filters', [
            'filter_id' => null,
            'field' => 'event.action',
            'operator' => 'contains',
            'value' => 'opened',
        ]);
    });

    test('fails if project does not exist', function () {
        $this->artisan('dispatch:add-filter', [
            'repo' => 'owner/nonexistent',
            'rule_id' => 'analyze',
            '--field' => 'event.label.name',
            '--operator' => 'equals',
            '--value' => 'sparky',
        ])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });

    test('fails if rule does not exist', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:add-filter', [
            'repo' => 'owner/repo',
            'rule_id' => 'nonexistent',
            '--field' => 'event.label.name',
            '--operator' => 'equals',
            '--value' => 'sparky',
        ])
            ->expectsOutput("Rule 'nonexistent' does not exist for project 'owner/repo'.")
            ->assertFailed();
    });

    test('fails if required options are missing', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:add-filter', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
        ])
            ->expectsOutput('The --field, --operator, and --value options are required.')
            ->assertFailed();
    });

    test('fails with invalid operator', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:add-filter', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            '--field' => 'event.label.name',
            '--operator' => 'invalid_op',
            '--value' => 'sparky',
        ])
            ->expectsOutput("Invalid operator 'invalid_op'. Allowed: equals, not_equals, contains, not_contains, starts_with, ends_with, matches")
            ->assertFailed();
    });

    test('validates all allowed operators', function (string $operator) {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:add-filter', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            '--field' => 'event.label.name',
            '--operator' => $operator,
            '--value' => 'test',
        ])
            ->assertSuccessful();

        $this->assertDatabaseHas('filters', [
            'operator' => $operator,
        ]);
    })->with([
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'starts_with',
        'ends_with',
        'matches',
    ]);
});

describe('dispatch:remove-filter', function () {
    test('removes a filter with confirmation', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);
        Filter::factory()->create(['rule_id' => $rule->id, 'filter_id' => 'label-check']);

        $this->artisan('dispatch:remove-filter', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            'filter_id' => 'label-check',
        ])
            ->expectsConfirmation("Are you sure you want to remove filter 'label-check' from rule 'analyze'?", 'yes')
            ->expectsOutput("Filter 'label-check' removed from rule 'analyze' on project 'owner/repo'.")
            ->assertSuccessful();

        $this->assertDatabaseMissing('filters', ['filter_id' => 'label-check']);
    });

    test('cancels removal when confirmation denied', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);
        Filter::factory()->create(['rule_id' => $rule->id, 'filter_id' => 'label-check']);

        $this->artisan('dispatch:remove-filter', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            'filter_id' => 'label-check',
        ])
            ->expectsConfirmation("Are you sure you want to remove filter 'label-check' from rule 'analyze'?", 'no')
            ->expectsOutput('Cancelled.')
            ->assertSuccessful();

        $this->assertDatabaseHas('filters', ['filter_id' => 'label-check']);
    });

    test('fails if project does not exist', function () {
        $this->artisan('dispatch:remove-filter', [
            'repo' => 'owner/nonexistent',
            'rule_id' => 'analyze',
            'filter_id' => 'label-check',
        ])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });

    test('fails if rule does not exist', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:remove-filter', [
            'repo' => 'owner/repo',
            'rule_id' => 'nonexistent',
            'filter_id' => 'label-check',
        ])
            ->expectsOutput("Rule 'nonexistent' does not exist for project 'owner/repo'.")
            ->assertFailed();
    });

    test('fails if filter does not exist', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:remove-filter', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
            'filter_id' => 'nonexistent',
        ])
            ->expectsOutput("Filter 'nonexistent' does not exist for rule 'analyze'.")
            ->assertFailed();
    });
});

describe('dispatch:list-filters', function () {
    test('lists filters for a rule', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);
        $filter = Filter::factory()->create([
            'rule_id' => $rule->id,
            'filter_id' => 'label-check',
            'field' => 'event.label.name',
            'operator' => FilterOperator::Equals,
            'value' => 'sparky',
            'sort_order' => 0,
        ]);

        $this->artisan('dispatch:list-filters', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
        ])
            ->expectsTable(
                ['ID', 'Filter ID', 'Field', 'Operator', 'Value', 'Sort Order'],
                [
                    [$filter->id, 'label-check', 'event.label.name', 'equals', 'sparky', 0],
                ]
            )
            ->assertSuccessful();
    });

    test('shows message when no filters exist', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'analyze']);

        $this->artisan('dispatch:list-filters', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
        ])
            ->expectsOutput("No filters found for rule 'analyze'.")
            ->assertSuccessful();
    });

    test('fails if project does not exist', function () {
        $this->artisan('dispatch:list-filters', [
            'repo' => 'owner/nonexistent',
            'rule_id' => 'analyze',
        ])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });

    test('fails if rule does not exist', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:list-filters', [
            'repo' => 'owner/repo',
            'rule_id' => 'nonexistent',
        ])
            ->expectsOutput("Rule 'nonexistent' does not exist for project 'owner/repo'.")
            ->assertFailed();
    });
});
