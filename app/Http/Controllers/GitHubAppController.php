<?php

namespace App\Http\Controllers;

use App\Models\GitHubInstallation;
use App\Services\GitHubAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GitHubAppController extends Controller
{
    public function __construct(
        protected GitHubAppService $gitHubAppService,
    ) {}

    /**
     * Handle the manifest flow callback — exchange code for credentials.
     */
    public function manifestCallback(Request $request): RedirectResponse
    {
        $code = $request->query('code');

        if (! $code) {
            return redirect()->route('github.settings')
                ->with('error', 'No code received from GitHub. The manifest flow may have been cancelled.');
        }

        try {
            $credentials = $this->gitHubAppService->exchangeManifestCode($code);
            $this->gitHubAppService->storeCredentials($credentials);

            // Sync installations right away
            try {
                $this->gitHubAppService->syncInstallations();
            } catch (\Throwable) {
                // App may not have any installations yet — that's fine
            }

            return redirect()->route('github.settings')
                ->with('status', "GitHub App \"{$credentials['name']}\" created and configured successfully.");
        } catch (\Throwable $e) {
            return redirect()->route('github.settings')
                ->with('error', "Failed to create GitHub App: {$e->getMessage()}");
        }
    }

    /**
     * Handle the callback after a user installs or configures the GitHub App.
     */
    public function callback(Request $request): RedirectResponse
    {
        $installationId = $request->query('installation_id');
        $setupAction = $request->query('setup_action', 'install');

        if (! $installationId) {
            return redirect()->route('github.settings')
                ->with('error', 'No installation ID provided.');
        }

        if ($setupAction === 'install' || $setupAction === 'update') {
            try {
                $this->gitHubAppService->syncInstallations();
            } catch (\Throwable $e) {
                return redirect()->route('github.settings')
                    ->with('error', "Failed to sync installations: {$e->getMessage()}");
            }
        }

        return redirect()->route('github.settings')
            ->with('status', 'GitHub App installation synced successfully.');
    }

    /**
     * Handle GitHub App webhook events (installation created/deleted/etc).
     */
    public function webhook(Request $request): JsonResponse
    {
        $event = $request->header('X-GitHub-Event');

        if ($event !== 'installation') {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $action = $request->input('action');
        $installation = $request->input('installation');

        if (! $installation || ! isset($installation['id'])) {
            return response()->json(['ok' => false, 'error' => 'Missing installation data'], 422);
        }

        return match ($action) {
            'created' => $this->handleInstallationCreated($installation),
            'deleted' => $this->handleInstallationDeleted($installation),
            'suspend' => $this->handleInstallationSuspended($installation),
            'unsuspend' => $this->handleInstallationUnsuspended($installation),
            default => response()->json(['ok' => true, 'action' => $action]),
        };
    }

    protected function handleInstallationCreated(array $installation): JsonResponse
    {
        GitHubInstallation::updateOrCreate(
            ['installation_id' => $installation['id']],
            [
                'account_login' => $installation['account']['login'],
                'account_type' => $installation['account']['type'],
                'account_id' => $installation['account']['id'],
                'permissions' => $installation['permissions'] ?? [],
                'events' => $installation['events'] ?? [],
                'target_type' => $installation['target_type'] ?? 'Organization',
            ],
        );

        return response()->json(['ok' => true, 'action' => 'created']);
    }

    protected function handleInstallationDeleted(array $installation): JsonResponse
    {
        GitHubInstallation::where('installation_id', $installation['id'])->delete();

        return response()->json(['ok' => true, 'action' => 'deleted']);
    }

    protected function handleInstallationSuspended(array $installation): JsonResponse
    {
        GitHubInstallation::where('installation_id', $installation['id'])
            ->update(['suspended_at' => now()]);

        return response()->json(['ok' => true, 'action' => 'suspended']);
    }

    protected function handleInstallationUnsuspended(array $installation): JsonResponse
    {
        GitHubInstallation::where('installation_id', $installation['id'])
            ->update(['suspended_at' => null]);

        return response()->json(['ok' => true, 'action' => 'unsuspended']);
    }
}
