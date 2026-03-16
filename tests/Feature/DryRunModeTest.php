<?php

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.github.verify_webhook_signature' => false]);

    $this->tempDir = sys_get_temp_dir().'/dispatch-dryrun-test-'.uniqid();
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

function writeDryRunYaml(string $dir, array $rules): void
{
    $config = [
        'version' => 1,
        'agent' => [
            'name' => 'test',
            'executor' => 'laravel-ai',
        ],
        'rules' => $rules,
    ];

    file_put_contents($dir.'/dispatch.yml', Yaml::dump($config, 10, 2));
}

function dryRunPayload(array $overrides = []): array
{
    return array_merge([
        'action' => 'labeled',
        'repository' => [
            'full_name' => 'owner/repo',
        ],
        'issue' => [
            'number' => 42,
            'title' => 'Test issue',
            'user' => [
                'login' => 'testuser',
            ],
        ],
        'label' => [
            'name' => 'bug',
        ],
        'sender' => [
            'login' => 'testuser',
        ],
    ], $overrides);
}

it('returns matched rules with rendered prompts in dry-run mode', function (): void {
    writeDryRunYaml($this->tempDir, [
        [
            'id' => 'analyze',
            'name' => 'Analyze Issue',
            'event' => 'issues.labeled',
            'prompt' => 'Analyze issue #{{ event.issue.number }}: {{ event.issue.title }}',
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $response = $this->postJson('/api/webhook?dry-run=true', dryRunPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'issues.labeled',
            'matched' => 1,
            'dryRun' => true,
            'results' => [
                [
                    'rule' => 'analyze',
                    'name' => 'Analyze Issue',
                    'prompt' => 'Analyze issue #42: Test issue',
                ],
            ],
        ]);
});

it('does not dispatch jobs in dry-run mode', function (): void {
    writeDryRunYaml($this->tempDir, [
        [
            'id' => 'analyze',
            'event' => 'issues.labeled',
            'prompt' => 'Do something',
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $response = $this->postJson('/api/webhook?dry-run=true', dryRunPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson(['dryRun' => true]);

    $this->assertDatabaseCount('agent_runs', 0);
});

it('returns empty results when no rules match in dry-run mode', function (): void {
    writeDryRunYaml($this->tempDir, [
        ['id' => 'push-only', 'event' => 'push', 'prompt' => 'Push only'],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $response = $this->postJson('/api/webhook?dry-run=true', dryRunPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'event' => 'issues.labeled',
            'matched' => 0,
            'dryRun' => true,
            'results' => [],
        ]);
});

it('returns multiple matched rules in dry-run mode', function (): void {
    writeDryRunYaml($this->tempDir, [
        [
            'id' => 'first',
            'name' => 'First Rule',
            'event' => 'issues.labeled',
            'prompt' => 'First: {{ event.issue.title }}',
            'sort_order' => 1,
        ],
        [
            'id' => 'second',
            'name' => 'Second Rule',
            'event' => 'issues.labeled',
            'prompt' => 'Second: {{ event.label.name }}',
            'sort_order' => 2,
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $response = $this->postJson('/api/webhook?dry-run=true', dryRunPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'matched' => 2,
            'dryRun' => true,
            'results' => [
                [
                    'rule' => 'first',
                    'name' => 'First Rule',
                    'prompt' => 'First: Test issue',
                ],
                [
                    'rule' => 'second',
                    'name' => 'Second Rule',
                    'prompt' => 'Second: bug',
                ],
            ],
        ]);
});

it('still logs the webhook in dry-run mode', function (): void {
    writeDryRunYaml($this->tempDir, [
        ['id' => 'push-only', 'event' => 'push', 'prompt' => 'Push only'],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $this->postJson('/api/webhook?dry-run=true', dryRunPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $this->assertDatabaseCount('webhook_logs', 1);
    $this->assertDatabaseHas('webhook_logs', [
        'event_type' => 'issues.labeled',
        'status' => 'processed',
    ]);
});

it('processes normally without dry-run parameter', function (): void {
    writeDryRunYaml($this->tempDir, [
        [
            'id' => 'analyze',
            'event' => 'issues.labeled',
            'prompt' => 'Do something',
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $response = $this->postJson('/api/webhook', dryRunPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJsonMissing(['dryRun' => true]);
});

it('renders prompts with nested payload paths in dry-run mode', function (): void {
    writeDryRunYaml($this->tempDir, [
        [
            'id' => 'analyze',
            'name' => 'Analyze',
            'event' => 'issues.labeled',
            'prompt' => 'User {{ event.issue.user.login }} filed issue #{{ event.issue.number }}',
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $response = $this->postJson('/api/webhook?dry-run=true', dryRunPayload(), [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson([
            'dryRun' => true,
            'results' => [
                [
                    'prompt' => 'User testuser filed issue #42',
                ],
            ],
        ]);
});
