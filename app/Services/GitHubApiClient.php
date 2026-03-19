<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubApiClient
{
    protected const string API_BASE = 'https://api.github.com';

    public function __construct(
        protected GitHubAppService $appService,
    ) {}

    /**
     * Post a comment on an issue or pull request.
     */
    public function postComment(string $repo, string $resourceType, int $number, string $body, int $installationId): bool
    {
        try {
            $response = $this->installationRequest($installationId)
                ->post(self::API_BASE."/repos/{$repo}/{$resourceType}/{$number}/comments", [
                    'body' => $body,
                ]);
        } catch (\Throwable $e) {
            Log::error('GitHubApiClient: exception posting comment', [
                'repo' => $repo,
                'resource' => "{$resourceType}/{$number}",
                'installation_id' => $installationId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::error('GitHubApiClient: failed to post comment', [
                'repo' => $repo,
                'resource' => "{$resourceType}/{$number}",
                'status' => $response->status(),
                'error' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Add a reaction to a comment.
     */
    public function addCommentReaction(string $repo, string $commentType, int $commentId, string $reaction, int $installationId): bool
    {
        try {
            $response = $this->installationRequest($installationId)
                ->post(self::API_BASE."/repos/{$repo}/{$commentType}/{$commentId}/reactions", [
                    'content' => $reaction,
                ]);
        } catch (\Throwable $e) {
            Log::error('GitHubApiClient: exception adding comment reaction', [
                'repo' => $repo,
                'comment_type' => $commentType,
                'comment_id' => $commentId,
                'installation_id' => $installationId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::error('GitHubApiClient: failed to add comment reaction', [
                'repo' => $repo,
                'comment_type' => $commentType,
                'comment_id' => $commentId,
                'reaction' => $reaction,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Add a reaction to an issue or pull request.
     */
    public function addIssueReaction(string $repo, string $resourceType, int $number, string $reaction, int $installationId): bool
    {
        try {
            $response = $this->installationRequest($installationId)
                ->post(self::API_BASE."/repos/{$repo}/{$resourceType}/{$number}/reactions", [
                    'content' => $reaction,
                ]);
        } catch (\Throwable $e) {
            Log::error('GitHubApiClient: exception adding issue reaction', [
                'repo' => $repo,
                'resource' => "{$resourceType}/{$number}",
                'installation_id' => $installationId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::error('GitHubApiClient: failed to add issue reaction', [
                'repo' => $repo,
                'resource' => "{$resourceType}/{$number}",
                'reaction' => $reaction,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Resolve the installation ID for a given repo.
     */
    public function resolveInstallationId(string $repo): ?int
    {
        $project = Project::where('repo', $repo)->first();

        if (! $project || ! $project->github_installation_id) {
            return null;
        }

        $installation = $project->githubInstallation;

        return $installation?->installation_id;
    }

    /**
     * Build an authenticated request using an installation token.
     */
    protected function installationRequest(int $installationId): PendingRequest
    {
        $token = $this->appService->getInstallationToken($installationId);

        return Http::withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
    }
}
