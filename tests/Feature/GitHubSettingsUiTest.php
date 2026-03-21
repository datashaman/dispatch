<?php

use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

test('github settings shows connected when configured', function () {
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

    Volt::test('pages::settings.github')
        ->assertSee('Connected')
        ->assertSee('test-dispatch-app');

    unlink($keyPath);
});

test('github settings appears in settings navigation', function () {
    $this->get(route('github.settings'))
        ->assertSee('GitHub App');
});

test('github settings shows installations list', function () {
    config(['services.github.app_id' => null]);
    config(['services.github.app_private_key' => null]);
    config(['services.github.app_private_key_path' => null]);

    GitHubInstallation::factory()->create([
        'account_login' => 'test-org',
        'account_type' => 'Organization',
    ]);

    Volt::test('pages::settings.github')
        ->assertSee('Installations')
        ->assertSee('test-org')
        ->assertSee('Active');
});

test('github repos page shows repositories', function () {
    $installation = GitHubInstallation::factory()->create();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'fake-token']),
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                ['id' => 1, 'full_name' => 'org/public-repo', 'name' => 'public-repo', 'description' => 'A public repo', 'private' => false, 'language' => 'PHP'],
                ['id' => 2, 'full_name' => 'org/private-repo', 'name' => 'private-repo', 'description' => 'A private repo', 'private' => true, 'language' => 'PHP'],
            ],
        ]),
    ]);

    Volt::test('pages::settings.github-repos', ['installation' => $installation])
        ->assertSee('org/public-repo')
        ->assertSee('org/private-repo')
        ->assertSee('Private');
});

test('github repos search filters across all pages', function () {
    $installation = GitHubInstallation::factory()->create();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'fake-token']),
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 3,
            'repositories' => [
                ['id' => 1, 'full_name' => 'org/alpha', 'name' => 'alpha', 'description' => null, 'private' => false, 'language' => 'PHP'],
                ['id' => 2, 'full_name' => 'org/beta', 'name' => 'beta', 'description' => null, 'private' => true, 'language' => 'PHP'],
                ['id' => 3, 'full_name' => 'org/alphabetical', 'name' => 'alphabetical', 'description' => null, 'private' => false, 'language' => null],
            ],
        ]),
    ]);

    Cache::flush();

    Volt::test('pages::settings.github-repos', ['installation' => $installation])
        ->set('search', 'alpha')
        ->assertSee('org/alpha')
        ->assertSee('org/alphabetical')
        ->assertDontSee('org/beta');
});

test('github repos search with no matches shows empty state', function () {
    $installation = GitHubInstallation::factory()->create();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'fake-token']),
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 1,
            'repositories' => [
                ['id' => 1, 'full_name' => 'org/some-repo', 'name' => 'some-repo', 'description' => null, 'private' => false, 'language' => null],
            ],
        ]),
    ]);

    Cache::flush();

    Volt::test('pages::settings.github-repos', ['installation' => $installation])
        ->set('search', 'nonexistent')
        ->assertSee('No repositories match your search');
});

test('github repos sort changes order', function () {
    $installation = GitHubInstallation::factory()->create();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'fake-token']),
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                ['id' => 1, 'full_name' => 'org/beta', 'name' => 'beta', 'description' => null, 'private' => false, 'language' => null, 'created_at' => '2025-01-01T00:00:00Z', 'updated_at' => '2025-06-01T00:00:00Z', 'pushed_at' => '2025-06-01T00:00:00Z'],
                ['id' => 2, 'full_name' => 'org/alpha', 'name' => 'alpha', 'description' => null, 'private' => false, 'language' => null, 'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z', 'pushed_at' => '2026-01-01T00:00:00Z'],
            ],
        ]),
    ]);

    Cache::flush();

    // Default sort (full_name asc) — alpha before beta
    $component = Volt::test('pages::settings.github-repos', ['installation' => $installation]);
    $html = $component->html();
    expect(strpos($html, 'org/alpha'))->toBeLessThan(strpos($html, 'org/beta'));

    // Descending — beta before alpha
    $component->set('direction', 'desc');
    $html = $component->html();
    expect(strpos($html, 'org/beta'))->toBeLessThan(strpos($html, 'org/alpha'));
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
