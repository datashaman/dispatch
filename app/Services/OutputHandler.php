<?php

namespace App\Services;

use App\DataTransferObjects\OutputConfig;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class OutputHandler
{
    /**
     * Handle output routing for a completed agent run.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(AgentRun $agentRun, OutputConfig $outputConfig, array $payload): void
    {
        if ($outputConfig->githubComment) {
            $this->postGitHubComment($agentRun, $payload);
        }
    }

    /**
     * Post agent output as a comment on the source GitHub resource.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postGitHubComment(AgentRun $agentRun, array $payload): void
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

        $result = Process::input(json_encode(['body' => $output]))
            ->run([
                'gh', 'api',
                '-X', 'POST',
                "/repos/{$repo}/{$resource['type']}/{$resource['number']}/comments",
                '--input', '-',
            ]);

        if (! $result->successful()) {
            Log::error('Failed to post GitHub comment', [
                'agent_run_id' => $agentRun->id,
                'error' => trim($result->errorOutput()),
            ]);
        }
    }

    /**
     * Add a reaction to the triggering comment via gh CLI.
     *
     * @param  array<string, mixed>  $payload
     */
    public function addReaction(string $reaction, array $payload): void
    {
        $repo = $payload['repository']['full_name'] ?? null;

        if (! $repo) {
            Log::warning('No repository found in payload for GitHub reaction');

            return;
        }

        // Try comment reaction first, fall back to issue/PR reaction
        $commentId = $payload['comment']['id'] ?? null;

        if ($commentId) {
            $resourceType = $this->resolveCommentResourceType($payload);
            $endpoint = "/repos/{$repo}/{$resourceType}/{$commentId}/reactions";
        } else {
            $resource = $this->resolveGitHubResource($payload);

            if (! $resource) {
                Log::warning('No reactable resource found in payload for GitHub reaction');

                return;
            }

            $endpoint = "/repos/{$repo}/{$resource['type']}/{$resource['number']}/reactions";
        }

        $result = Process::run([
            'gh', 'api',
            '-X', 'POST',
            $endpoint,
            '-f', "content={$reaction}",
        ]);

        if (! $result->successful()) {
            Log::error('Failed to add GitHub reaction', [
                'reaction' => $reaction,
                'error' => trim($result->errorOutput()),
            ]);
        }
    }

    /**
     * Resolve the GitHub resource type and number from the webhook payload.
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
}
