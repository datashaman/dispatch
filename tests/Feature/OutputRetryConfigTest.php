<?php

use App\Models\Project;
use App\Models\Rule;
use App\Models\RuleOutputConfig;
use App\Models\RuleRetryConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('dispatch:configure-output', function () {
    it('creates output config for a rule', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);

        $this->artisan('dispatch:configure-output', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
            '--log' => 'true',
            '--github-comment' => 'true',
            '--github-reaction' => 'eyes',
        ])
            ->expectsOutput("Output config updated for rule 'test-rule' on project 'owner/repo'.")
            ->assertSuccessful();

        $config = $rule->outputConfig;
        expect($config)->not->toBeNull();
        expect($config->log)->toBeTrue();
        expect($config->github_comment)->toBeTrue();
        expect($config->github_reaction)->toBe('eyes');
    });

    it('updates existing output config', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);
        RuleOutputConfig::factory()->create([
            'rule_id' => $rule->id,
            'log' => true,
            'github_comment' => false,
            'github_reaction' => null,
        ]);

        $this->artisan('dispatch:configure-output', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
            '--github-comment' => 'true',
            '--github-reaction' => 'rocket',
        ])
            ->assertSuccessful();

        $config = $rule->fresh()->outputConfig;
        expect($config->log)->toBeTrue();
        expect($config->github_comment)->toBeTrue();
        expect($config->github_reaction)->toBe('rocket');
    });

    it('can disable logging', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);

        $this->artisan('dispatch:configure-output', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
            '--log' => 'false',
        ])
            ->assertSuccessful();

        $config = $rule->outputConfig;
        expect($config->log)->toBeFalse();
    });

    it('warns when no options provided', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);

        $this->artisan('dispatch:configure-output', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
        ])
            ->expectsOutput('No options provided. Nothing to configure.')
            ->assertSuccessful();
    });

    it('fails if project does not exist', function () {
        $this->artisan('dispatch:configure-output', [
            'repo' => 'owner/nonexistent',
            'rule_id' => 'test-rule',
            '--log' => 'true',
        ])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });

    it('fails if rule does not exist', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:configure-output', [
            'repo' => 'owner/repo',
            'rule_id' => 'nonexistent',
            '--log' => 'true',
        ])
            ->expectsOutput("Rule 'nonexistent' does not exist for project 'owner/repo'.")
            ->assertFailed();
    });
});

describe('dispatch:configure-retry', function () {
    it('creates retry config for a rule', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);

        $this->artisan('dispatch:configure-retry', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
            '--enabled' => 'true',
            '--max-attempts' => '5',
            '--delay' => '120',
        ])
            ->expectsOutput("Retry config updated for rule 'test-rule' on project 'owner/repo'.")
            ->assertSuccessful();

        $config = $rule->retryConfig;
        expect($config)->not->toBeNull();
        expect($config->enabled)->toBeTrue();
        expect($config->max_attempts)->toBe(5);
        expect($config->delay)->toBe(120);
    });

    it('updates existing retry config', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);
        RuleRetryConfig::factory()->create([
            'rule_id' => $rule->id,
            'enabled' => false,
            'max_attempts' => 3,
            'delay' => 60,
        ]);

        $this->artisan('dispatch:configure-retry', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
            '--enabled' => 'true',
            '--max-attempts' => '10',
        ])
            ->assertSuccessful();

        $config = $rule->fresh()->retryConfig;
        expect($config->enabled)->toBeTrue();
        expect($config->max_attempts)->toBe(10);
        expect($config->delay)->toBe(60);
    });

    it('can disable retry', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);

        $this->artisan('dispatch:configure-retry', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
            '--enabled' => 'false',
        ])
            ->assertSuccessful();

        $config = $rule->retryConfig;
        expect($config->enabled)->toBeFalse();
    });

    it('handles partial updates correctly', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);

        $this->artisan('dispatch:configure-retry', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
            '--delay' => '300',
        ])->assertSuccessful();

        $config = $rule->retryConfig;
        expect($config->delay)->toBe(300);
        expect($config->enabled)->toBeFalse();
    });

    it('warns when no options provided', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);

        $this->artisan('dispatch:configure-retry', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
        ])
            ->expectsOutput('No options provided. Nothing to configure.')
            ->assertSuccessful();
    });

    it('fails if project does not exist', function () {
        $this->artisan('dispatch:configure-retry', [
            'repo' => 'owner/nonexistent',
            'rule_id' => 'test-rule',
            '--enabled' => 'true',
        ])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });

    it('fails if rule does not exist', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:configure-retry', [
            'repo' => 'owner/repo',
            'rule_id' => 'nonexistent',
            '--enabled' => 'true',
        ])
            ->expectsOutput("Rule 'nonexistent' does not exist for project 'owner/repo'.")
            ->assertFailed();
    });
});
