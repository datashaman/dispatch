<?php

use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('github settings page is accessible to authenticated users', function () {
    $this->get(route('github.settings'))
        ->assertStatus(200);
});

test('github settings page requires authentication', function () {
    auth()->logout();

    $this->get(route('github.settings'))
        ->assertRedirect(route('login'));
});

test('github settings shows not configured when env vars are missing', function () {
    config(['services.github.app_id' => null]);
    config(['services.github.app_private_key' => null]);
    config(['services.github.app_private_key_path' => null]);

    Volt::test('pages::settings.github')
        ->assertSee('Not Configured')
        ->assertSee('Set GITHUB_APP_ID');
});

test('github settings shows installations when configured', function () {
    $keyPath = tempnam(sys_get_temp_dir(), 'gh_key_');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents($keyPath, $pem);

    config(['services.github.app_id' => '12345']);
    config(['services.github.app_private_key_path' => $keyPath]);

    Http::fake([
        'api.github.com/app' => Http::response([
            'name' => 'test-dispatch-app',
            'html_url' => 'https://github.com/apps/test-dispatch-app',
        ]),
    ]);

    GitHubInstallation::factory()->create([
        'installation_id' => 12345,
        'account_login' => 'my-org',
        'account_type' => 'Organization',
    ]);

    Volt::test('pages::settings.github')
        ->assertSee('my-org')
        ->assertSee('Organization');

    unlink($keyPath);
});

test('github settings appears in settings navigation', function () {
    $this->get(route('github.settings'))
        ->assertSee('GitHub App');
});

test('github repos page is accessible', function () {
    $installation = GitHubInstallation::factory()->create();

    $this->get(route('github.repos', $installation))
        ->assertStatus(200);
});

test('github repos page requires authentication', function () {
    auth()->logout();
    $installation = GitHubInstallation::factory()->create();

    $this->get(route('github.repos', $installation))
        ->assertRedirect(route('login'));
});

test('github installation has projects relationship', function () {
    $installation = GitHubInstallation::factory()->create();

    expect($installation->projects)->toBeEmpty();
});

test('project belongs to github installation', function () {
    $installation = GitHubInstallation::factory()->create();
    $project = Project::factory()->create([
        'github_installation_id' => $installation->id,
    ]);

    expect($project->githubInstallation->id)->toBe($installation->id);
    expect($installation->fresh()->projects)->toHaveCount(1);
});
