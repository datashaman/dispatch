<?php

namespace App\Services;

use App\Models\AgentRun;
use Illuminate\Support\Arr;

class ConversationMemory
{
    public function __construct(
        protected EventSourceRegistry $registry,
    ) {}

    /**
     * Derive a conversation thread key from the webhook payload.
     *
     * Delegates to the appropriate ThreadKeyDeriver based on source.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deriveThreadKey(array $payload, string $source = 'github', ?string $eventType = null): ?string
    {
        try {
            $deriver = $this->registry->threadKey($source);

            return $deriver->deriveKey($eventType ?? '', $payload);
        } catch (\InvalidArgumentException) {
            // Fall back to legacy behavior if source not registered
            return $this->deriveThreadKeyLegacy($payload);
        }
    }

    /**
     * Legacy thread key derivation for backwards compatibility.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function deriveThreadKeyLegacy(array $payload): ?string
    {
        $repo = Arr::get($payload, 'repository.full_name');

        if (! $repo) {
            return null;
        }

        if ($number = Arr::get($payload, 'pull_request.number')) {
            return "{$repo}:pr:{$number}";
        }

        if ($number = Arr::get($payload, 'issue.number')) {
            return "{$repo}:issue:{$number}";
        }

        if ($number = Arr::get($payload, 'discussion.number')) {
            return "{$repo}:discussion:{$number}";
        }

        return null;
    }

    /**
     * Retrieve prior conversation history for a thread.
     *
     * @return list<array{role: string, content: string}>
     */
    public function retrieveHistory(string $threadKey, ?int $excludeRunId = null): array
    {
        $parts = explode(':', $threadKey, 3);

        if (count($parts) !== 3) {
            return [];
        }

        [$repo, $resourceType, $resourceNumber] = $parts;

        $payloadField = match ($resourceType) {
            'pr' => 'pull_request.number',
            'issue' => 'issue.number',
            'discussion' => 'discussion.number',
            default => null,
        };

        if (! $payloadField) {
            return [];
        }

        $query = AgentRun::query()
            ->whereHas('webhookLog', function ($q) use ($repo) {
                $q->where('repo', $repo);
            })
            ->where('status', 'success')
            ->whereNotNull('output')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');

        if ($excludeRunId) {
            $query->where('id', '!=', $excludeRunId);
        }

        $runs = $query->with('webhookLog')->get();

        $messages = [];

        foreach ($runs as $run) {
            $runPayload = $run->webhookLog->payload ?? [];
            $runSource = $run->webhookLog->source ?? 'github';
            $runThreadKey = $this->deriveThreadKey($runPayload, $runSource, $run->webhookLog->event_type);

            if ($runThreadKey !== $threadKey) {
                continue;
            }

            if ($run->webhookLog) {
                $messages[] = [
                    'role' => 'user',
                    'content' => $this->buildUserContext($run),
                ];
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $run->output,
            ];
        }

        return $messages;
    }

    /**
     * Format conversation history as text for CLI executors.
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function formatAsText(array $messages): string
    {
        if (empty($messages)) {
            return '';
        }

        $formatted = "## Prior Conversation History\n\n";

        foreach ($messages as $message) {
            $label = $message['role'] === 'user' ? 'User' : 'Assistant';
            $formatted .= "### {$label}\n{$message['content']}\n\n";
        }

        return $formatted;
    }

    /**
     * Build user context string from an agent run's webhook log.
     */
    protected function buildUserContext(AgentRun $run): string
    {
        $payload = $run->webhookLog->payload ?? [];
        $eventType = $run->webhookLog->event_type ?? 'unknown';
        $ruleId = $run->rule_id ?? 'unknown';

        $parts = ["[Event: {$eventType}, Rule: {$ruleId}]"];

        $body = Arr::get($payload, 'comment.body')
            ?? Arr::get($payload, 'issue.body')
            ?? Arr::get($payload, 'pull_request.body')
            ?? Arr::get($payload, 'discussion.body');

        if ($body) {
            $parts[] = $body;
        }

        return implode("\n", $parts);
    }
}
