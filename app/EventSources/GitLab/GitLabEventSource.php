<?php

namespace App\EventSources\GitLab;

use App\Contracts\EventSource;
use Illuminate\Http\Request;

class GitLabEventSource implements EventSource
{
    public function validates(Request $request): bool
    {
        return $request->hasHeader('X-Gitlab-Event');
    }

    public function eventType(Request $request): ?string
    {
        $event = $request->header('X-Gitlab-Event');

        if (! $event) {
            return null;
        }

        // Normalize GitLab event names to Dispatch format
        // e.g., "Issue Hook" → "issues", "Merge Request Hook" → "merge_request"
        $normalized = $this->normalizeEventName($event);
        $action = $this->action($request);

        return $action ? "{$normalized}.{$action}" : $normalized;
    }

    public function action(Request $request): ?string
    {
        return $request->input('object_attributes.action');
    }

    public function normalizePayload(Request $request): array
    {
        $payload = $request->all();
        $event = $request->header('X-Gitlab-Event');

        // Map GitLab payload structure to Dispatch's internal format
        return match ($this->normalizeEventName($event)) {
            'issues' => $this->normalizeIssuePayload($payload),
            'merge_request' => $this->normalizeMergeRequestPayload($payload),
            'note' => $this->normalizeNotePayload($payload),
            'push' => $this->normalizePushPayload($payload),
            default => $payload,
        };
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->verifyToken($request);
    }

    public function verificationError(Request $request): string
    {
        $token = $request->header('X-Gitlab-Token');

        if (! $token) {
            return 'Missing X-Gitlab-Token header';
        }

        return 'Invalid webhook token';
    }

    public function name(): string
    {
        return 'gitlab';
    }

    /**
     * Verify the webhook token against the configured secret.
     */
    public function verifyToken(Request $request): bool
    {
        $secret = config('services.gitlab.webhook_secret');

        if (! $secret) {
            return true;
        }

        $token = $request->header('X-Gitlab-Token');

        if (! $token) {
            return false;
        }

        return hash_equals($secret, $token);
    }

    /**
     * Normalize GitLab event hook names to short identifiers.
     */
    protected function normalizeEventName(?string $event): string
    {
        return match ($event) {
            'Issue Hook' => 'issues',
            'Merge Request Hook' => 'merge_request',
            'Note Hook' => 'note',
            'Push Hook' => 'push',
            'Tag Push Hook' => 'tag_push',
            'Pipeline Hook' => 'pipeline',
            'Job Hook' => 'job',
            'Wiki Page Hook' => 'wiki_page',
            default => strtolower(str_replace([' Hook', ' '], ['', '_'], $event ?? 'unknown')),
        };
    }

    /**
     * Normalize GitLab issue payload to Dispatch format.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeIssuePayload(array $payload): array
    {
        $attrs = $payload['object_attributes'] ?? [];

        $payload['repository'] = [
            'full_name' => $payload['project']['path_with_namespace'] ?? null,
        ];

        $payload['issue'] = [
            'number' => $attrs['iid'] ?? null,
            'title' => $attrs['title'] ?? null,
            'body' => $attrs['description'] ?? null,
            'state' => $attrs['state'] ?? null,
            'html_url' => $attrs['url'] ?? null,
            'user' => [
                'login' => $payload['user']['username'] ?? null,
            ],
        ];

        $payload['action'] = $attrs['action'] ?? null;
        $payload['sender'] = [
            'login' => $payload['user']['username'] ?? null,
        ];

        return $payload;
    }

    /**
     * Normalize GitLab merge request payload to Dispatch format.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeMergeRequestPayload(array $payload): array
    {
        $attrs = $payload['object_attributes'] ?? [];

        $payload['repository'] = [
            'full_name' => $payload['project']['path_with_namespace'] ?? null,
        ];

        $payload['pull_request'] = [
            'number' => $attrs['iid'] ?? null,
            'title' => $attrs['title'] ?? null,
            'body' => $attrs['description'] ?? null,
            'state' => $attrs['state'] ?? null,
            'html_url' => $attrs['url'] ?? null,
            'merged' => ($attrs['state'] ?? null) === 'merged',
            'head' => [
                'ref' => $attrs['source_branch'] ?? null,
                'sha' => $attrs['last_commit']['id'] ?? null,
            ],
            'base' => [
                'ref' => $attrs['target_branch'] ?? null,
            ],
            'user' => [
                'login' => $payload['user']['username'] ?? null,
            ],
        ];

        $payload['action'] = $attrs['action'] ?? null;
        $payload['sender'] = [
            'login' => $payload['user']['username'] ?? null,
        ];

        return $payload;
    }

    /**
     * Normalize GitLab note (comment) payload to Dispatch format.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeNotePayload(array $payload): array
    {
        $attrs = $payload['object_attributes'] ?? [];

        $payload['repository'] = [
            'full_name' => $payload['project']['path_with_namespace'] ?? null,
        ];

        $payload['comment'] = [
            'id' => $attrs['id'] ?? null,
            'body' => $attrs['note'] ?? null,
            'html_url' => $attrs['url'] ?? null,
            'user' => [
                'login' => $payload['user']['username'] ?? null,
            ],
        ];

        $payload['action'] = 'created';
        $payload['sender'] = [
            'login' => $payload['user']['username'] ?? null,
        ];

        // Attach the parent resource (issue or merge request)
        if (isset($payload['issue'])) {
            $payload['issue'] = [
                'number' => $payload['issue']['iid'] ?? null,
                'title' => $payload['issue']['title'] ?? null,
                'body' => $payload['issue']['description'] ?? null,
            ];
        }

        if (isset($payload['merge_request'])) {
            $payload['pull_request'] = [
                'number' => $payload['merge_request']['iid'] ?? null,
                'title' => $payload['merge_request']['title'] ?? null,
                'body' => $payload['merge_request']['description'] ?? null,
            ];
        }

        return $payload;
    }

    /**
     * Normalize GitLab push payload to Dispatch format.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizePushPayload(array $payload): array
    {
        $payload['repository'] = [
            'full_name' => $payload['project']['path_with_namespace'] ?? null,
        ];

        $payload['ref'] = $payload['ref'] ?? null;
        $payload['before'] = $payload['before'] ?? null;
        $payload['after'] = $payload['after'] ?? null;
        $payload['sender'] = [
            'login' => $payload['user_username'] ?? null,
        ];

        $commits = $payload['commits'] ?? [];
        $lastCommit = end($commits) ?: [];

        $payload['head_commit'] = [
            'message' => $lastCommit['message'] ?? null,
            'id' => $lastCommit['id'] ?? null,
            'author' => [
                'name' => $lastCommit['author']['name'] ?? null,
            ],
        ];

        $payload['pusher'] = [
            'name' => $payload['user_name'] ?? null,
        ];

        return $payload;
    }
}
