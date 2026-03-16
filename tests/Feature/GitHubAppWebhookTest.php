<?php

use App\Models\GitHubInstallation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an installation on installation.created webhook', function (): void {
    $payload = [
        'action' => 'created',
        'installation' => [
            'id' => 12345,
            'account' => [
                'login' => 'test-org',
                'type' => 'Organization',
                'id' => 999,
            ],
            'permissions' => ['issues' => 'write', 'pull_requests' => 'write'],
            'events' => ['issues', 'pull_request'],
            'target_type' => 'Organization',
        ],
    ];

    $response = $this->postJson('/api/github/webhook', $payload, [
        'X-GitHub-Event' => 'installation',
    ]);

    $response->assertOk()
        ->assertJson(['ok' => true, 'action' => 'created']);

    expect(GitHubInstallation::where('installation_id', 12345)->exists())->toBeTrue();

    $installation = GitHubInstallation::where('installation_id', 12345)->first();
    expect($installation->account_login)->toBe('test-org');
    expect($installation->account_type)->toBe('Organization');
});

it('deletes an installation on installation.deleted webhook', function (): void {
    GitHubInstallation::factory()->create(['installation_id' => 12345]);

    $payload = [
        'action' => 'deleted',
        'installation' => [
            'id' => 12345,
            'account' => ['login' => 'test-org', 'type' => 'Organization', 'id' => 999],
        ],
    ];

    $response = $this->postJson('/api/github/webhook', $payload, [
        'X-GitHub-Event' => 'installation',
    ]);

    $response->assertOk()
        ->assertJson(['ok' => true, 'action' => 'deleted']);

    expect(GitHubInstallation::where('installation_id', 12345)->exists())->toBeFalse();
});

it('suspends an installation on installation.suspend webhook', function (): void {
    GitHubInstallation::factory()->create(['installation_id' => 12345, 'suspended_at' => null]);

    $payload = [
        'action' => 'suspend',
        'installation' => [
            'id' => 12345,
            'account' => ['login' => 'test-org', 'type' => 'Organization', 'id' => 999],
        ],
    ];

    $response = $this->postJson('/api/github/webhook', $payload, [
        'X-GitHub-Event' => 'installation',
    ]);

    $response->assertOk()
        ->assertJson(['ok' => true, 'action' => 'suspended']);

    expect(GitHubInstallation::where('installation_id', 12345)->first()->suspended_at)->not->toBeNull();
});

it('unsuspends an installation on installation.unsuspend webhook', function (): void {
    GitHubInstallation::factory()->create(['installation_id' => 12345, 'suspended_at' => now()]);

    $payload = [
        'action' => 'unsuspend',
        'installation' => [
            'id' => 12345,
            'account' => ['login' => 'test-org', 'type' => 'Organization', 'id' => 999],
        ],
    ];

    $response = $this->postJson('/api/github/webhook', $payload, [
        'X-GitHub-Event' => 'installation',
    ]);

    $response->assertOk()
        ->assertJson(['ok' => true, 'action' => 'unsuspended']);

    expect(GitHubInstallation::where('installation_id', 12345)->first()->suspended_at)->toBeNull();
});

it('skips non-installation webhook events', function (): void {
    $response = $this->postJson('/api/github/webhook', ['action' => 'opened'], [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertOk()
        ->assertJson(['ok' => true, 'skipped' => true]);
});

it('returns 422 when installation data is missing', function (): void {
    $response = $this->postJson('/api/github/webhook', ['action' => 'created'], [
        'X-GitHub-Event' => 'installation',
    ]);

    $response->assertStatus(422)
        ->assertJson(['ok' => false]);
});
