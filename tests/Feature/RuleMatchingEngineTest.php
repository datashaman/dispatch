<?php

use App\Exceptions\RuleMatchingException;
use App\Models\Project;
use App\Models\WebhookLog;
use App\Services\RuleMatchingEngine;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->engine = app(RuleMatchingEngine::class);
    config(['services.github.verify_webhook_signature' => false]);

    $this->tempDir = sys_get_temp_dir().'/dispatch-rule-test-'.uniqid();
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

function writeDispatchYaml(string $dir, array $rules, array $agentOverrides = []): void
{
    $config = array_merge([
        'version' => 1,
        'agent' => array_merge([
            'name' => 'test',
            'executor' => 'laravel-ai',
        ], $agentOverrides),
        'rules' => $rules,
    ]);

    file_put_contents($dir.'/dispatch.yml', Yaml::dump($config, 10, 2));
}

it('throws exception when project not found', function () {
    $this->engine->match('nonexistent/repo', 'issues.labeled', []);
})->throws(RuleMatchingException::class, "Project not found for repo 'nonexistent/repo'");

it('returns empty collection when no rules match event type', function () {
    writeDispatchYaml($this->tempDir, [
        ['id' => 'push-rule', 'event' => 'push', 'prompt' => 'Handle push'],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $result = $this->engine->match('owner/repo', 'issues.labeled', []);

    expect($result)->toBeEmpty();
});

it('matches rule by exact event type', function () {
    writeDispatchYaml($this->tempDir, [
        ['id' => 'analyze', 'event' => 'issues.labeled', 'prompt' => 'Analyze'],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $result = $this->engine->match('owner/repo', 'issues.labeled', []);

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe('analyze');
});

it('returns multiple matching rules in sort_order', function () {
    writeDispatchYaml($this->tempDir, [
        ['id' => 'second', 'event' => 'issues.labeled', 'prompt' => 'Second', 'sort_order' => 2],
        ['id' => 'first', 'event' => 'issues.labeled', 'prompt' => 'First', 'sort_order' => 1],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $result = $this->engine->match('owner/repo', 'issues.labeled', []);

    expect($result)->toHaveCount(2)
        ->and($result[0]->id)->toBe('second')
        ->and($result[1]->id)->toBe('first');
});

it('matches rule with no filters', function () {
    writeDispatchYaml($this->tempDir, [
        ['id' => 'no-filters', 'event' => 'push', 'prompt' => 'Handle push'],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $result = $this->engine->match('owner/repo', 'push', []);

    expect($result)->toHaveCount(1);
});

it('filters using equals operator', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'labeled',
            'event' => 'issues.labeled',
            'prompt' => 'Handle label',
            'filters' => [
                ['id' => 'f1', 'field' => 'label.name', 'operator' => 'equals', 'value' => 'sparky'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $match = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'sparky']]);
    $noMatch = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'other']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using not_equals operator', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'not-bug',
            'event' => 'issues.labeled',
            'prompt' => 'Not a bug',
            'filters' => [
                ['id' => 'f1', 'field' => 'label.name', 'operator' => 'not_equals', 'value' => 'bug'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $match = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'feature']]);
    $noMatch = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'bug']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using contains operator', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'mention',
            'event' => 'issue_comment.created',
            'prompt' => 'Mentioned',
            'filters' => [
                ['id' => 'f1', 'field' => 'comment.body', 'operator' => 'contains', 'value' => '@sparky'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $match = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'Hey @sparky please review']]);
    $noMatch = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'Just a comment']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using not_contains operator', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'no-skip',
            'event' => 'issue_comment.created',
            'prompt' => 'No skip',
            'filters' => [
                ['id' => 'f1', 'field' => 'comment.body', 'operator' => 'not_contains', 'value' => '[skip]'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $match = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'Normal comment']]);
    $noMatch = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'Comment [skip] this']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using starts_with operator', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'command',
            'event' => 'issue_comment.created',
            'prompt' => 'Command',
            'filters' => [
                ['id' => 'f1', 'field' => 'comment.body', 'operator' => 'starts_with', 'value' => '/deploy'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $match = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => '/deploy production']]);
    $noMatch = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'Please /deploy']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using ends_with operator', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'priority',
            'event' => 'issues.labeled',
            'prompt' => 'Priority',
            'filters' => [
                ['id' => 'f1', 'field' => 'label.name', 'operator' => 'ends_with', 'value' => '-priority'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $match = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'high-priority']]);
    $noMatch = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'priority-low']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('filters using matches (regex) operator', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'version',
            'event' => 'issue_comment.created',
            'prompt' => 'Version',
            'filters' => [
                ['id' => 'f1', 'field' => 'comment.body', 'operator' => 'matches', 'value' => '/^v\d+\.\d+\.\d+$/'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $match = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'v1.2.3']]);
    $noMatch = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => 'version 1.2.3']]);

    expect($match)->toHaveCount(1)
        ->and($noMatch)->toBeEmpty();
});

it('requires all filters to pass (AND logic)', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'implement',
            'event' => 'issue_comment.created',
            'prompt' => 'Implement',
            'filters' => [
                ['id' => 'f1', 'field' => 'comment.body', 'operator' => 'contains', 'value' => '@sparky'],
                ['id' => 'f2', 'field' => 'comment.body', 'operator' => 'contains', 'value' => 'implement'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $match = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => '@sparky implement this']]);
    $partialMatch = $this->engine->match('owner/repo', 'issue_comment.created', ['comment' => ['body' => '@sparky review this']]);

    expect($match)->toHaveCount(1)
        ->and($partialMatch)->toBeEmpty();
});

it('resolves dot-path fields with event prefix', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'with-prefix',
            'event' => 'issues.labeled',
            'prompt' => 'With prefix',
            'filters' => [
                ['id' => 'f1', 'field' => 'event.label.name', 'operator' => 'equals', 'value' => 'sparky'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $result = $this->engine->match('owner/repo', 'issues.labeled', ['label' => ['name' => 'sparky']]);

    expect($result)->toHaveCount(1);
});

it('resolves deeply nested dot-path fields', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'nested',
            'event' => 'issues.opened',
            'prompt' => 'Nested',
            'filters' => [
                ['id' => 'f1', 'field' => 'issue.user.login', 'operator' => 'equals', 'value' => 'octocat'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $result = $this->engine->match('owner/repo', 'issues.opened', ['issue' => ['user' => ['login' => 'octocat']]]);

    expect($result)->toHaveCount(1);
});

it('treats missing field path as empty string', function () {
    writeDispatchYaml($this->tempDir, [
        [
            'id' => 'missing-field',
            'event' => 'push',
            'prompt' => 'Missing field',
            'filters' => [
                ['id' => 'f1', 'field' => 'nonexistent.path', 'operator' => 'equals', 'value' => 'something'],
            ],
        ],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $result = $this->engine->match('owner/repo', 'push', ['other' => 'data']);

    expect($result)->toBeEmpty();
});

it('does not match rules from other projects', function () {
    $tempDir2 = sys_get_temp_dir().'/dispatch-rule-test2-'.uniqid();
    mkdir($tempDir2);

    try {
        // repo1 has no rules for push
        writeDispatchYaml($this->tempDir, [
            ['id' => 'not-push', 'event' => 'issues.labeled', 'prompt' => 'Not push'],
        ]);
        Project::factory()->create(['repo' => 'owner/repo1', 'path' => $this->tempDir]);

        // repo2 has a push rule
        writeDispatchYaml($tempDir2, [
            ['id' => 'other-project', 'event' => 'push', 'prompt' => 'Other project'],
        ]);
        Project::factory()->create(['repo' => 'owner/repo2', 'path' => $tempDir2]);

        $result = $this->engine->match('owner/repo1', 'push', []);

        expect($result)->toBeEmpty();
    } finally {
        @unlink($tempDir2.'/dispatch.yml');
        @rmdir($tempDir2);
    }
});

it('integrates with webhook controller for missing project', function () {
    $payload = [
        'action' => 'labeled',
        'repository' => ['full_name' => 'nonexistent/repo'],
        'label' => ['name' => 'sparky'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'error' => "Project not found for repo 'nonexistent/repo'",
        ]);
});

it('integrates with webhook controller for matching rules', function () {
    writeDispatchYaml($this->tempDir, [
        ['id' => 'analyze', 'event' => 'issues.labeled', 'prompt' => 'Analyze'],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'action' => 'labeled',
        'repository' => ['full_name' => 'owner/repo'],
        'label' => ['name' => 'sparky'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'event' => 'issues.labeled',
            'matched' => 1,
        ]);
});

it('integrates with webhook controller for no matching rules', function () {
    writeDispatchYaml($this->tempDir, [
        ['id' => 'push-only', 'event' => 'push', 'prompt' => 'Push only'],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'action' => 'opened',
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'ok' => true,
            'event' => 'issues.opened',
            'matched' => 0,
        ]);
});

it('logs error when project not found', function () {
    Log::shouldReceive('error')
        ->once()
        ->with("Rule matching: project not found for repo 'nonexistent/repo'");

    expect(fn () => $this->engine->match('nonexistent/repo', 'push', []))
        ->toThrow(RuleMatchingException::class);
});

it('updates webhook log with matched rules and processed status', function () {
    writeDispatchYaml($this->tempDir, [
        ['id' => 'analyze', 'event' => 'issues.labeled', 'prompt' => 'Analyze'],
    ]);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => $this->tempDir]);

    $payload = [
        'action' => 'labeled',
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $this->postJson('/api/webhook', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $log = WebhookLog::latest('id')->first();
    expect($log->status)->toBe('processed')
        ->and($log->matched_rules)->toBe(['analyze']);
});
