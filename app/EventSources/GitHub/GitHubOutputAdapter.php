<?php

namespace App\EventSources\GitHub;

use App\Contracts\OutputAdapter;
use App\Models\AgentRun;
use App\Services\GitHubApiClient;
use Illuminate\Support\Facades\Log;

class GitHubOutputAdapter implements OutputAdapter
{
    public function __construct(
        protected GitHubApiClient $github,
    ) {}

    public function postComment(AgentRun $agentRun, array $payload): bool
    {
        $resource = $this->resolveResource($payload);

        if (! $resource) {
            Log::warning('Could not determine GitHub resource for comment', [
                'agent_run_id' => $agentRun->id,
            ]);

            return false;
        }

        $repo = $payload['repository']['full_name'] ?? null;

        if (! $repo) {
            Log::warning('No repository found in payload for GitHub comment', [
                'agent_run_id' => $agentRun->id,
            ]);

            return false;
        }

        $output = $agentRun->output ?? '';

        if (empty(trim($output))) {
            Log::warning('Skipping GitHub comment — agent produced no output', [
                'agent_run_id' => $agentRun->id,
            ]);

            return false;
        }

        $installationId = $this->resolveInstallationId($repo, $payload);

        if (! $installationId) {
            Log::error('No GitHub installation found for repo', [
                'repo' => $repo,
                'agent_run_id' => $agentRun->id,
            ]);

            return false;
        }

        return $this->github->postComment(
            $repo,
            $resource['type'],
            $resource['number'],
            $output,
            $installationId,
        );
    }

    public function addReaction(string $reaction, array $payload): bool
    {
        $repo = $payload['repository']['full_name'] ?? null;

        if (! $repo) {
            Log::warning('No repository found in payload for GitHub reaction');

            return false;
        }

        $installationId = $this->resolveInstallationId($repo, $payload);

        if (! $installationId) {
            Log::error('No GitHub installation found for repo', ['repo' => $repo]);

            return false;
        }

        $commentId = $payload['comment']['id'] ?? null;

        if ($commentId) {
            $commentType = $this->resolveCommentResourceType($payload);

            return $this->github->addCommentReaction($repo, $commentType, $commentId, $reaction, $installationId);
        }

        $resource = $this->resolveResource($payload);

        if (! $resource) {
            Log::warning('No reactable resource found in payload for GitHub reaction');

            return false;
        }

        return $this->github->addIssueReaction($repo, $resource['type'], $resource['number'], $reaction, $installationId);
    }

    /**
     * Resolve the GitHub resource type and number from the webhook payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{type: string, number: int}|null
     */
    public function resolveResource(array $payload): ?array
    {
        if (isset($payload['pull_request']['number'])) {
            return [
                'type' => 'issues',
                'number' => $payload['pull_request']['number'],
            ];
        }

        if (isset($payload['issue']['number'])) {
            return [
                'type' => 'issues',
                'number' => $payload['issue']['number'],
            ];
        }

        if (isset($payload['discussion']['number'])) {
            return [
                'type' => 'discussions',
                'number' => $payload['discussion']['number'],
            ];
        }

        return null;
    }

    /**
     * Resolve the comment resource type for reactions API.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function resolveCommentResourceType(array $payload): string
    {
        if (isset($payload['pull_request']) || str_starts_with($payload['action'] ?? '', 'pull_request')) {
            return 'pulls/comments';
        }

        if (isset($payload['discussion'])) {
            return 'discussions/comments';
        }

        return 'issues/comments';
    }

    /**
     * Resolve the installation ID from the payload or project lookup.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function resolveInstallationId(string $repo, array $payload): ?int
    {
        $payloadInstallationId = $payload['installation']['id'] ?? null;

        if ($payloadInstallationId) {
            return (int) $payloadInstallationId;
        }

        return $this->github->resolveInstallationId($repo);
    }
}
