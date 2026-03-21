<?php

use App\Enums\FilterOperator;
use App\Models\Project;
use App\Services\ConfigSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dispatch-sync-test-'.uniqid();
    mkdir($this->tempDir);
    $this->syncer = app(ConfigSyncer::class);
});

afterEach(function () {
    $configFile = $this->tempDir.'/dispatch.yml';
    if (file_exists($configFile)) {
        unlink($configFile);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

function fullYaml(): string
{
    return <<<'YAML'
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"
  instructions_file: "SPARKY.md"
  provider: "anthropic"
  model: "claude-sonnet-4-6"

rules:
  - id: "analyze"
    name: "Analyze Issue"
    event: "issues.labeled"
    continue_on_error: false
    sort_order: 0
    filters:
      - id: "label-filter"
        field: "event.label.name"
        operator: "equals"
        value: "sparky"
    agent:
      provider: "openai"
      model: "gpt-4"
      max_tokens: 4096
      tools:
        - "read"
        - "glob"
      disallowed_tools:
        - "edit"
      isolation: false
    output:
      log: true
      github_comment: true
      github_reaction: "eyes"
    retry:
      enabled: true
      max_attempts: 5
      delay: 30
    prompt: |
      Analyze issue #{{ event.issue.number }}.
  - id: "implement"
    name: "Implement Feature"
    event: "issue_comment.created"
    continue_on_error: true
    sort_order: 1
    filters:
      - field: "event.comment.body"
        operator: "contains"
        value: "@sparky implement"
    agent:
      isolation: true
    output:
      log: true
    retry:
      enabled: false
      max_attempts: 3
      delay: 60
    prompt: |
      Implement the feature described in the issue.
YAML;
}

function writeYaml(string $dir, string $yaml): void
{
    file_put_contents($dir.'/dispatch.yml', $yaml);
}

// --- Import Tests ---

describe('import', function () {
    it('imports project-level agent config', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->syncer->import($project);

        $project->refresh();
        expect($project->agent_name)->toBe('sparky');
        expect($project->agent_executor)->toBe('laravel-ai');
        expect($project->agent_provider)->toBe('anthropic');
        expect($project->agent_model)->toBe('claude-sonnet-4-6');
        expect($project->agent_instructions_file)->toBe('SPARKY.md');
    });

    it('returns DispatchConfig with parsed rules', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $config = $this->syncer->import($project);

        expect($config->rules)->toHaveCount(2);
        expect($config->rules[0]->id)->toBe('analyze');
        expect($config->rules[0]->name)->toBe('Analyze Issue');
        expect($config->rules[0]->event)->toBe('issues.labeled');
        expect($config->rules[0]->continueOnError)->toBeFalse();
        expect($config->rules[0]->sortOrder)->toBe(0);
    });

    it('returns rules with filters', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $config = $this->syncer->import($project);

        $filters = $config->rules[0]->filters;
        expect($filters)->toHaveCount(1);
        expect($filters[0]->id)->toBe('label-filter');
        expect($filters[0]->field)->toBe('event.label.name');
        expect($filters[0]->operator)->toBe(FilterOperator::Equals);
        expect($filters[0]->value)->toBe('sparky');
    });

    it('returns rules with agent config', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $config = $this->syncer->import($project);

        $agent = $config->rules[0]->agent;
        expect($agent)->not->toBeNull();
        expect($agent->provider)->toBe('openai');
        expect($agent->model)->toBe('gpt-4');
        expect($agent->maxTokens)->toBe(4096);
        expect($agent->tools)->toBe(['read', 'glob']);
        expect($agent->disallowedTools)->toBe(['edit']);
        expect($agent->isolation)->toBeFalse();
    });

    it('returns rules with output config', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $config = $this->syncer->import($project);

        $output = $config->rules[0]->output;
        expect($output)->not->toBeNull();
        expect($output->log)->toBeTrue();
        expect($output->githubComment)->toBeTrue();
        expect($output->githubReaction)->toBe('eyes');
    });

    it('returns rules with retry config', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $config = $this->syncer->import($project);

        $retry = $config->rules[0]->retry;
        expect($retry)->not->toBeNull();
        expect($retry->enabled)->toBeTrue();
        expect($retry->maxAttempts)->toBe(5);
        expect($retry->delay)->toBe(30);
    });

    it('updates project agent settings on re-import', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->syncer->import($project);

        // Modify the YAML and re-import
        $updatedYaml = str_replace('sparky', 'new-agent', fullYaml());
        writeYaml($this->tempDir, $updatedYaml);

        $this->syncer->import($project);

        $project->refresh();
        expect($project->agent_name)->toBe('new-agent');
    });
});

// --- Command Tests ---

describe('dispatch:import command', function () {
    it('imports config via artisan command', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->artisan('dispatch:import', ['repo' => $project->repo])
            ->expectsOutputToContain('2 rules found')
            ->assertSuccessful();

        $project->refresh();
        expect($project->agent_name)->toBe('sparky');
    });

    it('fails for nonexistent project', function () {
        $this->artisan('dispatch:import', ['repo' => 'nonexistent/repo'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });

    it('fails for missing dispatch.yml', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);

        $this->artisan('dispatch:import', ['repo' => $project->repo])
            ->expectsOutputToContain('Config file not found')
            ->assertFailed();
    });
});

describe('dispatch:sync command', function () {
    it('delegates to import command', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->artisan('dispatch:sync', ['repo' => $project->repo, '--direction' => 'import'])
            ->assertSuccessful();

        $project->refresh();
        expect($project->agent_name)->toBe('sparky');
    });

    it('fails for invalid direction', function () {
        $this->artisan('dispatch:sync', ['repo' => 'test/repo', '--direction' => 'invalid'])
            ->expectsOutputToContain('Invalid direction')
            ->assertFailed();
    });
});
