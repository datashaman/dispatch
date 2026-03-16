<?php

use App\Models\Project;
use App\Models\Rule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('dispatch:seed-defaults', function () {
    it('seeds 5 default rules for a project', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        expect($project->rules()->count())->toBe(5);

        // Verify each rule exists with correct event
        $rules = $project->rules()->orderBy('sort_order')->get();
        expect($rules[0]->rule_id)->toBe('analyze');
        expect($rules[0]->event)->toBe('issues.labeled');
        expect($rules[1]->rule_id)->toBe('discuss');
        expect($rules[1]->event)->toBe('discussion_comment.created');
        expect($rules[2]->rule_id)->toBe('implement');
        expect($rules[2]->event)->toBe('issue_comment.created');
        expect($rules[3]->rule_id)->toBe('interactive');
        expect($rules[3]->event)->toBe('issue_comment.created');
        expect($rules[4]->rule_id)->toBe('review');
        expect($rules[4]->event)->toBe('pull_request_review_comment.created');

        // Verify dispatch.yml was exported
        expect(file_exists($tempDir.'/dispatch.yml'))->toBeTrue();

        // Cleanup
        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('creates filters for each rule', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        // analyze: 1 filter (label = sparky)
        $analyze = $project->rules()->where('rule_id', 'analyze')->first();
        expect($analyze->filters()->count())->toBe(1);
        expect($analyze->filters()->first()->field)->toBe('label.name');
        expect($analyze->filters()->first()->value)->toBe('sparky');

        // interactive: 2 filters (contains @sparky AND not_contains @sparky implement)
        $interactive = $project->rules()->where('rule_id', 'interactive')->first();
        expect($interactive->filters()->count())->toBe(2);

        // implement: isolation should be true
        $implement = $project->rules()->where('rule_id', 'implement')->first();
        expect($implement->agentConfig->isolation)->toBeTrue();

        // Cleanup
        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('creates agent config for each rule', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        // analyze: read-only tools
        $analyze = $project->rules()->where('rule_id', 'analyze')->first();
        expect($analyze->agentConfig->tools)->toBe(['Read', 'Glob', 'Grep']);
        expect($analyze->agentConfig->isolation)->toBeFalse();

        // discuss: bash only
        $discuss = $project->rules()->where('rule_id', 'discuss')->first();
        expect($discuss->agentConfig->tools)->toBe(['Bash']);

        // implement: full tools, isolation on
        $implement = $project->rules()->where('rule_id', 'implement')->first();
        expect($implement->agentConfig->tools)->toBe(['Read', 'Edit', 'Write', 'Bash', 'Glob', 'Grep']);
        expect($implement->agentConfig->isolation)->toBeTrue();

        // Cleanup
        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('creates output config for each rule', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        $analyze = $project->rules()->where('rule_id', 'analyze')->first();
        expect($analyze->outputConfig->log)->toBeTrue();
        expect($analyze->outputConfig->github_comment)->toBeTrue();
        expect($analyze->outputConfig->github_reaction)->toBe('eyes');

        $implement = $project->rules()->where('rule_id', 'implement')->first();
        expect($implement->outputConfig->github_reaction)->toBe('rocket');

        // Cleanup
        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('fails when project does not exist', function () {
        $this->artisan('dispatch:seed-defaults', ['repo' => 'nonexistent/repo'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });

    it('skips rules that already exist', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
        ]);

        // Pre-create the analyze rule
        $project->rules()->create([
            'rule_id' => 'analyze',
            'name' => 'Existing Analyze',
            'event' => 'issues.labeled',
            'prompt' => 'existing prompt',
            'sort_order' => 0,
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->expectsOutputToContain("Rule 'analyze' already exists, skipping.")
            ->assertSuccessful();

        // Should have 5 rules total (1 existing + 4 new)
        expect($project->rules()->count())->toBe(5);

        // Existing rule should not be modified
        $analyze = $project->rules()->where('rule_id', 'analyze')->first();
        expect($analyze->name)->toBe('Existing Analyze');

        // Cleanup
        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('exports seeded rules to dispatch.yml', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        $yamlContent = file_get_contents($tempDir.'/dispatch.yml');
        expect($yamlContent)->toContain('analyze');
        expect($yamlContent)->toContain('discuss');
        expect($yamlContent)->toContain('implement');
        expect($yamlContent)->toContain('interactive');
        expect($yamlContent)->toContain('review');
        expect($yamlContent)->toContain('issues.labeled');
        expect($yamlContent)->toContain('discussion_comment.created');
        expect($yamlContent)->toContain('pull_request_review_comment.created');

        // Cleanup
        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });
});
