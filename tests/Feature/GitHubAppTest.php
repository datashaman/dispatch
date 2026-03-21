<?php

use App\Models\GitHubInstallation;
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

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents($keyPath, $pem);

    config(['services.github.app_id' => '99999']);
    config(['services.github.app_private_key_path' => $keyPath]);

    $service = app(GitHubAppService::class);
    $jwt = $service->generateJwt();

    $parts = explode('.', $jwt);
    expect($parts)->toHaveCount(3);

    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    expect($header['alg'])->toBe('RS256');
    expect($header['typ'])->toBe('JWT');

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
