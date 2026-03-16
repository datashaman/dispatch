<?php

use App\Enums\FilterOperator;
use App\Models\Filter;
use App\Models\Project;
use App\Models\Rule;
use App\Models\RuleAgentConfig;
use App\Models\RuleOutputConfig;
use App\Models\RuleRetryConfig;
use App\Services\ConfigSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

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
  secrets:
    api_key: "ANTHROPIC_API_KEY"

rules:
  - id: "analyze"
    name: "Analyze Issue"
    event: "issues.labeled"
    circuit_break: false
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
    circuit_break: true
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
        expect($project->agent_secrets)->toBe(['api_key' => 'ANTHROPIC_API_KEY']);
    });

    it('imports rules into the database', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->syncer->import($project);

        expect(Rule::where('project_id', $project->id)->count())->toBe(2);
        $rule = Rule::where('rule_id', 'analyze')->first();
        expect($rule->name)->toBe('Analyze Issue');
        expect($rule->event)->toBe('issues.labeled');
        expect($rule->circuit_break)->toBeFalse();
        expect($rule->sort_order)->toBe(0);
    });

    it('imports filters for rules', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->syncer->import($project);

        $rule = Rule::where('rule_id', 'analyze')->first();
        $filters = $rule->filters;
        expect($filters)->toHaveCount(1);
        expect($filters[0]->filter_id)->toBe('label-filter');
        expect($filters[0]->field)->toBe('event.label.name');
        expect($filters[0]->operator)->toBe(FilterOperator::Equals);
        expect($filters[0]->value)->toBe('sparky');
    });

    it('imports agent config for rules', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->syncer->import($project);

        $rule = Rule::where('rule_id', 'analyze')->first();
        $agent = $rule->agentConfig;
        expect($agent)->not->toBeNull();
        expect($agent->provider)->toBe('openai');
        expect($agent->model)->toBe('gpt-4');
        expect($agent->max_tokens)->toBe(4096);
        expect($agent->tools)->toBe(['read', 'glob']);
        expect($agent->disallowed_tools)->toBe(['edit']);
        expect($agent->isolation)->toBeFalse();
    });

    it('imports output config for rules', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->syncer->import($project);

        $rule = Rule::where('rule_id', 'analyze')->first();
        $output = $rule->outputConfig;
        expect($output)->not->toBeNull();
        expect($output->log)->toBeTrue();
        expect($output->github_comment)->toBeTrue();
        expect($output->github_reaction)->toBe('eyes');
    });

    it('imports retry config for rules', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->syncer->import($project);

        $rule = Rule::where('rule_id', 'analyze')->first();
        $retry = $rule->retryConfig;
        expect($retry)->not->toBeNull();
        expect($retry->enabled)->toBeTrue();
        expect($retry->max_attempts)->toBe(5);
        expect($retry->delay)->toBe(30);
    });

    it('removes rules from DB that are not in config', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);

        // Create an existing rule that's not in the YAML
        Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'old-rule',
        ]);

        writeYaml($this->tempDir, fullYaml());
        $this->syncer->import($project);

        expect(Rule::where('rule_id', 'old-rule')->exists())->toBeFalse();
        expect(Rule::where('project_id', $project->id)->count())->toBe(2);
    });

    it('updates existing rules on re-import', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->syncer->import($project);

        // Modify the YAML and re-import
        $updatedYaml = str_replace('Analyze Issue', 'Updated Analyze', fullYaml());
        writeYaml($this->tempDir, $updatedYaml);

        $this->syncer->import($project);

        $rule = Rule::where('rule_id', 'analyze')->first();
        expect($rule->name)->toBe('Updated Analyze');
        expect(Rule::where('project_id', $project->id)->count())->toBe(2);
    });
});

// --- Export Tests ---

describe('export', function () {
    it('exports project state to dispatch.yml', function () {
        $project = Project::factory()->create([
            'path' => $this->tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
            'agent_provider' => 'anthropic',
            'agent_model' => 'claude-sonnet-4-6',
        ]);

        $rule = Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'test-rule',
            'name' => 'Test Rule',
            'event' => 'issues.labeled',
            'prompt' => 'Do the thing',
            'sort_order' => 0,
        ]);

        $this->syncer->export($project);

        $filePath = $this->tempDir.'/dispatch.yml';
        expect(file_exists($filePath))->toBeTrue();

        $data = Yaml::parseFile($filePath);
        expect($data['version'])->toBe(1);
        expect($data['agent']['name'])->toBe('sparky');
        expect($data['agent']['executor'])->toBe('laravel-ai');
        expect($data['rules'])->toHaveCount(1);
        expect($data['rules'][0]['id'])->toBe('test-rule');
    });

    it('exports rules with all sub-configs', function () {
        $project = Project::factory()->create([
            'path' => $this->tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
        ]);

        $rule = Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'full-rule',
            'name' => 'Full Rule',
            'event' => 'push',
            'prompt' => 'Handle push',
        ]);

        RuleAgentConfig::factory()->create([
            'rule_id' => $rule->id,
            'provider' => 'openai',
            'model' => 'gpt-4',
            'tools' => ['read', 'write'],
        ]);

        RuleOutputConfig::factory()->create([
            'rule_id' => $rule->id,
            'log' => true,
            'github_comment' => true,
            'github_reaction' => 'rocket',
        ]);

        RuleRetryConfig::factory()->create([
            'rule_id' => $rule->id,
            'enabled' => true,
            'max_attempts' => 5,
            'delay' => 30,
        ]);

        Filter::factory()->create([
            'rule_id' => $rule->id,
            'filter_id' => 'my-filter',
            'field' => 'event.action',
            'operator' => 'equals',
            'value' => 'created',
        ]);

        $this->syncer->export($project);

        $data = Yaml::parseFile($this->tempDir.'/dispatch.yml');
        $ruleData = $data['rules'][0];

        expect($ruleData['agent']['provider'])->toBe('openai');
        expect($ruleData['agent']['model'])->toBe('gpt-4');
        expect($ruleData['output']['github_comment'])->toBeTrue();
        expect($ruleData['output']['github_reaction'])->toBe('rocket');
        expect($ruleData['retry']['enabled'])->toBeTrue();
        expect($ruleData['retry']['max_attempts'])->toBe(5);
        expect($ruleData['filters'])->toHaveCount(1);
        expect($ruleData['filters'][0]['field'])->toBe('event.action');
    });

    it('produces valid YAML that can be re-parsed', function () {
        $project = Project::factory()->create([
            'path' => $this->tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
        ]);

        Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'simple',
            'event' => 'push',
            'prompt' => 'Handle it',
        ]);

        $this->syncer->export($project);

        $data = Yaml::parseFile($this->tempDir.'/dispatch.yml');
        expect($data)->toBeArray();
        expect($data)->toHaveKeys(['version', 'agent', 'rules']);
    });
});

// --- Round-Trip Tests ---

describe('round-trip', function () {
    it('import then export produces equivalent YAML', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        // Import
        $this->syncer->import($project);

        // Export
        $this->syncer->export($project);

        // Re-parse and compare
        $exported = Yaml::parseFile($this->tempDir.'/dispatch.yml');

        expect($exported['version'])->toBe(1);
        expect($exported['agent']['name'])->toBe('sparky');
        expect($exported['agent']['executor'])->toBe('laravel-ai');
        expect($exported['agent']['provider'])->toBe('anthropic');
        expect($exported['agent']['model'])->toBe('claude-sonnet-4-6');
        expect($exported['rules'])->toHaveCount(2);

        // Verify first rule round-tripped correctly
        $analyzeRule = collect($exported['rules'])->firstWhere('id', 'analyze');
        expect($analyzeRule['name'])->toBe('Analyze Issue');
        expect($analyzeRule['event'])->toBe('issues.labeled');
        expect($analyzeRule['filters'])->toHaveCount(1);
        expect($analyzeRule['filters'][0]['field'])->toBe('event.label.name');
        expect($analyzeRule['agent']['provider'])->toBe('openai');
        expect($analyzeRule['output']['github_comment'])->toBeTrue();
        expect($analyzeRule['retry']['max_attempts'])->toBe(5);

        // Verify second rule round-tripped correctly
        $implementRule = collect($exported['rules'])->firstWhere('id', 'implement');
        expect($implementRule['name'])->toBe('Implement Feature');
        expect($implementRule['event'])->toBe('issue_comment.created');
        expect($implementRule['agent']['isolation'])->toBeTrue();
    });
});

// --- Command Tests ---

describe('dispatch:import command', function () {
    it('imports config via artisan command', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->artisan('dispatch:import', ['repo' => $project->repo])
            ->expectsOutputToContain('Imported 2 rule(s)')
            ->assertSuccessful();

        expect(Rule::where('project_id', $project->id)->count())->toBe(2);
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

describe('dispatch:export command', function () {
    it('exports config via artisan command', function () {
        $project = Project::factory()->create([
            'path' => $this->tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
        ]);

        Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'test',
            'event' => 'push',
            'prompt' => 'Handle it',
        ]);

        $this->artisan('dispatch:export', ['repo' => $project->repo])
            ->expectsOutputToContain('Exported 1 rule(s)')
            ->assertSuccessful();

        expect(file_exists($this->tempDir.'/dispatch.yml'))->toBeTrue();
    });

    it('fails for nonexistent project', function () {
        $this->artisan('dispatch:export', ['repo' => 'nonexistent/repo'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });
});

describe('dispatch:sync command', function () {
    it('delegates to import command', function () {
        $project = Project::factory()->create(['path' => $this->tempDir]);
        writeYaml($this->tempDir, fullYaml());

        $this->artisan('dispatch:sync', ['repo' => $project->repo, '--direction' => 'import'])
            ->assertSuccessful();

        expect(Rule::where('project_id', $project->id)->count())->toBe(2);
    });

    it('delegates to export command', function () {
        $project = Project::factory()->create([
            'path' => $this->tempDir,
            'agent_name' => 'sparky',
            'agent_executor' => 'laravel-ai',
        ]);

        Rule::factory()->create([
            'project_id' => $project->id,
            'rule_id' => 'test',
            'event' => 'push',
            'prompt' => 'Handle it',
        ]);

        $this->artisan('dispatch:sync', ['repo' => $project->repo, '--direction' => 'export'])
            ->assertSuccessful();

        expect(file_exists($this->tempDir.'/dispatch.yml'))->toBeTrue();
    });

    it('fails for invalid direction', function () {
        $this->artisan('dispatch:sync', ['repo' => 'test/repo', '--direction' => 'invalid'])
            ->expectsOutputToContain('Invalid direction')
            ->assertFailed();
    });
});
