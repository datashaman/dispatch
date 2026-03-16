<?php

use App\Models\GitHubInstallation;
use App\Models\User;
use App\Services\GitHubAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.github.app_id' => null,
        'services.github.app_private_key' => null,
        'services.github.app_private_key_path' => null,
    ]);
});

it('reports not configured when env vars are missing', function (): void {
    config(['services.github.app_id' => null]);
    config(['services.github.app_private_key' => null]);
    config(['services.github.app_private_key_path' => null]);

    $service = app(GitHubAppService::class);

    expect($service->isConfigured())->toBeFalse();
});

it('reports configured when app id and key path are set', function (): void {
    $keyPath = tempnam(sys_get_temp_dir(), 'gh_key_');
    file_put_contents($keyPath, 'fake-key');

    config(['services.github.app_id' => '12345']);
    config(['services.github.app_private_key_path' => $keyPath]);

    $service = app(GitHubAppService::class);

    expect($service->isConfigured())->toBeTrue();

    unlink($keyPath);
});

it('reports configured when app id and base64 key are set', function (): void {
    config(['services.github.app_id' => '12345']);
    config(['services.github.app_private_key' => base64_encode('fake-key')]);
    config(['services.github.app_private_key_path' => null]);

    $service = app(GitHubAppService::class);

    expect($service->isConfigured())->toBeTrue();
});

it('generates a valid JWT from a base64 key', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    config(['services.github.app_id' => '88888']);
    config(['services.github.app_private_key' => base64_encode($pem)]);
    config(['services.github.app_private_key_path' => null]);

    $service = app(GitHubAppService::class);
    $jwt = $service->generateJwt();

    $parts = explode('.', $jwt);
    expect($parts)->toHaveCount(3);

    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    expect($payload['iss'])->toBe('88888');
});

it('generates a valid JWT structure', function (): void {
    $keyPath = tempnam(sys_get_temp_dir(), 'gh_key_');

    // Generate a test RSA key
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents($keyPath, $pem);

    config(['services.github.app_id' => '99999']);
    config(['services.github.app_private_key_path' => $keyPath]);

    $service = app(GitHubAppService::class);
    $jwt = $service->generateJwt();

    // JWT has three parts
    $parts = explode('.', $jwt);
    expect($parts)->toHaveCount(3);

    // Decode header
    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    expect($header['alg'])->toBe('RS256');
    expect($header['typ'])->toBe('JWT');

    // Decode payload
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    expect($payload['iss'])->toBe('99999');
    expect($payload)->toHaveKeys(['iat', 'exp', 'iss']);

    unlink($keyPath);
});

it('syncs installations from the GitHub API', function (): void {
    $keyPath = tempnam(sys_get_temp_dir(), 'gh_key_');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents($keyPath, $pem);

    config(['services.github.app_id' => '12345']);
    config(['services.github.app_private_key_path' => $keyPath]);

    Http::fake([
        'api.github.com/app/installations' => Http::response([
            [
                'id' => 100,
                'account' => ['login' => 'test-org', 'type' => 'Organization', 'id' => 555],
                'permissions' => ['issues' => 'write'],
                'events' => ['issues'],
                'target_type' => 'Organization',
                'suspended_at' => null,
            ],
            [
                'id' => 200,
                'account' => ['login' => 'test-user', 'type' => 'User', 'id' => 666],
                'permissions' => ['pull_requests' => 'write'],
                'events' => ['pull_request'],
                'target_type' => 'User',
                'suspended_at' => null,
            ],
        ]),
    ]);

    $service = app(GitHubAppService::class);
    $result = $service->syncInstallations();

    expect($result['created'])->toBe(2);
    expect($result['updated'])->toBe(0);
    expect($result['removed'])->toBe(0);

    expect(GitHubInstallation::count())->toBe(2);
    expect(GitHubInstallation::where('installation_id', 100)->first()->account_login)->toBe('test-org');
    expect(GitHubInstallation::where('installation_id', 200)->first()->account_login)->toBe('test-user');

    unlink($keyPath);
});

it('removes stale installations during sync', function (): void {
    $keyPath = tempnam(sys_get_temp_dir(), 'gh_key_');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents($keyPath, $pem);

    config(['services.github.app_id' => '12345']);
    config(['services.github.app_private_key_path' => $keyPath]);

    // Pre-existing installation that no longer exists on GitHub
    GitHubInstallation::factory()->create(['installation_id' => 999, 'account_login' => 'stale-org']);

    Http::fake([
        'api.github.com/app/installations' => Http::response([]),
    ]);

    $service = app(GitHubAppService::class);
    $result = $service->syncInstallations();

    expect($result['removed'])->toBe(1);
    expect(GitHubInstallation::count())->toBe(0);

    unlink($keyPath);
});

it('lists repositories for an installation', function (): void {
    $keyPath = tempnam(sys_get_temp_dir(), 'gh_key_');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents($keyPath, $pem);

    config(['services.github.app_id' => '12345']);
    config(['services.github.app_private_key_path' => $keyPath]);

    Http::fake([
        'api.github.com/app/installations/100/access_tokens' => Http::response([
            'token' => 'ghs_test_token_123',
        ]),
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                ['id' => 1, 'full_name' => 'org/repo-a', 'private' => false, 'description' => 'First repo', 'language' => 'PHP'],
                ['id' => 2, 'full_name' => 'org/repo-b', 'private' => true, 'description' => 'Second repo', 'language' => 'JavaScript'],
            ],
        ]),
    ]);

    $service = app(GitHubAppService::class);
    $result = $service->listRepositories(100);

    expect($result['total_count'])->toBe(2);
    expect($result['repositories'])->toHaveCount(2);
    expect($result['repositories'][0]['full_name'])->toBe('org/repo-a');

    unlink($keyPath);
});

it('builds a manifest with correct structure', function (): void {
    $service = app(GitHubAppService::class);
    $manifest = $service->buildManifest('https://dispatch.example.com');

    expect($manifest['url'])->toBe('https://dispatch.example.com');
    expect($manifest['hook_attributes']['url'])->toBe('https://dispatch.example.com/api/webhook');
    expect($manifest['redirect_url'])->toBe('https://dispatch.example.com/github/manifest/callback');
    expect($manifest['setup_url'])->toBe('https://dispatch.example.com/github/callback');
    expect($manifest['public'])->toBeFalse();
    expect($manifest['default_permissions'])->toHaveKeys(['issues', 'pull_requests', 'contents', 'metadata']);
    expect($manifest['default_events'])->toContain('issues', 'pull_request', 'push', 'discussion');
    expect($manifest['default_events'])->not->toContain('installation');
    expect($manifest['default_permissions'])->toHaveKeys(['issues', 'pull_requests', 'contents', 'metadata', 'discussions']);
});

it('exchanges a manifest code for credentials', function (): void {
    Http::fake([
        'api.github.com/app-manifests/test-code-123/conversions' => Http::response([
            'id' => 54321,
            'slug' => 'dispatch-abcd',
            'name' => 'Dispatch-abcd',
            'pem' => "-----BEGIN RSA PRIVATE KEY-----\nfake\n-----END RSA PRIVATE KEY-----\n",
            'webhook_secret' => 'wh_secret_abc',
            'client_id' => 'Iv1.abc123',
            'client_secret' => 'cs_secret_xyz',
            'html_url' => 'https://github.com/apps/dispatch-abcd',
        ]),
    ]);

    $service = app(GitHubAppService::class);
    $credentials = $service->exchangeManifestCode('test-code-123');

    expect($credentials['id'])->toBe(54321);
    expect($credentials['slug'])->toBe('dispatch-abcd');
    expect($credentials['pem'])->toContain('RSA PRIVATE KEY');
    expect($credentials['webhook_secret'])->toBe('wh_secret_abc');
});

it('handles the manifest callback and stores credentials', function (): void {
    $user = User::factory()->create();

    Http::fake([
        'api.github.com/app-manifests/abc123/conversions' => Http::response([
            'id' => 99999,
            'slug' => 'my-dispatch',
            'name' => 'My Dispatch',
            'pem' => "-----BEGIN RSA PRIVATE KEY-----\nfake\n-----END RSA PRIVATE KEY-----\n",
            'webhook_secret' => 'wh_secret_test',
            'client_id' => 'Iv1.test',
            'client_secret' => 'cs_test',
            'html_url' => 'https://github.com/apps/my-dispatch',
        ]),
        'api.github.com/app/installations' => Http::response([]),
    ]);

    $response = $this->actingAs($user)
        ->get('/github/manifest/callback?code=abc123');

    $response->assertRedirect(route('github.settings'));
    $response->assertSessionHas('status');

    // Verify config was updated
    expect(config('services.github.app_id'))->toBe(99999);
    expect(config('services.github.webhook_secret'))->toBe('wh_secret_test');
});

it('redirects with error when manifest callback has no code', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/github/manifest/callback');

    $response->assertRedirect(route('github.settings'));
    $response->assertSessionHas('error');
});

it('returns personal manifest url by default', function (): void {
    $service = app(GitHubAppService::class);

    expect($service->getManifestCreateUrl())->toBe('https://github.com/settings/apps/new');
});

it('returns org manifest url when organization is specified', function (): void {
    $service = app(GitHubAppService::class);

    expect($service->getManifestCreateUrl('my-org'))->toBe('https://github.com/organizations/my-org/settings/apps/new');
});

it('deletes the app on GitHub and clears local credentials', function (): void {
    $keyPath = tempnam(sys_get_temp_dir(), 'gh_key_');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents($keyPath, $pem);

    config(['services.github.app_id' => '12345']);
    config(['services.github.app_private_key_path' => $keyPath]);

    GitHubInstallation::factory()->create(['installation_id' => 100]);
    GitHubInstallation::factory()->create(['installation_id' => 200]);

    Http::fake([
        'api.github.com/app' => Http::response(null, 204),
    ]);

    $service = app(GitHubAppService::class);
    $service->deleteApp();

    expect(config('services.github.app_id'))->toBeNull();
    expect(config('services.github.app_private_key'))->toBeNull();
    expect(GitHubInstallation::count())->toBe(0);

    unlink($keyPath);
});

it('clears credentials without deleting on GitHub', function (): void {
    config(['services.github.app_id' => '12345']);
    config(['services.github.app_private_key' => base64_encode('fake')]);

    GitHubInstallation::factory()->create();

    Http::fake(); // should not make any HTTP calls

    $service = app(GitHubAppService::class);
    $service->clearCredentials();

    expect(config('services.github.app_id'))->toBeNull();
    expect(GitHubInstallation::count())->toBe(0);
    Http::assertNothingSent();
});
