<?php

use App\DataTransferObjects\DispatchConfig;
use App\Enums\FilterOperator;
use App\Exceptions\ConfigLoadException;
use App\Services\ConfigLoader;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->loader = new ConfigLoader;
    $this->tempDir = sys_get_temp_dir().'/dispatch-test-'.uniqid();
    mkdir($this->tempDir);
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

function writeConfig(string $dir, string $yaml): void
{
    file_put_contents($dir.'/dispatch.yml', $yaml);
}

function validYaml(): string
{
    return <<<'YAML'
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"
  instructions_file: "SPARKY.md"
  provider: "anthropic"
  model: "claude-sonnet-4-6"

cache:
  config: true

rules:
  - id: "analyze"
    name: "Analyze Issue"
    event: "issues.labeled"
    continue_on_error: false
    filters:
      - id: "filter-1"
        field: "event.label.name"
        operator: "equals"
        value: "sparky"
    agent:
      provider: null
      model: null
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
YAML;
}

it('loads a valid dispatch.yml and returns a DispatchConfig', function () {
    writeConfig($this->tempDir, validYaml());

    $config = $this->loader->load($this->tempDir);

    expect($config)
        ->toBeInstanceOf(DispatchConfig::class)
        ->version->toBe(1)
        ->agentName->toBe('sparky')
        ->agentExecutor->toBe('laravel-ai')
        ->agentInstructionsFile->toBe('SPARKY.md')
        ->agentProvider->toBe('anthropic')
        ->agentModel->toBe('claude-sonnet-4-6')
        ->cacheConfig->toBeTrue()
        ->rules->toHaveCount(1);
});

it('parses rule fields correctly', function () {
    writeConfig($this->tempDir, validYaml());

    $config = $this->loader->load($this->tempDir);
    $rule = $config->rules[0];

    expect($rule)
        ->id->toBe('analyze')
        ->name->toBe('Analyze Issue')
        ->event->toBe('issues.labeled')
        ->continueOnError->toBeFalse()
        ->prompt->toContain('Analyze issue')
        ->filters->toHaveCount(1);
});

it('parses filter fields correctly', function () {
    writeConfig($this->tempDir, validYaml());

    $config = $this->loader->load($this->tempDir);
    $filter = $config->rules[0]->filters[0];

    expect($filter)
        ->id->toBe('filter-1')
        ->field->toBe('event.label.name')
        ->operator->toBe(FilterOperator::Equals)
        ->value->toBe('sparky');
});

it('parses agent config correctly', function () {
    writeConfig($this->tempDir, validYaml());

    $config = $this->loader->load($this->tempDir);
    $agent = $config->rules[0]->agent;

    expect($agent)
        ->provider->toBeNull()
        ->model->toBeNull()
        ->maxTokens->toBe(4096)
        ->tools->toBe(['read', 'glob'])
        ->disallowedTools->toBe(['edit'])
        ->isolation->toBeFalse();
});

it('parses output config correctly', function () {
    writeConfig($this->tempDir, validYaml());

    $config = $this->loader->load($this->tempDir);
    $output = $config->rules[0]->output;

    expect($output)
        ->log->toBeTrue()
        ->githubComment->toBeTrue()
        ->githubReaction->toBe('eyes');
});

it('parses retry config correctly', function () {
    writeConfig($this->tempDir, validYaml());

    $config = $this->loader->load($this->tempDir);
    $retry = $config->rules[0]->retry;

    expect($retry)
        ->enabled->toBeTrue()
        ->maxAttempts->toBe(5)
        ->delay->toBe(30);
});

it('throws on missing file', function () {
    $this->loader->load('/nonexistent/path');
})->throws(ConfigLoadException::class, 'Config file not found');

it('throws on malformed YAML', function () {
    writeConfig($this->tempDir, "version: 1\n  bad indentation:\n- broken");

    $this->loader->load($this->tempDir);
})->throws(ConfigLoadException::class, 'Malformed YAML');

it('throws when version is missing', function () {
    writeConfig($this->tempDir, <<<'YAML'
agent:
  name: "sparky"
  executor: "laravel-ai"
rules:
  - id: "test"
    event: "issues.labeled"
    prompt: "Do something"
YAML);

    $this->loader->load($this->tempDir);
})->throws(ConfigLoadException::class, "Missing required field 'version'");

it('throws when agent is missing', function () {
    writeConfig($this->tempDir, <<<'YAML'
version: 1
rules:
  - id: "test"
    event: "issues.labeled"
    prompt: "Do something"
YAML);

    $this->loader->load($this->tempDir);
})->throws(ConfigLoadException::class, "Missing required field 'agent'");

it('throws when agent.name is missing', function () {
    writeConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  executor: "laravel-ai"
rules:
  - id: "test"
    event: "issues.labeled"
    prompt: "Do something"
YAML);

    $this->loader->load($this->tempDir);
})->throws(ConfigLoadException::class, "Missing required field 'agent.name'");

it('throws when agent.executor is missing', function () {
    writeConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  name: "sparky"
rules:
  - id: "test"
    event: "issues.labeled"
    prompt: "Do something"
YAML);

    $this->loader->load($this->tempDir);
})->throws(ConfigLoadException::class, "Missing required field 'agent.executor'");

it('throws when rules is missing', function () {
    writeConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"
YAML);

    $this->loader->load($this->tempDir);
})->throws(ConfigLoadException::class, "Missing required field 'rules'");

it('throws when a rule is missing id', function () {
    writeConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"
rules:
  - event: "issues.labeled"
    prompt: "Do something"
YAML);

    $this->loader->load($this->tempDir);
})->throws(ConfigLoadException::class, "Missing required field 'rules[0].id'");

it('throws when a rule is missing event', function () {
    writeConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"
rules:
  - id: "test"
    prompt: "Do something"
YAML);

    $this->loader->load($this->tempDir);
})->throws(ConfigLoadException::class, "Missing required field 'rules[0].event'");

it('throws when a rule is missing prompt', function () {
    writeConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"
rules:
  - id: "test"
    event: "issues.labeled"
YAML);

    $this->loader->load($this->tempDir);
})->throws(ConfigLoadException::class, "Missing required field 'rules[0].prompt'");

it('throws on invalid filter operator', function () {
    Log::shouldReceive('warning')->once();

    writeConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"
rules:
  - id: "test"
    event: "issues.labeled"
    prompt: "Do something"
    filters:
      - field: "event.label.name"
        operator: "invalid_op"
        value: "sparky"
YAML);

    $this->loader->load($this->tempDir);
})->throws(ConfigLoadException::class, "Invalid filter operator 'invalid_op'");

it('validates all filter operators', function (string $operator) {
    writeConfig($this->tempDir, <<<YAML
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"
rules:
  - id: "test"
    event: "issues.labeled"
    prompt: "Do something"
    filters:
      - field: "event.label.name"
        operator: "{$operator}"
        value: "sparky"
YAML);

    $config = $this->loader->load($this->tempDir);
    expect($config->rules[0]->filters[0]->operator->value)->toBe($operator);
})->with([
    'equals',
    'not_equals',
    'contains',
    'not_contains',
    'starts_with',
    'ends_with',
    'matches',
]);

it('handles rules with minimal fields', function () {
    writeConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"
rules:
  - id: "simple"
    event: "push"
    prompt: "Handle push"
YAML);

    $config = $this->loader->load($this->tempDir);
    $rule = $config->rules[0];

    expect($rule)
        ->id->toBe('simple')
        ->name->toBeNull()
        ->continueOnError->toBeFalse()
        ->filters->toBe([])
        ->agent->toBeNull()
        ->output->toBeNull()
        ->retry->toBeNull();
});

it('handles multiple rules', function () {
    writeConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"
rules:
  - id: "first"
    event: "issues.labeled"
    prompt: "First rule"
  - id: "second"
    event: "push"
    prompt: "Second rule"
  - id: "third"
    event: "pull_request.opened"
    prompt: "Third rule"
YAML);

    $config = $this->loader->load($this->tempDir);

    expect($config->rules)->toHaveCount(3);
    expect($config->rules[0]->id)->toBe('first');
    expect($config->rules[1]->id)->toBe('second');
    expect($config->rules[2]->id)->toBe('third');
});

it('logs warnings for missing required fields', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, "Missing required field 'version'"));

    writeConfig($this->tempDir, <<<'YAML'
agent:
  name: "sparky"
  executor: "laravel-ai"
rules:
  - id: "test"
    event: "issues.labeled"
    prompt: "Do something"
YAML);

    expect(fn () => $this->loader->load($this->tempDir))
        ->toThrow(ConfigLoadException::class);
});
