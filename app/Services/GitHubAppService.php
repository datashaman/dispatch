<?php

namespace App\Services;

use App\Models\GitHubInstallation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GitHubAppService
{
    protected const string API_BASE = 'https://api.github.com';

    public function isConfigured(): bool
    {
        return ! empty(config('services.github.app_id'))
            && $this->resolvePrivateKey() !== null;
    }

    /**
     * Generate a JWT for authenticating as the GitHub App.
     */
    public function generateJwt(): string
    {
        $now = time();
        $payload = [
            'iat' => $now - 60,
            'exp' => $now + (9 * 60),
            'iss' => config('services.github.app_id'),
        ];

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode(json_encode($payload));

        $pem = $this->resolvePrivateKey();

        if (! $pem) {
            throw new \RuntimeException('GitHub App private key not found. Set GITHUB_APP_PRIVATE_KEY or GITHUB_APP_PRIVATE_KEY_PATH.');
        }

        $privateKey = openssl_pkey_get_private($pem);

        if (! $privateKey) {
            throw new \RuntimeException('Failed to parse GitHub App private key.');
        }

        openssl_sign("{$header}.{$claims}", $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return "{$header}.{$claims}.".$this->base64UrlEncode($signature);
    }

    /**
     * Get an installation access token (cached until near-expiry).
     */
    public function getInstallationToken(int $installationId): string
    {
        $cacheKey = "github_installation_token_{$installationId}";

        return Cache::remember($cacheKey, 3500, function () use ($installationId): string {
            $response = $this->appRequest()
                ->post(self::API_BASE."/app/installations/{$installationId}/access_tokens");

            $response->throw();

            return $response->json('token');
        });
    }

    /**
     * List all installations of this GitHub App.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInstallations(): array
    {
        $response = $this->appRequest()
            ->get(self::API_BASE.'/app/installations');

        $response->throw();

        return $response->json();
    }

    /**
     * List repositories accessible to a specific installation.
     *
     * @return array{total_count: int, repositories: array<int, array<string, mixed>>}
     */
    public function listRepositories(int $installationId, int $page = 1, int $perPage = 100): array
    {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->get(self::API_BASE.'/installation/repositories', [
                'page' => $page,
                'per_page' => $perPage,
            ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Sync installations from GitHub to the database.
     *
     * @return array{created: int, updated: int, removed: int}
     */
    public function syncInstallations(): array
    {
        $remoteInstallations = $this->listInstallations();
        $remoteIds = collect($remoteInstallations)->pluck('id');

        $created = 0;
        $updated = 0;

        foreach ($remoteInstallations as $installation) {
            $record = GitHubInstallation::updateOrCreate(
                ['installation_id' => $installation['id']],
                [
                    'account_login' => $installation['account']['login'],
                    'account_type' => $installation['account']['type'],
                    'account_id' => $installation['account']['id'],
                    'permissions' => $installation['permissions'] ?? [],
                    'events' => $installation['events'] ?? [],
                    'target_type' => $installation['target_type'] ?? 'Organization',
                    'suspended_at' => $installation['suspended_at'] ?? null,
                ],
            );

            $record->wasRecentlyCreated ? $created++ : $updated++;
        }

        $removed = GitHubInstallation::whereNotIn('installation_id', $remoteIds)->delete();

        return compact('created', 'updated', 'removed');
    }

    /**
     * Get the GitHub App's metadata (name, slug, description, etc).
     *
     * @return array<string, mixed>
     */
    public function getApp(): array
    {
        $response = $this->appRequest()
            ->get(self::API_BASE.'/app');

        $response->throw();

        return $response->json();
    }

    /**
     * Build the URL to install this GitHub App.
     */
    public function getInstallUrl(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $app = $this->getApp();

            return $app['html_url'].'/installations/new';
        } catch (\Throwable) {
            return null;
        }
    }

    protected function appRequest(): PendingRequest
    {
        return Http::withToken($this->generateJwt())
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
    }

    /**
     * Resolve the private key PEM contents from either a base64 env var or a file path.
     */
    protected function resolvePrivateKey(): ?string
    {
        $base64 = config('services.github.app_private_key');

        if (! empty($base64)) {
            $decoded = base64_decode($base64, true);

            return $decoded !== false ? $decoded : null;
        }

        $path = config('services.github.app_private_key_path', '');

        if (! empty($path) && file_exists($path)) {
            return file_get_contents($path);
        }

        return null;
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
