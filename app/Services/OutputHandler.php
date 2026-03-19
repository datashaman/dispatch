<?php

namespace App\Services;

use App\DataTransferObjects\OutputConfig;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Log;

class OutputHandler
{
    public function __construct(
        protected EventSourceRegistry $registry,
        protected GitHubApiClient $github,
    ) {}

    /**
     * Handle output routing for a completed agent run.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(AgentRun $agentRun, OutputConfig $outputConfig, array $payload, string $source = 'github'): void
    {
        if ($outputConfig->githubComment) {
            $this->postComment($agentRun, $payload, $source);
        }
    }

    /**
     * Post agent output as a comment via the appropriate output adapter.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postComment(AgentRun $agentRun, array $payload, string $source): void
    {
        try {
            $adapter = $this->registry->output($source);
            $adapter->postComment($agentRun, $payload);
        } catch (\InvalidArgumentException $e) {
            // Fall back to legacy GitHub behavior for backwards compatibility
            $this->postGitHubCommentLegacy($agentRun, $payload);
        }
    }

    /**
     * Add a reaction via the appropriate output adapter.
     *
     * @param  array<string, mixed>  $payload
     */
    public function addReaction(string $reaction, array $payload, string $source = 'github'): void
    {
        try {
            $adapter = $this->registry->output($source);
            $adapter->addReaction($reaction, $payload);
        } catch (\InvalidArgumentException $e) {
            // Fall back to legacy GitHub behavior for backwards compatibility
            $this->addReactionLegacy($reaction, $payload);
        }
    }

    /**
     * Resolve the GitHub resource type and number from the webhook payload.
     * Kept for backwards compatibility with existing code that calls this method.
     *
     * @param  array<string, mixed>  $payload
     * @return array{type: string, number: int}|null
     */
    public function resolveGitHubResource(array $payload): ?array
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
     * Legacy GitHub comment posting for backwards compatibility.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postGitHubCommentLegacy(AgentRun $agentRun, array $payload): void
    {
        $resource = $this->resolveGitHubResource($payload);

        if (! $resource) {
            Log::warning('Could not determine GitHub resource for comment', [
                'agent_run_id' => $agentRun->id,
            ]);

            return;
        }

        $repo = $payload['repository']['full_name'] ?? null;

        if (! $repo) {
            Log::warning('No repository found in payload for GitHub comment', [
                'agent_run_id' => $agentRun->id,
            ]);

            return;
        }

        $output = $agentRun->output ?? '';

        if (empty(trim($output))) {
            Log::warning('Skipping GitHub comment — agent produced no output', [
                'agent_run_id' => $agentRun->id,
            ]);

            return;
        }

        $installationId = $this->resolveInstallationId($repo, $payload);

        if (! $installationId) {
            Log::error('No GitHub installation found for repo', [
                'repo' => $repo,
                'agent_run_id' => $agentRun->id,
            ]);

            return;
        }

        $this->github->postComment(
            $repo,
            $resource['type'],
            $resource['number'],
            $output,
            $installationId,
        );
    }

    /**
     * Legacy reaction adding for backwards compatibility.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function addReactionLegacy(string $reaction, array $payload): void
    {
        $repo = $payload['repository']['full_name'] ?? null;

        if (! $repo) {
            Log::warning('No repository found in payload for GitHub reaction');

            return;
        }

        $installationId = $this->resolveInstallationId($repo, $payload);

        if (! $installationId) {
            Log::error('No GitHub installation found for repo', ['repo' => $repo]);

            return;
        }

        $commentId = $payload['comment']['id'] ?? null;

        if ($commentId) {
            $commentType = $this->resolveCommentResourceType($payload);
            $this->github->addCommentReaction($repo, $commentType, $commentId, $reaction, $installationId);
        } else {
            $resource = $this->resolveGitHubResource($payload);

            if (! $resource) {
                Log::warning('No reactable resource found in payload for GitHub reaction');

                return;
            }

            $this->github->addIssueReaction($repo, $resource['type'], $resource['number'], $reaction, $installationId);
        }
    }

    /**
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
