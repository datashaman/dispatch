<?php

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

describe('dispatch:seed-defaults', function () {
    it('creates dispatch.yml with default rules for a project', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        expect(file_exists($tempDir.'/dispatch.yml'))->toBeTrue();

        $yamlContent = file_get_contents($tempDir.'/dispatch.yml');
        $data = Yaml::parse($yamlContent);

        expect($data['rules'])->toHaveCount(4);

        $ruleIds = array_column($data['rules'], 'id');
        expect($ruleIds)->toContain('analyze')
            ->toContain('implement')
            ->toContain('interactive')
            ->toContain('review');

        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('creates correct events for each rule', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        $data = Yaml::parseFile($tempDir.'/dispatch.yml');
        $rules = collect($data['rules'])->keyBy('id');

        expect($rules['analyze']['event'])->toBe('issues.labeled');
        expect($rules['implement']['event'])->toBe('issue_comment.created');
        expect($rules['interactive']['event'])->toBe('issue_comment.created');
        expect($rules['review']['event'])->toBe('pull_request_review_comment.created');

        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('creates filters for each rule', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        $data = Yaml::parseFile($tempDir.'/dispatch.yml');
        $rules = collect($data['rules'])->keyBy('id');

        // analyze: 1 filter (label = dispatch)
        expect($rules['analyze']['filters'])->toHaveCount(1);
        expect($rules['analyze']['filters'][0]['field'])->toBe('event.label.name');
        expect($rules['analyze']['filters'][0]['value'])->toBe('dispatch');

        // interactive: 2 filters (contains @dispatch AND not_contains @dispatch implement)
        expect($rules['interactive']['filters'])->toHaveCount(2);

        // implement: isolation should be true
        expect($rules['implement']['agent']['isolation'])->toBeTrue();

        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('creates agent config for each rule', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        $data = Yaml::parseFile($tempDir.'/dispatch.yml');
        $rules = collect($data['rules'])->keyBy('id');

        // analyze: read + bash tools
        expect($rules['analyze']['agent']['tools'])->toBe(['Read', 'Glob', 'Grep', 'Bash']);

        // implement: full tools, isolation on
        expect($rules['implement']['agent']['tools'])->toBe(['Read', 'Edit', 'Write', 'Bash', 'Glob', 'Grep']);
        expect($rules['implement']['agent']['isolation'])->toBeTrue();

        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('creates output config for each rule', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        $data = Yaml::parseFile($tempDir.'/dispatch.yml');
        $rules = collect($data['rules'])->keyBy('id');

        expect($rules['analyze']['output']['log'])->toBeTrue();
        expect($rules['analyze']['output']['github_comment'])->toBeTrue();
        expect($rules['analyze']['output']['github_reaction'])->toBe('eyes');

        expect($rules['implement']['output']['github_reaction'])->toBe('rocket');

        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('fails when project does not exist', function () {
        $this->artisan('dispatch:seed-defaults', ['repo' => 'nonexistent/repo'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });

    it('does not overwrite existing dispatch.yml', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
        ]);

        // Pre-create a dispatch.yml
        $existingContent = "---\nversion: 1\nagent:\n  name: existing\n  executor: laravel-ai\nrules: []\n";
        file_put_contents($tempDir.'/dispatch.yml', $existingContent);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        // File should not be overwritten
        expect(file_get_contents($tempDir.'/dispatch.yml'))->toBe($existingContent);

        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });

    it('contains all expected rule names in dispatch.yml', function () {
        $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_seed_');
        mkdir($tempDir, 0755, true);

        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $tempDir,
        ]);

        $this->artisan('dispatch:seed-defaults', ['repo' => 'owner/repo'])
            ->assertSuccessful();

        $yamlContent = file_get_contents($tempDir.'/dispatch.yml');
        expect($yamlContent)->toContain('analyze');
        expect($yamlContent)->toContain('implement');
        expect($yamlContent)->toContain('interactive');
        expect($yamlContent)->toContain('review');
        expect($yamlContent)->toContain('issues.labeled');
        expect($yamlContent)->toContain('pull_request_review_comment.created');

        @unlink($tempDir.'/dispatch.yml');
        @rmdir($tempDir);
    });
});
