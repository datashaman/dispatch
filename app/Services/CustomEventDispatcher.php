<?php

namespace App\Services;

use App\Exceptions\RuleMatchingException;
use App\Models\Project;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

class CustomEventDispatcher
{
    /**
     * Maximum event depth to prevent infinite loops.
     */
    public const MAX_EVENT_DEPTH = 5;

    public function __construct(
        protected RuleMatchingEngine $engine,
        protected AgentDispatcher $dispatcher,
        protected ConfigLoader $configLoader,
    ) {}

    /**
     * Dispatch a custom event into the pipeline.
     *
     * @param  array<string, mixed>  $eventPayload  Arbitrary payload from the emitting agent
     * @param  array<string, mixed>  $context  Agent run context (source rule, webhook log, project)
     * @return array{ok: bool, message: string, webhook_log_id: ?int, matched: int}
     */
    public function dispatch(
        string $eventType,
        array $eventPayload,
        Project $project,
        int $eventDepth = 0,
    ): array {
        // Enforce app. prefix
        if (! str_starts_with($eventType, 'app.')) {
            $eventType = 'app.'.$eventType;
        }

        // Loop detection: prevent infinite chains
        if ($eventDepth >= self::MAX_EVENT_DEPTH) {
            Log::warning('Custom event depth limit reached', [
                'event_type' => $eventType,
                'project' => $project->repo,
                'depth' => $eventDepth,
            ]);

            return [
                'ok' => false,
                'message' => "Event depth limit reached ({$eventDepth}/".self::MAX_EVENT_DEPTH.'). Aborting to prevent infinite loops.',
                'webhook_log_id' => null,
                'matched' => 0,
            ];
        }

        // Build the normalized payload with repository info
        $payload = array_merge($eventPayload, [
            'repository' => ['full_name' => $project->repo],
            '_dispatch' => [
                'source' => 'app',
                'event_depth' => $eventDepth + 1,
            ],
        ]);

        // Log as a webhook event with source=app
        $webhookLog = WebhookLog::create([
            'event_type' => $eventType,
            'repo' => $project->repo,
            'source' => 'app',
            'payload' => $payload,
            'status' => 'received',
            'created_at' => now(),
        ]);

        try {
            $matchedRules = $this->engine->match($project->repo, $eventType, $payload);
        } catch (RuleMatchingException $e) {
            $webhookLog->update(['status' => 'error', 'error' => $e->getMessage()]);

            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'webhook_log_id' => $webhookLog->id,
                'matched' => 0,
            ];
        }

        $webhookLog->update([
            'matched_rules' => $matchedRules->pluck('id')->toArray(),
            'status' => 'processed',
        ]);

        if ($matchedRules->isEmpty()) {
            return [
                'ok' => true,
                'message' => "No rules matched event '{$eventType}'.",
                'webhook_log_id' => $webhookLog->id,
                'matched' => 0,
            ];
        }

        $config = $this->configLoader->load($project->path);

        $this->dispatcher->dispatch($webhookLog, $matchedRules, $payload, $project, $config);

        return [
            'ok' => true,
            'message' => "Event '{$eventType}' dispatched, {$matchedRules->count()} rule(s) matched.",
            'webhook_log_id' => $webhookLog->id,
            'matched' => $matchedRules->count(),
        ];
    }
}
