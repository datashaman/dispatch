<?php

use App\Models\Filter;
use App\Models\Project;
use App\Models\Rule;
use App\Models\RuleAgentConfig;
use App\Models\RuleOutputConfig;
use App\Models\RuleRetryConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('dispatch:configure-agent', function () {
    it('creates agent config for a rule', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);

        $this->artisan('dispatch:configure-agent', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
            '--provider' => 'anthropic',
            '--model' => 'claude-sonnet-4-5-20250514',
            '--max-tokens' => '4096',
            '--tools' => 'Read,Write,Bash',
            '--disallowed-tools' => 'Glob',
            '--isolation' => 'true',
        ])
            ->expectsOutput("Agent config updated for rule 'test-rule' on project 'owner/repo'.")
            ->assertSuccessful();

        $config = $rule->agentConfig;
        expect($config)->not->toBeNull();
        expect($config->provider)->toBe('anthropic');
        expect($config->model)->toBe('claude-sonnet-4-5-20250514');
        expect($config->max_tokens)->toBe(4096);
        expect($config->tools)->toBe(['Read', 'Write', 'Bash']);
        expect($config->disallowed_tools)->toBe(['Glob']);
        expect($config->isolation)->toBeTrue();
    });

    it('updates existing agent config', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);
        RuleAgentConfig::factory()->create([
            'rule_id' => $rule->id,
            'provider' => 'openai',
            'model' => 'gpt-4',
        ]);

        $this->artisan('dispatch:configure-agent', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
            '--provider' => 'anthropic',
        ])
            ->assertSuccessful();

        $config = $rule->fresh()->agentConfig;
        expect($config->provider)->toBe('anthropic');
        expect($config->model)->toBe('gpt-4');
    });

    it('warns when no options provided', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);

        $this->artisan('dispatch:configure-agent', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
        ])
            ->expectsOutput('No options provided. Nothing to configure.')
            ->assertSuccessful();
    });

    it('fails if project does not exist', function () {
        $this->artisan('dispatch:configure-agent', [
            'repo' => 'owner/nonexistent',
            'rule_id' => 'test-rule',
            '--provider' => 'anthropic',
        ])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });

    it('fails if rule does not exist', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:configure-agent', [
            'repo' => 'owner/repo',
            'rule_id' => 'nonexistent',
            '--provider' => 'anthropic',
        ])
            ->expectsOutput("Rule 'nonexistent' does not exist for project 'owner/repo'.")
            ->assertFailed();
    });

    it('handles partial updates correctly', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create(['project_id' => $project->id, 'rule_id' => 'test-rule']);

        $this->artisan('dispatch:configure-agent', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
            '--tools' => 'Read,Edit',
        ])->assertSuccessful();

        $config = $rule->agentConfig;
        expect($config->tools)->toBe(['Read', 'Edit']);
        expect($config->provider)->toBeNull();
        expect($config->model)->toBeNull();
    });
});

describe('dispatch:show-rule', function () {
    it('displays full rule configuration', function () {
        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'agent_provider' => 'anthropic',
            'agent_model' => 'claude-sonnet-4-5-20250514',
        ]);
        $rule = Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'analyze',
            'name' => 'Analyze Issues',
            'event' => 'issues.labeled',
            'circuit_break' => false,
            'sort_order' => 1,
            'prompt' => 'Analyze the issue: {{ event.issue.title }}',
        ]);

        RuleAgentConfig::factory()->create([
            'rule_id' => $rule->id,
            'provider' => 'openai',
            'model' => 'gpt-4',
            'max_tokens' => 2048,
            'tools' => ['Read', 'Bash'],
            'disallowed_tools' => ['Write'],
            'isolation' => true,
        ]);

        RuleOutputConfig::factory()->create([
            'rule_id' => $rule->id,
            'log' => true,
            'github_comment' => true,
            'github_reaction' => 'eyes',
        ]);

        RuleRetryConfig::factory()->create([
            'rule_id' => $rule->id,
            'enabled' => true,
            'max_attempts' => 5,
            'delay' => 120,
        ]);

        Filter::factory()->create([
            'rule_id' => $rule->id,
            'filter_id' => 'label-check',
            'field' => 'event.label.name',
            'operator' => 'equals',
            'value' => 'sparky',
            'sort_order' => 0,
        ]);

        $this->artisan('dispatch:show-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'analyze',
        ])
            ->expectsOutput('Rule: analyze')
            ->expectsOutputToContain('Analyze Issues')
            ->expectsOutputToContain('issues.labeled')
            ->expectsOutputToContain('openai')
            ->expectsOutputToContain('gpt-4')
            ->expectsOutputToContain('2048')
            ->expectsOutputToContain('Read, Bash')
            ->expectsOutputToContain('Yes')
            ->expectsOutputToContain('eyes')
            ->expectsOutputToContain('120s')
            ->expectsTable(
                ['ID', 'Filter ID', 'Field', 'Operator', 'Value', 'Sort Order'],
                [[$rule->filters()->first()->id, 'label-check', 'event.label.name', 'equals', 'sparky', '0']],
            )
            ->assertSuccessful();
    });

    it('shows project-level fallback for provider and model', function () {
        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'agent_provider' => 'anthropic',
            'agent_model' => 'claude-sonnet-4-5',
        ]);
        $rule = Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'test-rule',
        ]);

        // Verify the fallback logic works correctly
        $agentConfig = $rule->agentConfig;
        expect($agentConfig)->toBeNull();

        $effectiveProvider = $agentConfig?->provider ?? $project->agent_provider;
        $effectiveModel = $agentConfig?->model ?? $project->agent_model;

        expect($effectiveProvider)->toBe('anthropic');
        expect($effectiveModel)->toBe('claude-sonnet-4-5');

        $this->artisan('dispatch:show-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
        ])
            ->expectsOutputToContain('(from project)')
            ->assertSuccessful();
    });

    it('shows defaults when no configs exist', function () {
        $project = Project::factory()->create(['repo' => 'owner/repo']);
        $rule = Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'test-rule',
        ]);

        $this->artisan('dispatch:show-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'test-rule',
        ])
            ->expectsOutputToContain('(not set)')
            ->expectsOutputToContain('(none)')
            ->assertSuccessful();
    });

    it('fails if project does not exist', function () {
        $this->artisan('dispatch:show-rule', [
            'repo' => 'owner/nonexistent',
            'rule_id' => 'test-rule',
        ])
            ->expectsOutput("Project 'owner/nonexistent' does not exist.")
            ->assertFailed();
    });

    it('fails if rule does not exist', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:show-rule', [
            'repo' => 'owner/repo',
            'rule_id' => 'nonexistent',
        ])
            ->expectsOutput("Rule 'nonexistent' does not exist for project 'owner/repo'.")
            ->assertFailed();
    });
});

describe('agent config fallback behavior', function () {
    it('falls back to project-level config when rule-level is null', function () {
        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'agent_provider' => 'anthropic',
            'agent_model' => 'claude-sonnet-4-5-20250514',
        ]);
        $rule = Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'test-rule',
        ]);

        RuleAgentConfig::factory()->create([
            'rule_id' => $rule->id,
            'provider' => null,
            'model' => null,
            'max_tokens' => 2048,
        ]);

        $agentConfig = $rule->agentConfig;
        $effectiveProvider = $agentConfig->provider ?? $project->agent_provider;
        $effectiveModel = $agentConfig->model ?? $project->agent_model;

        expect($effectiveProvider)->toBe('anthropic');
        expect($effectiveModel)->toBe('claude-sonnet-4-5-20250514');
        expect($agentConfig->max_tokens)->toBe(2048);
    });

    it('uses rule-level config when set', function () {
        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'agent_provider' => 'anthropic',
            'agent_model' => 'claude-sonnet-4-5-20250514',
        ]);
        $rule = Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'test-rule',
        ]);

        RuleAgentConfig::factory()->create([
            'rule_id' => $rule->id,
            'provider' => 'openai',
            'model' => 'gpt-4',
        ]);

        $agentConfig = $rule->agentConfig;
        $effectiveProvider = $agentConfig->provider ?? $project->agent_provider;
        $effectiveModel = $agentConfig->model ?? $project->agent_model;

        expect($effectiveProvider)->toBe('openai');
        expect($effectiveModel)->toBe('gpt-4');
    });
});
