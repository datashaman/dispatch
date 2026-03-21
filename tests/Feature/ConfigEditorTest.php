<?php

use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Services\ConfigWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->tempDir = sys_get_temp_dir().'/dispatch-editor-test-'.uniqid();
    mkdir($this->tempDir);

    $this->project = Project::create([
        'repo' => 'test/config-editor',
        'path' => $this->tempDir,
    ]);
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

function writeDispatchYml(string $dir, string $yaml): void
{
    file_put_contents($dir.'/dispatch.yml', $yaml);
}

function minimalYaml(): string
{
    return <<<'YAML'
version: 1
agent:
  name: "test-agent"
  executor: "laravel-ai"
  provider: "anthropic"
  model: "claude-sonnet-4-6"

rules:
  - id: "hello"
    event: "issues.opened"
    name: "Hello Rule"
    prompt: "Say hello"
    filters:
      - field: "event.action"
        operator: "equals"
        value: "opened"
YAML;
}

// --- Page Access ---

test('config editor page is accessible with a project', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $this->get(route('config.index', $this->project))
        ->assertStatus(200);
});

test('config editor page requires authentication', function () {
    auth()->logout();

    $this->get(route('config.index', $this->project))
        ->assertRedirect(route('login'));
});

// --- Loading Config ---

test('loads config from dispatch.yml', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    Volt::test('pages::config.index', ['project' => $this->project])
        ->assertSet('configData.agent.name', 'test-agent')
        ->assertSet('configData.agent.executor', 'laravel-ai')
        ->assertSee('test/config-editor')
        ->assertSee('Hello Rule');
});

test('shows empty state when no dispatch.yml exists', function () {
    Volt::test('pages::config.index', ['project' => $this->project])
        ->assertSee('No dispatch.yml found')
        ->assertSee('Generate Default Rules');
});

test('shows error for malformed YAML', function () {
    writeDispatchYml($this->tempDir, "invalid: [yaml: broken\n  <<<");

    Volt::test('pages::config.index', ['project' => $this->project])
        ->assertSet('errorMessage', fn ($msg) => str_contains($msg, 'Failed to parse'));
});

// --- Saving Config ---

test('saves config to dispatch.yml', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    Volt::test('pages::config.index', ['project' => $this->project])
        ->set('configData.agent.name', 'updated-agent')
        ->call('saveConfig')
        ->assertSet('statusMessage', 'dispatch.yml saved.');

    $saved = Yaml::parseFile($this->tempDir.'/dispatch.yml');
    expect($saved['agent']['name'])->toBe('updated-agent');
});

test('detects mtime conflict on save', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project]);

    // Simulate external modification by explicitly advancing the file mtime
    $filePath = $this->tempDir.'/dispatch.yml';
    $originalMtime = filemtime($filePath);
    touch($filePath, $originalMtime + 2);
    clearstatcache(true, $filePath);

    $component
        ->call('saveConfig')
        ->assertSet('showMtimeConflict', true)
        ->assertSet('errorMessage', fn ($msg) => str_contains($msg, 'modified externally'));
});

test('reload clears mtime conflict', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project]);
    $component->set('showMtimeConflict', true);

    $component
        ->call('reloadConfig')
        ->assertSet('showMtimeConflict', false)
        ->assertSet('statusMessage', 'Config reloaded from disk.');
});

// --- Validation ---

test('validates required fields before saving', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    Volt::test('pages::config.index', ['project' => $this->project])
        ->set('configData.agent.name', '')
        ->call('saveConfig')
        ->assertSet('errorMessage', fn ($msg) => str_contains($msg, 'Agent name is required'));
});

test('validates rule required fields', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    Volt::test('pages::config.index', ['project' => $this->project])
        ->set('configData.rules.0.id', '')
        ->call('saveConfig')
        ->assertSet('errorMessage', fn ($msg) => str_contains($msg, 'Rule 1: ID is required'));
});

test('validates duplicate rule IDs', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    Volt::test('pages::config.index', ['project' => $this->project])
        ->call('addBlankRule')
        ->set('configData.rules.1.id', 'hello')
        ->set('configData.rules.1.event', 'issues.opened')
        ->set('configData.rules.1.prompt', 'test')
        ->call('saveConfig')
        ->assertSet('errorMessage', fn ($msg) => str_contains($msg, "Duplicate rule ID: 'hello'"));
});

// --- Rule Management ---

test('adds blank rule', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project]);

    expect($component->get('configData.rules'))->toHaveCount(1);

    $component->call('addBlankRule');

    expect($component->get('configData.rules'))->toHaveCount(2);
    expect($component->get('configData.rules.1.id'))->toBe('');
    expect($component->get('expandedRuleId'))->toBe('1');
});

test('removes rule', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('addBlankRule');

    expect($component->get('configData.rules'))->toHaveCount(2);

    $component->call('removeRule', 1);

    expect($component->get('configData.rules'))->toHaveCount(1);
    expect($component->get('configData.rules.0.id'))->toBe('hello');
});

test('duplicates rule with copy suffix', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('duplicateRule', 0);

    expect($component->get('configData.rules'))->toHaveCount(2);
    expect($component->get('configData.rules.1.id'))->toBe('hello-copy');
    expect($component->get('configData.rules.1.name'))->toContain('(copy)');
    expect($component->get('expandedRuleId'))->toBe('1');
});

test('duplicates rule with counter when copy already exists', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('duplicateRule', 0)  // Creates hello-copy at index 1
        ->call('duplicateRule', 0); // Creates hello-copy-2 at index 1, pushes hello-copy to index 2

    expect($component->get('configData.rules'))->toHaveCount(3);
    // Second duplicate inserts after index 0, so hello-copy-2 is at 1 and hello-copy at 2
    expect($component->get('configData.rules.1.id'))->toBe('hello-copy-2');
    expect($component->get('configData.rules.2.id'))->toBe('hello-copy');
});

test('moves rule up and down', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('addBlankRule')
        ->set('configData.rules.1.id', 'second');

    expect($component->get('configData.rules.0.id'))->toBe('hello');
    expect($component->get('configData.rules.1.id'))->toBe('second');

    // Move second rule up
    $component->call('moveRule', 1, 'up');

    expect($component->get('configData.rules.0.id'))->toBe('second');
    expect($component->get('configData.rules.1.id'))->toBe('hello');

    // Move it back down
    $component->call('moveRule', 0, 'down');

    expect($component->get('configData.rules.0.id'))->toBe('hello');
    expect($component->get('configData.rules.1.id'))->toBe('second');
});

test('does not move rule beyond bounds', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('moveRule', 0, 'up'); // Already at top

    expect($component->get('configData.rules.0.id'))->toBe('hello');
});

// --- Expand/Collapse ---

test('toggles individual rule expand', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('toggleExpand', 0);

    expect($component->get('expandedRuleId'))->toBe('0');

    $component->call('toggleExpand', 0);

    expect($component->get('expandedRuleId'))->toBeNull();
});

test('toggles expand all', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('toggleExpandAll');

    expect($component->get('allExpanded'))->toBeTrue();

    $component->call('toggleExpandAll');

    expect($component->get('allExpanded'))->toBeFalse();
});

// --- Filter Management ---

test('adds filter to a rule', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project]);

    // Rule already has 1 filter from minimalYaml
    expect($component->get('configData.rules.0.filters'))->toHaveCount(1);

    $component->call('addFilter', 0);

    expect($component->get('configData.rules.0.filters'))->toHaveCount(2);
    expect($component->get('configData.rules.0.filters.1.field'))->toBe('');
});

test('removes filter from a rule', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('removeFilter', 0, 0);

    expect($component->get('configData.rules.0.filters'))->toHaveCount(0);
});

// --- Dirty State ---

test('isDirty is false when config matches original', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project]);

    expect($component->get('isDirty'))->toBeFalse();
});

test('isDirty is true after modifying config', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->set('configData.agent.name', 'changed-agent');

    expect($component->get('isDirty'))->toBeTrue();
});

test('isDirty resets after save', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->set('configData.agent.name', 'changed-agent')
        ->call('saveConfig');

    expect($component->get('isDirty'))->toBeFalse();
});

// --- Dependency Warnings ---

test('shows dependency warning for missing depends_on target', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->set('configData.rules.0.depends_on', ['nonexistent-rule']);

    $warnings = $component->get('dependencyWarnings');
    expect($warnings)->toHaveKey('hello');
    expect($warnings['hello'][0])->toContain('nonexistent-rule');
});

test('no dependency warning when depends_on target exists', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('addBlankRule')
        ->set('configData.rules.1.id', 'second')
        ->set('configData.rules.1.depends_on', ['hello']);

    $warnings = $component->get('dependencyWarnings');
    expect($warnings)->not->toHaveKey('second');
});

// --- YAML Preview ---

test('yaml preview shows current config state', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project]);

    $preview = $component->get('yamlPreview');
    expect($preview)->toContain('test-agent');
    expect($preview)->toContain('hello');
});

test('yaml preview is empty when no config loaded', function () {
    $component = Volt::test('pages::config.index', ['project' => $this->project]);

    expect($component->get('yamlPreview'))->toBe('');
});

// --- Generate Default Rules ---

test('generates default rules when no config exists', function () {
    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('generateDefaultRules');

    expect($component->get('configData'))->not->toBeEmpty();
    expect($component->get('statusMessage'))->toBe('Default rules generated.');
    expect(file_exists($this->tempDir.'/dispatch.yml'))->toBeTrue();
});

test('does not overwrite existing config with generate', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    Volt::test('pages::config.index', ['project' => $this->project])
        ->call('generateDefaultRules')
        ->assertSet('errorMessage', 'dispatch.yml already exists.');
});

// --- prepareForSave cleans data ---

test('save strips empty optional fields from YAML', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->call('saveConfig');

    $saved = Yaml::parseFile($this->tempDir.'/dispatch.yml');
    $rule = $saved['rules'][0];

    // Output with only default values should not be in YAML
    // Agent with only empty strings should not be in YAML
    expect($rule)->not->toHaveKey('continue_on_error');
    expect($rule)->not->toHaveKey('retry');
});

test('save converts tools string to array', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $component = Volt::test('pages::config.index', ['project' => $this->project])
        ->set('configData.rules.0.agent.tools', 'Read, Edit, Bash')
        ->call('saveConfig');

    $saved = Yaml::parseFile($this->tempDir.'/dispatch.yml');
    expect($saved['rules'][0]['agent']['tools'])->toBe(['Read', 'Edit', 'Bash']);
});

// --- ConfigWriter Unit Tests ---

test('ConfigWriter arrayToYaml produces valid YAML', function () {
    $writer = app(ConfigWriter::class);
    $data = [
        'version' => 1,
        'agent' => ['name' => 'test', 'executor' => 'laravel-ai'],
        'rules' => [
            ['id' => 'r1', 'event' => 'issues.opened', 'prompt' => 'Do something'],
        ],
    ];

    $yaml = $writer->arrayToYaml($data);
    $parsed = Yaml::parse($yaml);

    expect($parsed['version'])->toBe(1);
    expect($parsed['rules'][0]['id'])->toBe('r1');
});

test('ConfigWriter getMtime returns null for missing file', function () {
    $writer = app(ConfigWriter::class);
    expect($writer->getMtime('/nonexistent/path'))->toBeNull();
});

test('ConfigWriter getMtime returns integer for existing file', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $writer = app(ConfigWriter::class);
    $mtime = $writer->getMtime($this->tempDir);

    expect($mtime)->toBeInt();
    expect($mtime)->toBeGreaterThan(0);
});

test('deploy config fails without GitHub installation', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    Volt::test('pages::config.index', ['project' => $this->project])
        ->call('deployConfig')
        ->assertSet('errorMessage', 'No GitHub App installation linked to this project.');
});

test('deploy config shows button only when GitHub installation exists', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    // Without installation — no deploy button
    Volt::test('pages::config.index', ['project' => $this->project])
        ->assertDontSee('Deploy to Repo');

    // With installation — deploy button visible
    $installation = GitHubInstallation::factory()->create([
        'installation_id' => 12345,
        'account_login' => 'testuser',
    ]);

    $this->project->update(['github_installation_id' => $installation->id]);
    $this->project->refresh();

    Volt::test('pages::config.index', ['project' => $this->project])
        ->assertSee('Deploy to Repo');
});

test('deploy config fails when dispatch.yml does not exist', function () {
    $installation = GitHubInstallation::factory()->create([
        'installation_id' => 12345,
        'account_login' => 'testuser',
    ]);

    $this->project->update(['github_installation_id' => $installation->id]);

    Volt::test('pages::config.index', ['project' => $this->project])
        ->call('deployConfig')
        ->assertSet('errorMessage', 'No dispatch.yml found. Save first.');
});

test('ConfigWriter save returns success result', function () {
    writeDispatchYml($this->tempDir, minimalYaml());

    $writer = app(ConfigWriter::class);
    $mtime = $writer->getMtime($this->tempDir);

    $result = $writer->save($this->project, [
        'version' => 1,
        'agent' => ['name' => 'test', 'executor' => 'laravel-ai'],
        'rules' => [
            ['id' => 'r1', 'event' => 'issues.opened', 'prompt' => 'Do it'],
        ],
    ], $mtime);

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toBe('dispatch.yml saved.');
});
