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
     * Create or update a file in a repository via the Contents API.
     *
     * @return array{success: bool, message: string, commit_sha: ?string}
     */
    public function commitFile(string $repo, string $path, string $content, string $commitMessage, int $installationId, ?string $branch = null): array
    {
        try {
            // Get the existing file's SHA (required for updates)
            $existingSha = null;
            $url = self::API_BASE."/repos/{$repo}/contents/{$path}";
            $query = $branch ? ['ref' => $branch] : [];

            $existing = $this->installationRequest($installationId)->get($url, $query);
            if ($existing->successful()) {
                $existingSha = $existing->json('sha');
            }

            $payload = [
                'message' => $commitMessage,
                'content' => base64_encode($content),
            ];

            if ($existingSha) {
                $payload['sha'] = $existingSha;
            }

            if ($branch) {
                $payload['branch'] = $branch;
            }

            $response = $this->installationRequest($installationId)->put($url, $payload);

            if (! $response->successful()) {
                Log::error('GitHubApiClient: failed to commit file', [
                    'repo' => $repo,
                    'path' => $path,
                    'status' => $response->status(),
                    'error' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'message' => "GitHub API error ({$response->status()}): {$response->json('message', 'Unknown error')}",
                    'commit_sha' => null,
                ];
            }

            $commitSha = $response->json('commit.sha');

            Log::info('GitHubApiClient: file committed', [
                'repo' => $repo,
                'path' => $path,
                'commit_sha' => $commitSha,
                'branch' => $branch,
            ]);

            return [
                'success' => true,
                'message' => 'Committed to repository.',
                'commit_sha' => $commitSha,
            ];
        } catch (\Throwable $e) {
            Log::error('GitHubApiClient: exception committing file', [
                'repo' => $repo,
                'path' => $path,
                'installation_id' => $installationId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => "Failed to commit: {$e->getMessage()}",
                'commit_sha' => null,
            ];
        }
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
