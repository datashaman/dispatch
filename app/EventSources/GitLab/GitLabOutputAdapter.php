<?php

namespace App\EventSources\GitLab;

use App\Contracts\OutputAdapter;
use App\Models\AgentRun;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitLabOutputAdapter implements OutputAdapter
{
    public function postComment(AgentRun $agentRun, array $payload): bool
    {
        $resource = $this->resolveResource($payload);

        if (! $resource) {
            Log::warning('Could not determine GitLab resource for comment', [
                'agent_run_id' => $agentRun->id,
            ]);

            return false;
        }

        $output = $agentRun->output ?? '';

        if (empty(trim($output))) {
            Log::warning('Skipping GitLab comment — agent produced no output', [
                'agent_run_id' => $agentRun->id,
            ]);

            return false;
        }

        $baseUrl = $this->baseUrl($payload);
        $projectId = $this->projectId($payload);

        if (! $baseUrl || ! $projectId) {
            Log::error('Missing GitLab base URL or project ID', [
                'agent_run_id' => $agentRun->id,
            ]);

            return false;
        }

        $endpoint = match ($resource['type']) {
            'merge_request' => "{$baseUrl}/api/v4/projects/{$projectId}/merge_requests/{$resource['iid']}/notes",
            'issue' => "{$baseUrl}/api/v4/projects/{$projectId}/issues/{$resource['iid']}/notes",
            default => null,
        };

        if (! $endpoint) {
            return false;
        }

        try {
            $response = $this->apiRequest()
                ->post($endpoint, ['body' => $output]);

            if (! $response->successful()) {
                Log::error('GitLab API: failed to post comment', [
                    'status' => $response->status(),
                    'error' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('GitLab API: exception posting comment', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function addReaction(string $reaction, array $payload): bool
    {
        $resource = $this->resolveResource($payload);

        if (! $resource) {
            return false;
        }

        $baseUrl = $this->baseUrl($payload);
        $projectId = $this->projectId($payload);

        if (! $baseUrl || ! $projectId) {
            return false;
        }

        $endpoint = match ($resource['type']) {
            'merge_request' => "{$baseUrl}/api/v4/projects/{$projectId}/merge_requests/{$resource['iid']}/award_emoji",
            'issue' => "{$baseUrl}/api/v4/projects/{$projectId}/issues/{$resource['iid']}/award_emoji",
            default => null,
        };

        if (! $endpoint) {
            return false;
        }

        // Map GitHub reaction names to GitLab emoji names
        $emojiName = $this->mapReactionToEmoji($reaction);

        try {
            $response = $this->apiRequest()
                ->post($endpoint, ['name' => $emojiName]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('GitLab API: exception adding reaction', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Resolve the GitLab resource from the normalized payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{type: string, iid: int}|null
     */
    protected function resolveResource(array $payload): ?array
    {
        $attrs = $payload['object_attributes'] ?? [];
        $noteableType = $attrs['noteable_type'] ?? null;

        // For note events, resolve the parent resource
        if ($noteableType === 'MergeRequest' && isset($payload['merge_request']['iid'])) {
            return ['type' => 'merge_request', 'iid' => $payload['merge_request']['iid']];
        }

        if ($noteableType === 'Issue' && isset($payload['issue']['iid'])) {
            return ['type' => 'issue', 'iid' => $payload['issue']['iid']];
        }

        // For normalized payloads, check pull_request/issue
        if (isset($payload['pull_request']['number'])) {
            return ['type' => 'merge_request', 'iid' => $payload['pull_request']['number']];
        }

        if (isset($payload['issue']['number'])) {
            return ['type' => 'issue', 'iid' => $payload['issue']['number']];
        }

        // Direct merge request or issue events
        if (isset($attrs['iid'])) {
            if (isset($payload['object_attributes']['source_branch'])) {
                return ['type' => 'merge_request', 'iid' => $attrs['iid']];
            }

            return ['type' => 'issue', 'iid' => $attrs['iid']];
        }

        return null;
    }

    /**
     * Get the GitLab base URL from the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function baseUrl(array $payload): ?string
    {
        $url = $payload['project']['web_url'] ?? null;

        if (! $url) {
            return config('services.gitlab.url', 'https://gitlab.com');
        }

        // Extract base URL from project URL
        $pathWithNamespace = $payload['project']['path_with_namespace'] ?? '';
        $base = str_replace("/{$pathWithNamespace}", '', $url);

        return rtrim($base, '/');
    }

    /**
     * Get the URL-encoded project ID from the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function projectId(array $payload): ?string
    {
        $id = $payload['project']['id'] ?? null;

        if ($id) {
            return (string) $id;
        }

        // Fall back to URL-encoded path
        $path = $payload['project']['path_with_namespace'] ?? null;

        return $path ? urlencode($path) : null;
    }

    /**
     * Build an authenticated GitLab API request.
     */
    protected function apiRequest(): PendingRequest
    {
        $token = config('services.gitlab.token');

        return Http::withHeaders([
            'PRIVATE-TOKEN' => $token,
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Map GitHub-style reaction names to GitLab emoji names.
     */
    protected function mapReactionToEmoji(string $reaction): string
    {
        return match ($reaction) {
            '+1', 'thumbs_up' => 'thumbsup',
            '-1', 'thumbs_down' => 'thumbsdown',
            'heart' => 'heart',
            'eyes' => 'eyes',
            'rocket' => 'rocket',
            'hooray', 'tada' => 'tada',
            'laugh' => 'laughing',
            'confused' => 'confused',
            default => $reaction,
        };
    }
}
