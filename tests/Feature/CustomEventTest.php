<?php

use App\Ai\Tools\EmitCustomEventTool;
use App\DataTransferObjects\CustomEventConfig;
use App\DataTransferObjects\DispatchConfig;
use App\Exceptions\ConfigLoadException;
use App\Models\Project;
use App\Models\WebhookLog;
use App\Services\ConfigLoader;
use App\Services\ConfigWriter;
use App\Services\CustomEventDispatcher;
use App\Services\RuleMatchingEngine;
use App\Services\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->tempDir = sys_get_temp_dir().'/dispatch-custom-event-test-'.uniqid();
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

function writeCustomConfig(string $dir, array $rules, array $customEvents = []): void
{
    $config = [
        'version' => 1,
        'agent' => [
            'executor' => 'laravel-ai',
        ],
        'rules' => $rules,
    ];

    if (! empty($customEvents)) {
        $config['custom_events'] = $customEvents;
    }

    file_put_contents($dir.'/dispatch.yml', Yaml::dump($config, 10, 2));
}

// --- ConfigLoader: custom_events parsing ---

it('parses custom_events from dispatch.yml', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'test', 'event' => 'app.deploy_ready', 'prompt' => 'Handle deploy'],
    ], [
        ['id' => 'deploy_ready', 'name' => 'Deploy Ready', 'description' => 'All checks passed'],
        ['id' => 'analysis_complete', 'name' => 'Analysis Complete'],
    ]);

    $config = app(ConfigLoader::class)->loadFromDisk($this->tempDir);

    expect($config->customEvents)->toHaveCount(2);
    expect($config->customEvents[0])->toBeInstanceOf(CustomEventConfig::class);
    expect($config->customEvents[0]->id)->toBe('deploy_ready');
    expect($config->customEvents[0]->name)->toBe('Deploy Ready');
    expect($config->customEvents[0]->description)->toBe('All checks passed');
    expect($config->customEvents[1]->id)->toBe('analysis_complete');
    expect($config->customEvents[1]->description)->toBeNull();
});

it('allows dispatch.yml without custom_events', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'test', 'event' => 'issues.opened', 'prompt' => 'Test'],
    ]);

    $config = app(ConfigLoader::class)->loadFromDisk($this->tempDir);

    expect($config->customEvents)->toBe([]);
});

it('requires id field on custom events', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'test', 'event' => 'issues.opened', 'prompt' => 'Test'],
    ], [
        ['name' => 'Missing ID'],
    ]);

    app(ConfigLoader::class)->loadFromDisk($this->tempDir);
})->throws(ConfigLoadException::class, "Missing required field 'custom_events[0].id'");

// --- ConfigWriter: custom_events serialization ---

it('serializes custom_events in DispatchConfig to YAML', function () {
    $config = new DispatchConfig(
        version: 1,
        agentProvider: 'anthropic',
        agentModel: 'claude-sonnet-4-6',
        rules: [],
        customEvents: [
            new CustomEventConfig(id: 'deploy_ready', name: 'Deploy Ready', description: 'Ready to deploy'),
        ],
    );

    $yaml = app(ConfigWriter::class)->toYaml($config);
    $parsed = Yaml::parse($yaml);

    expect($parsed['custom_events'])->toHaveCount(1);
    expect($parsed['custom_events'][0]['id'])->toBe('deploy_ready');
    expect($parsed['custom_events'][0]['name'])->toBe('Deploy Ready');
    expect($parsed['custom_events'][0]['description'])->toBe('Ready to deploy');
});

it('omits custom_events from YAML when empty', function () {
    $config = new DispatchConfig(
        version: 1,
        rules: [],
        customEvents: [],
    );

    $yaml = app(ConfigWriter::class)->toYaml($config);
    $parsed = Yaml::parse($yaml);

    expect($parsed)->not->toHaveKey('custom_events');
});

// --- RuleMatchingEngine: matching app.* events ---

it('matches rules with app.* event types', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'on-deploy', 'event' => 'app.deploy_ready', 'prompt' => 'Deploy now'],
        ['id' => 'on-push', 'event' => 'push', 'prompt' => 'Handle push'],
    ]);

    $project = Project::factory()->create(['repo' => 'owner/custom-events', 'path' => $this->tempDir]);

    $engine = app(RuleMatchingEngine::class);
    $matched = $engine->match('owner/custom-events', 'app.deploy_ready', [
        'repository' => ['full_name' => 'owner/custom-events'],
    ]);

    expect($matched)->toHaveCount(1);
    expect($matched->first()->id)->toBe('on-deploy');
});

// --- CustomEventDispatcher ---

it('dispatches custom events through the pipeline', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'on-deploy', 'event' => 'app.deploy_ready', 'prompt' => 'Deploy now'],
    ]);

    $project = Project::factory()->create(['repo' => 'owner/custom-dispatch', 'path' => $this->tempDir]);

    $dispatcher = app(CustomEventDispatcher::class);
    $result = $dispatcher->dispatch(
        eventType: 'deploy_ready',
        eventPayload: ['version' => '1.2.3'],
        project: $project,
    );

    expect($result['ok'])->toBeTrue();
    expect($result['matched'])->toBe(1);
    expect($result['webhook_log_id'])->not->toBeNull();

    // Verify WebhookLog was created with source=app
    $log = WebhookLog::find($result['webhook_log_id']);
    expect($log->source)->toBe('app');
    expect($log->event_type)->toBe('app.deploy_ready');
    expect($log->status)->toBe('processed');
});

it('auto-prefixes event types with app.', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'on-deploy', 'event' => 'app.deploy_ready', 'prompt' => 'Deploy now'],
    ]);

    $project = Project::factory()->create(['repo' => 'owner/prefix-test', 'path' => $this->tempDir]);

    $dispatcher = app(CustomEventDispatcher::class);
    $result = $dispatcher->dispatch(
        eventType: 'deploy_ready', // Without prefix
        eventPayload: [],
        project: $project,
    );

    $log = WebhookLog::find($result['webhook_log_id']);
    expect($log->event_type)->toBe('app.deploy_ready');
});

it('does not double-prefix app. events', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'on-deploy', 'event' => 'app.deploy_ready', 'prompt' => 'Deploy now'],
    ]);

    $project = Project::factory()->create(['repo' => 'owner/double-prefix', 'path' => $this->tempDir]);

    $dispatcher = app(CustomEventDispatcher::class);
    $result = $dispatcher->dispatch(
        eventType: 'app.deploy_ready', // Already prefixed
        eventPayload: [],
        project: $project,
    );

    $log = WebhookLog::find($result['webhook_log_id']);
    expect($log->event_type)->toBe('app.deploy_ready');
});

it('returns no matches when no rules match the custom event', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'on-push', 'event' => 'push', 'prompt' => 'Handle push'],
    ]);

    $project = Project::factory()->create(['repo' => 'owner/no-match', 'path' => $this->tempDir]);

    $dispatcher = app(CustomEventDispatcher::class);
    $result = $dispatcher->dispatch(
        eventType: 'app.nonexistent',
        eventPayload: [],
        project: $project,
    );

    expect($result['ok'])->toBeTrue();
    expect($result['matched'])->toBe(0);
});

// --- Loop Detection ---

it('enforces max event depth to prevent infinite loops', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'on-deploy', 'event' => 'app.deploy_ready', 'prompt' => 'Deploy now'],
    ]);

    $project = Project::factory()->create(['repo' => 'owner/loop-test', 'path' => $this->tempDir]);

    $dispatcher = app(CustomEventDispatcher::class);
    $result = $dispatcher->dispatch(
        eventType: 'deploy_ready',
        eventPayload: [],
        project: $project,
        eventDepth: CustomEventDispatcher::MAX_EVENT_DEPTH,
    );

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('depth limit reached');
    expect($result['matched'])->toBe(0);
});

it('allows events below max depth', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'on-deploy', 'event' => 'app.deploy_ready', 'prompt' => 'Deploy now'],
    ]);

    $project = Project::factory()->create(['repo' => 'owner/depth-ok', 'path' => $this->tempDir]);

    $dispatcher = app(CustomEventDispatcher::class);
    $result = $dispatcher->dispatch(
        eventType: 'deploy_ready',
        eventPayload: [],
        project: $project,
        eventDepth: CustomEventDispatcher::MAX_EVENT_DEPTH - 1,
    );

    expect($result['ok'])->toBeTrue();
    expect($result['matched'])->toBe(1);
});

// --- EmitCustomEventTool ---

it('emits custom events via the tool', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'on-deploy', 'event' => 'app.deploy_ready', 'prompt' => 'Deploy now'],
    ]);

    $project = Project::factory()->create(['repo' => 'owner/tool-test', 'path' => $this->tempDir]);

    $tool = new EmitCustomEventTool($this->tempDir);
    $tool->setProject($project);
    $tool->setEventDepth(0);

    $request = new Request([
        'event_type' => 'deploy_ready',
        'payload' => ['version' => '1.0.0'],
    ]);

    $result = json_decode($tool->handle($request), true);

    expect($result['ok'])->toBeTrue();
    expect($result['matched'])->toBe(1);
});

it('returns error when tool has no project context', function () {
    $tool = new EmitCustomEventTool($this->tempDir);

    $request = new Request(['event_type' => 'test']);
    $result = $tool->handle($request);

    expect($result)->toContain('Error: No project context');
});

it('returns error when event_type is empty', function () {
    $project = Project::factory()->create(['repo' => 'owner/empty-event', 'path' => $this->tempDir]);

    $tool = new EmitCustomEventTool($this->tempDir);
    $tool->setProject($project);

    $request = new Request(['event_type' => '']);
    $result = $tool->handle($request);

    expect($result)->toContain('Error: event_type is required');
});

// --- ToolRegistry: EmitCustomEvent registration ---

it('includes EmitCustomEvent in available tools', function () {
    expect(ToolRegistry::availableTools())->toContain('EmitCustomEvent');
});

it('resolves EmitCustomEvent tool with event depth', function () {
    $project = Project::factory()->create(['repo' => 'owner/registry', 'path' => $this->tempDir]);

    $tools = app(ToolRegistry::class)->resolve(
        tools: ['EmitCustomEvent'],
        workingDirectory: $this->tempDir,
        project: $project,
        eventDepth: 3,
    );

    expect($tools)->toHaveCount(1);
    expect($tools[0])->toBeInstanceOf(EmitCustomEventTool::class);
});

// --- API Endpoint ---

it('handles custom events via the API endpoint', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'on-deploy', 'event' => 'app.deploy_ready', 'prompt' => 'Deploy now'],
    ]);

    $project = Project::factory()->create(['repo' => 'owner/api-test', 'path' => $this->tempDir]);

    $response = $this->postJson('/api/events', [
        'event_type' => 'deploy_ready',
        'repo' => 'owner/api-test',
        'payload' => ['version' => '2.0.0'],
    ]);

    $response->assertOk();
    $response->assertJson([
        'ok' => true,
        'matched' => 1,
    ]);
});

it('returns 404 for unknown repo via API', function () {
    $response = $this->postJson('/api/events', [
        'event_type' => 'deploy_ready',
        'repo' => 'unknown/repo',
    ]);

    $response->assertStatus(404);
    $response->assertJson(['ok' => false]);
});

it('validates required fields on API endpoint', function () {
    $response = $this->postJson('/api/events', []);

    $response->assertStatus(422);
});

// --- Payload includes dispatch metadata ---

it('injects _dispatch metadata into custom event payload', function () {
    writeCustomConfig($this->tempDir, [
        ['id' => 'on-deploy', 'event' => 'app.deploy_ready', 'prompt' => 'Deploy now'],
    ]);

    $project = Project::factory()->create(['repo' => 'owner/metadata', 'path' => $this->tempDir]);

    $dispatcher = app(CustomEventDispatcher::class);
    $result = $dispatcher->dispatch(
        eventType: 'deploy_ready',
        eventPayload: ['key' => 'value'],
        project: $project,
        eventDepth: 2,
    );

    $log = WebhookLog::find($result['webhook_log_id']);
    $payload = $log->payload;

    expect($payload['_dispatch']['source'])->toBe('app');
    expect($payload['_dispatch']['event_depth'])->toBe(3); // incremented from 2
    expect($payload['key'])->toBe('value');
    expect($payload['repository']['full_name'])->toBe('owner/metadata');
});
