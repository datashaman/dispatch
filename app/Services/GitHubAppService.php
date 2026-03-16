<?php

namespace App\Services;

use App\Models\GitHubInstallation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * @return array<int, array<string, mixed>>
     */
    public function listRepositories(int $installationId, int $page = 1, int $perPage = 30): array
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

    /**
     * Build the manifest JSON for creating a GitHub App via the manifest flow.
     *
     * @return array<string, mixed>
     */
    public function buildManifest(string $appUrl): array
    {
        return [
            'name' => config('app.name', 'Dispatch').'-'.Str::lower(Str::random(4)),
            'url' => $appUrl,
            'hook_attributes' => [
                'url' => rtrim($appUrl, '/').'/api/webhook',
                'active' => true,
            ],
            'redirect_url' => rtrim($appUrl, '/').'/github/manifest/callback',
            'setup_url' => rtrim($appUrl, '/').'/github/callback',
            'setup_on_update' => true,
            'public' => false,
            'default_permissions' => [
                'issues' => 'write',
                'pull_requests' => 'write',
                'contents' => 'read',
                'metadata' => 'read',
                'discussions' => 'write',
            ],
            'default_events' => [
                'issues',
                'issue_comment',
                'pull_request',
                'pull_request_review',
                'pull_request_review_comment',
                'push',
                'release',
                'create',
                'delete',
                'discussion',
                'discussion_comment',
                'workflow_run',
            ],
        ];
    }

    /**
     * Exchange a manifest code for GitHub App credentials.
     *
     * @return array{id: int, slug: string, pem: string, webhook_secret: string, client_id: string, client_secret: string}
     */
    public function exchangeManifestCode(string $code): array
    {
        $response = Http::accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withBody('', 'application/json')
            ->post(self::API_BASE."/app-manifests/{$code}/conversions");

        $response->throw();

        $data = $response->json();

        return [
            'id' => $data['id'],
            'slug' => $data['slug'] ?? $data['name'] ?? '',
            'pem' => $data['pem'],
            'webhook_secret' => $data['webhook_secret'],
            'client_id' => $data['client_id'] ?? '',
            'client_secret' => $data['client_secret'] ?? '',
            'html_url' => $data['html_url'] ?? '',
            'name' => $data['name'] ?? '',
        ];
    }

    /**
     * Store GitHub App credentials in the .env file.
     */
    public function storeCredentials(array $credentials): void
    {
        $envPath = $this->envPath();
        $env = file_get_contents($envPath);

        Log::info('GitHubAppService::storeCredentials called', [
            'app_id' => $credentials['id'],
            'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        ]);

        $env = $this->setEnvValue($env, 'GITHUB_APP_ID', (string) $credentials['id']);
        $env = $this->setEnvValue($env, 'GITHUB_APP_PRIVATE_KEY', base64_encode($credentials['pem']));
        $env = $this->setEnvValue($env, 'GITHUB_WEBHOOK_SECRET', $credentials['webhook_secret']);
        $env = $this->setEnvValue($env, 'GITHUB_BOT_USERNAME', $credentials['slug'] ?: $credentials['name']);

        file_put_contents($envPath, $env);

        // Update running config immediately
        config([
            'services.github.app_id' => $credentials['id'],
            'services.github.app_private_key' => base64_encode($credentials['pem']),
            'services.github.webhook_secret' => $credentials['webhook_secret'],
            'services.github.bot_username' => $credentials['slug'] ?: $credentials['name'],
        ]);
    }

    /**
     * Delete the GitHub App on GitHub and clear local credentials.
     */
    public function deleteApp(): void
    {
        Log::warning('GitHubAppService::deleteApp called', [
            'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        ]);

        // Delete the app on GitHub (DELETE /app, authenticated as the app via JWT)
        if ($this->isConfigured()) {
            $this->appRequest()->delete(self::API_BASE.'/app')->throw();
        }

        $this->clearCredentials();
    }

    /**
     * Remove GitHub App credentials from .env and clear local state (without deleting on GitHub).
     */
    public function clearCredentials(): void
    {
        Log::warning('GitHubAppService::clearCredentials called', [
            'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        ]);

        $envPath = $this->envPath();
        $env = file_get_contents($envPath);

        $env = $this->setEnvValue($env, 'GITHUB_APP_ID', '');
        $env = $this->setEnvValue($env, 'GITHUB_APP_PRIVATE_KEY', '');
        $env = $this->setEnvValue($env, 'GITHUB_WEBHOOK_SECRET', '');

        file_put_contents($envPath, $env);

        config([
            'services.github.app_id' => null,
            'services.github.app_private_key' => null,
            'services.github.webhook_secret' => null,
        ]);

        // Remove cached installation tokens
        GitHubInstallation::pluck('installation_id')->each(function (int $id): void {
            Cache::forget("github_installation_token_{$id}");
        });

        // Remove local installation records
        GitHubInstallation::query()->delete();
    }

    /**
     * Get the URL to start the manifest flow on GitHub.
     */
    public function getManifestCreateUrl(?string $organization = null): string
    {
        if ($organization) {
            return "https://github.com/organizations/{$organization}/settings/apps/new";
        }

        return 'https://github.com/settings/apps/new';
    }

    protected function setEnvValue(string $env, string $key, string $value): string
    {
        $escaped = str_contains($value, ' ') || str_contains($value, '#') ? "\"{$value}\"" : $value;
        $newLine = "{$key}={$escaped}";

        $lines = explode("\n", $env);
        $found = false;

        foreach ($lines as &$line) {
            if (str_starts_with($line, "{$key}=")) {
                $line = $newLine;
                $found = true;
                break;
            }
        }
        unset($line);

        if (! $found) {
            $lines[] = $newLine;
        }

        return implode("\n", $lines);
    }

    /**
     * Get the path to the .env file. Overridable for testing.
     */
    public function envPath(): string
    {
        return $this->envPath ?? base_path('.env');
    }

    /**
     * Override the .env path (for testing).
     */
    public function useEnvPath(string $path): static
    {
        $this->envPath = $path;

        return $this;
    }

    protected ?string $envPath = null;

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
