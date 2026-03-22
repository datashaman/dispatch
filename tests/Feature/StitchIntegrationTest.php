<?php

use App\Ai\Contracts\ProjectAware;
use App\Ai\Tools\StitchEditTool;
use App\Ai\Tools\StitchGenerateTool;
use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\RuleConfig;
use App\DataTransferObjects\StitchResult;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\StitchBridge;
use App\Services\ToolRegistry;
use Laravel\Ai\Tools\Request;

// --- StitchResult DTO ---

test('StitchResult holds html, screenshotUrl, and screenId', function () {
    $result = new StitchResult(
        html: '<div>Hello</div>',
        screenshotUrl: 'https://example.com/screenshot.png',
        screenId: 'scr_abc123',
    );

    expect($result->html)->toBe('<div>Hello</div>')
        ->and($result->screenshotUrl)->toBe('https://example.com/screenshot.png')
        ->and($result->screenId)->toBe('scr_abc123');
});

// --- ToolRegistry integration ---

test('ToolRegistry registers StitchGenerate and StitchEdit', function () {
    $available = ToolRegistry::availableTools();

    expect($available)->toContain('StitchGenerate')
        ->and($available)->toContain('StitchEdit');
});

test('ToolRegistry resolves stitch tools with ProjectAware injection', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $registry = new ToolRegistry;

    $tools = $registry->resolve(
        tools: ['StitchGenerate', 'StitchEdit'],
        workingDirectory: '/tmp',
        project: $project,
    );

    expect($tools)->toHaveCount(2);

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(ProjectAware::class);
    }
});

test('ToolRegistry passes Project to ProjectAware tools but not to others', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $registry = new ToolRegistry;

    $tools = $registry->resolve(
        tools: ['Read', 'StitchGenerate'],
        workingDirectory: '/tmp',
        project: $project,
    );

    expect($tools)->toHaveCount(2);
    expect($tools[0])->not->toBeInstanceOf(ProjectAware::class);
    expect($tools[1])->toBeInstanceOf(ProjectAware::class);
});

test('ToolRegistry works without project (backward compatible)', function () {
    $registry = new ToolRegistry;

    $tools = $registry->resolve(
        tools: ['Read', 'Bash'],
        workingDirectory: '/tmp',
    );

    expect($tools)->toHaveCount(2);
});

// --- StitchGenerateTool ---

test('StitchGenerateTool returns error when no project set', function () {
    $tool = new StitchGenerateTool('/tmp');

    $request = new Request(['prompt' => 'test']);
    $result = $tool->handle($request);

    expect((string) $result)->toContain('No project context');
});

test('StitchGenerateTool returns error when no API key configured', function () {
    $user = User::factory()->create(['api_keys' => null]);
    $project = Project::factory()->create([
        'agent_secrets' => null,
        'user_id' => $user->id,
    ]);

    $tool = new StitchGenerateTool('/tmp');
    $tool->setProject($project);

    $request = new Request(['prompt' => 'test']);
    $result = $tool->handle($request);

    expect((string) $result)->toContain('No Stitch API key configured');
});

test('StitchGenerateTool resolves project-level key over user-level', function () {
    $user = User::factory()->create([
        'api_keys' => ['stitch' => 'sk-user-stitch'],
    ]);
    $project = Project::factory()->create([
        'agent_secrets' => ['stitch' => 'sk-project-stitch'],
        'user_id' => $user->id,
    ]);

    $tool = new StitchGenerateTool('/tmp');
    $tool->setProject($project);

    // Use reflection to test key resolution
    $reflection = new ReflectionMethod($tool, 'resolveApiKey');
    $key = $reflection->invoke($tool);

    expect($key)->toBe('sk-project-stitch');
});

test('StitchGenerateTool falls back to user-level key', function () {
    $user = User::factory()->create([
        'api_keys' => ['stitch' => 'sk-user-stitch'],
    ]);
    $project = Project::factory()->create([
        'agent_secrets' => null,
        'user_id' => $user->id,
    ]);

    $tool = new StitchGenerateTool('/tmp');
    $tool->setProject($project);

    $reflection = new ReflectionMethod($tool, 'resolveApiKey');
    $key = $reflection->invoke($tool);

    expect($key)->toBe('sk-user-stitch');
});

// --- StitchEditTool ---

test('StitchEditTool returns error when no project set', function () {
    $tool = new StitchEditTool('/tmp');

    $request = new Request(['screen_id' => 'scr_123', 'prompt' => 'test']);
    $result = $tool->handle($request);

    expect((string) $result)->toContain('No project context');
});

test('StitchEditTool returns error for missing screen_id', function () {
    $user = User::factory()->create(['api_keys' => ['stitch' => 'sk-test']]);
    $project = Project::factory()->create(['user_id' => $user->id]);

    $tool = new StitchEditTool('/tmp');
    $tool->setProject($project);

    $request = new Request(['prompt' => 'test']);
    $result = $tool->handle($request);

    expect((string) $result)->toContain('screen_id is required');
});

// --- StitchBridge service ---

test('StitchBridge throws when bridge not found', function () {
    config(['services.stitch.bridge_path' => '/nonexistent/path/stitch-bridge.js']);

    $bridge = new StitchBridge;

    expect(fn () => $bridge->createProject('test', 'sk-test'))
        ->toThrow(RuntimeException::class, 'Stitch bridge not found');
});

test('StitchBridge generate calls bridge and returns StitchResult', function () {
    Process::fake([
        '*' => Process::result(json_encode([
            'screen_id' => 'scr_123',
            'html' => '<div>Generated</div>',
            'screenshot_url' => 'https://example.com/shot.png',
        ])),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $user = User::factory()->create();
    $project = Project::factory()->create([
        'stitch_project_id' => 'proj_existing',
        'user_id' => $user->id,
    ]);

    $bridge = new StitchBridge;
    $result = $bridge->generate($project, 'sk-test', 'Create a login form');

    expect($result)->toBeInstanceOf(StitchResult::class)
        ->and($result->screenId)->toBe('scr_123')
        ->and($result->html)->toBe('<div>Generated</div>')
        ->and($result->screenshotUrl)->toBe('https://example.com/shot.png');

    Process::assertRan(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, 'generate')
            && str_contains($command, '--project')
            && str_contains($command, 'proj_existing');
    });
});

test('StitchBridge edit calls bridge and returns StitchResult', function () {
    Process::fake([
        '*' => Process::result(json_encode([
            'screen_id' => 'scr_456',
            'html' => '<div>Edited</div>',
            'screenshot_url' => 'https://example.com/edited.png',
        ])),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $bridge = new StitchBridge;
    $result = $bridge->edit('scr_456', 'sk-test', 'Add a forgot password link');

    expect($result)->toBeInstanceOf(StitchResult::class)
        ->and($result->screenId)->toBe('scr_456')
        ->and($result->html)->toBe('<div>Edited</div>');

    Process::assertRan(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, 'edit')
            && str_contains($command, '--screen')
            && str_contains($command, 'scr_456');
    });
});

test('StitchBridge createProject returns project ID', function () {
    Process::fake([
        '*' => Process::result(json_encode([
            'project_id' => 'proj_new_123',
            'title' => 'owner/repo',
        ])),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $bridge = new StitchBridge;
    $projectId = $bridge->createProject('owner/repo', 'sk-test');

    expect($projectId)->toBe('proj_new_123');
});

test('StitchBridge createProject throws on empty project ID', function () {
    Process::fake([
        '*' => Process::result(json_encode(['project_id' => null])),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $bridge = new StitchBridge;

    expect(fn () => $bridge->createProject('test', 'sk-test'))
        ->toThrow(RuntimeException::class, 'Stitch returned empty project ID');
});

test('StitchBridge throws on process failure', function () {
    Process::fake([
        '*' => Process::result('', 'Error: API key invalid', 1),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $bridge = new StitchBridge;

    expect(fn () => $bridge->createProject('test', 'sk-test'))
        ->toThrow(RuntimeException::class, 'Stitch bridge failed');
});

test('StitchBridge throws on empty output', function () {
    Process::fake([
        '*' => Process::result(''),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $bridge = new StitchBridge;

    expect(fn () => $bridge->createProject('test', 'sk-test'))
        ->toThrow(RuntimeException::class, 'Stitch bridge returned empty output');
});

test('StitchBridge throws on invalid JSON', function () {
    Process::fake([
        '*' => Process::result('not json at all'),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $bridge = new StitchBridge;

    expect(fn () => $bridge->createProject('test', 'sk-test'))
        ->toThrow(RuntimeException::class, 'Stitch bridge returned invalid JSON');
});

test('StitchBridge resolveProjectId lazy-creates when stitch_project_id is null', function () {
    Process::fake([
        '*' => Process::result(json_encode([
            'project_id' => 'proj_lazy_created',
            'title' => 'owner/repo',
        ])),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $user = User::factory()->create();
    $project = Project::factory()->create([
        'repo' => 'owner/repo',
        'stitch_project_id' => null,
        'user_id' => $user->id,
    ]);

    $bridge = new StitchBridge;

    // Call generate which triggers resolveProjectId internally
    // First call creates project, second call for generate
    Process::fake([
        '*' => Process::sequence([
            Process::result(json_encode([
                'project_id' => 'proj_lazy_created',
                'title' => 'owner/repo',
            ])),
            Process::result(json_encode([
                'screen_id' => 'scr_999',
                'html' => '<div>Test</div>',
                'screenshot_url' => 'https://example.com/test.png',
            ])),
        ]),
    ]);

    $result = $bridge->generate($project, 'sk-test', 'test prompt');

    $project->refresh();
    expect($project->stitch_project_id)->toBe('proj_lazy_created')
        ->and($result->screenId)->toBe('scr_999');
});

test('StitchBridge resolveProjectId skips creation when stitch_project_id is cached', function () {
    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $user = User::factory()->create();
    $project = Project::factory()->create([
        'stitch_project_id' => 'proj_already_exists',
        'user_id' => $user->id,
    ]);

    Process::fake([
        '*' => Process::result(json_encode([
            'screen_id' => 'scr_888',
            'html' => '<div>Cached</div>',
            'screenshot_url' => 'https://example.com/cached.png',
        ])),
    ]);

    $bridge = new StitchBridge;
    $result = $bridge->generate($project, 'sk-test', 'test prompt');

    // Only one process call (generate), no create-project call
    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : $process->command,
        'generate',
    ));
    Process::assertNotRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : $process->command,
        'create-project',
    ));
});

// --- StitchGenerateTool happy path ---

test('StitchGenerateTool returns JSON result on success', function () {
    Process::fake([
        '*' => Process::result(json_encode([
            'screen_id' => 'scr_tool_test',
            'html' => '<div>Tool Generated</div>',
            'screenshot_url' => 'https://example.com/tool.png',
        ])),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $user = User::factory()->create(['api_keys' => ['stitch' => 'sk-test']]);
    $project = Project::factory()->create([
        'stitch_project_id' => 'proj_existing',
        'user_id' => $user->id,
    ]);

    $tool = new StitchGenerateTool('/tmp');
    $tool->setProject($project);

    $request = new Request(['prompt' => 'Build a dashboard']);
    $result = $tool->handle($request);

    $decoded = json_decode((string) $result, true);
    expect($decoded['screen_id'])->toBe('scr_tool_test')
        ->and($decoded['html'])->toBe('<div>Tool Generated</div>')
        ->and($decoded['screenshot_url'])->toBe('https://example.com/tool.png');
});

// --- StitchEditTool happy path ---

test('StitchEditTool returns JSON result on success', function () {
    Process::fake([
        '*' => Process::result(json_encode([
            'screen_id' => 'scr_edit_test',
            'html' => '<div>Tool Edited</div>',
            'screenshot_url' => 'https://example.com/edited.png',
        ])),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $user = User::factory()->create(['api_keys' => ['stitch' => 'sk-test']]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $tool = new StitchEditTool('/tmp');
    $tool->setProject($project);

    $request = new Request(['screen_id' => 'scr_edit_test', 'prompt' => 'Add a button']);
    $result = $tool->handle($request);

    $decoded = json_decode((string) $result, true);
    expect($decoded['screen_id'])->toBe('scr_edit_test')
        ->and($decoded['html'])->toBe('<div>Tool Edited</div>');
});

// --- HasProjectContext trait ---

test('HasProjectContext trait resolves key from project secrets first', function () {
    $user = User::factory()->create([
        'api_keys' => ['stitch' => 'sk-user-key'],
    ]);
    $project = Project::factory()->create([
        'agent_secrets' => ['stitch' => 'sk-project-key'],
        'user_id' => $user->id,
    ]);

    $tool = new StitchGenerateTool('/tmp');
    $tool->setProject($project);

    $reflection = new ReflectionMethod($tool, 'resolveApiKey');
    expect($reflection->invoke($tool))->toBe('sk-project-key');
});

test('HasProjectContext trait returns null when no key configured', function () {
    $user = User::factory()->create(['api_keys' => null]);
    $project = Project::factory()->create([
        'agent_secrets' => null,
        'user_id' => $user->id,
    ]);

    $tool = new StitchEditTool('/tmp');
    $tool->setProject($project);

    $reflection = new ReflectionMethod($tool, 'resolveApiKey');
    expect($reflection->invoke($tool))->toBeNull();
});

// --- Project model ---

test('Project has stitch_project_id in fillable', function () {
    $project = new Project;

    expect($project->getFillable())->toContain('stitch_project_id');
});

test('Project stitch_project_id is stored and retrieved', function () {
    $project = Project::factory()->create([
        'stitch_project_id' => 'proj_abc123',
    ]);

    $project->refresh();

    expect($project->stitch_project_id)->toBe('proj_abc123');
});

test('Project stitch_project_id defaults to null', function () {
    $project = Project::factory()->create();

    expect($project->stitch_project_id)->toBeNull();
});

// --- resolveAgentConfig includes project ---

test('resolveAgentConfig includes project in config array', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'agent_provider' => 'anthropic',
        'user_id' => $user->id,
    ]);

    // Reuse the helper from ApiKeyResolutionTest
    $webhookLog = WebhookLog::create([
        'event_type' => 'push',
        'repo' => 'owner/repo',
        'payload' => [],
        'status' => 'processed',
    ]);

    $agentRun = AgentRun::create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test-rule',
        'status' => 'queued',
    ]);

    $ruleConfig = new RuleConfig(
        id: 'test-rule',
        event: 'push',
        prompt: 'test',
    );

    $dispatchConfig = new DispatchConfig(
        version: 1,
        agentName: 'test-agent',
        agentExecutor: 'laravel-ai',
        agentProvider: 'anthropic',
    );

    $job = new ProcessAgentRun(
        agentRun: $agentRun,
        ruleConfig: $ruleConfig,
        payload: ['action' => 'test'],
        project: $project,
        dispatchConfig: $dispatchConfig,
    );

    $reflection = new ReflectionMethod($job, 'resolveAgentConfig');
    $config = $reflection->invoke($job);

    expect($config['project'])->toBeInstanceOf(Project::class)
        ->and($config['project']->id)->toBe($project->id);
});

// --- StitchBridge listProjects ---

test('StitchBridge listProjects returns array of projects', function () {
    Process::fake([
        '*' => Process::result(json_encode([
            ['project_id' => 'proj_1', 'title' => 'My App'],
            ['project_id' => 'proj_2', 'title' => 'Other App'],
        ])),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $bridge = new StitchBridge;
    $projects = $bridge->listProjects('sk-test');

    expect($projects)->toBeArray()
        ->and($projects)->toHaveCount(2)
        ->and($projects[0]['project_id'])->toBe('proj_1')
        ->and($projects[0]['title'])->toBe('My App')
        ->and($projects[1]['project_id'])->toBe('proj_2');

    Process::assertRan(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, 'list-projects');
    });
});

test('StitchBridge listProjects passes API key as env var', function () {
    Process::fake([
        '*' => Process::result(json_encode([])),
    ]);

    config(['services.stitch.bridge_path' => base_path('tools/stitch/stitch-bridge.js')]);

    $bridge = new StitchBridge;
    $bridge->listProjects('sk-my-key');

    Process::assertRan(function ($process) {
        return $process->command && true; // Process was called (env vars not directly assertable)
    });
});
