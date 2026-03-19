<?php

namespace App\Http\Controllers;

use App\EventSources\GitHub\GitHubEventSource;
use App\Exceptions\RuleMatchingException;
use App\Models\Project;
use App\Models\WebhookLog;
use App\Services\AgentDispatcher;
use App\Services\ConfigLoader;
use App\Services\EventSourceRegistry;
use App\Services\PromptRenderer;
use App\Services\RuleMatchingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        protected RuleMatchingEngine $engine,
        protected AgentDispatcher $dispatcher,
        protected PromptRenderer $promptRenderer,
        protected ConfigLoader $configLoader,
        protected EventSourceRegistry $registry,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $sourceName = $this->registry->detect($request);

        if (! $sourceName) {
            return response()->json([
                'ok' => false,
                'error' => 'Unable to detect webhook source',
            ], 400);
        }

        $source = $this->registry->source($sourceName);

        // Verify webhook authenticity (signature, token, etc.)
        if (! $source->verifyWebhook($request)) {
            $eventType = $source->eventType($request) ?? 'unknown';
            $error = $source->verificationError($request);

            $this->logWebhook($eventType, $request, 'error', $error, $sourceName);

            return response()->json([
                'ok' => false,
                'error' => $error,
            ], 401);
        }

        // Source-specific pre-processing (self-loop, ping, etc.)
        if ($source instanceof GitHubEventSource) {
            if ($source->isSelfLoop($request)) {
                $eventType = $source->eventType($request) ?? 'unknown';
                $this->logWebhook($eventType, $request, 'received', 'Self-loop detected', $sourceName);

                return response()->json([
                    'ok' => true,
                    'event' => $eventType,
                    'skipped' => 'self-loop',
                ]);
            }

            if ($source->isPing($request)) {
                $this->logWebhook('ping', $request, 'received', null, $sourceName);

                return response()->json([
                    'ok' => true,
                    'event' => 'ping',
                ]);
            }
        }

        $eventType = $source->eventType($request) ?? 'unknown';

        $webhookLog = $this->logWebhook($eventType, $request, 'received', null, $sourceName);

        $payload = $source->normalizePayload($request);
        $repo = $payload['repository']['full_name'] ?? null;

        if (! $repo) {
            $webhookLog->update(['status' => 'error', 'error' => 'Missing repository.full_name in payload']);

            return response()->json([
                'ok' => false,
                'error' => 'Missing repository.full_name in payload',
                'webhook_log_id' => $webhookLog->id,
            ], 422);
        }

        try {
            $matchedRules = $this->engine->match($repo, $eventType, $payload);
        } catch (RuleMatchingException $e) {
            $webhookLog->update(['status' => 'error', 'error' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'webhook_log_id' => $webhookLog->id,
            ], 422);
        }

        $webhookLog->update([
            'matched_rules' => $matchedRules->pluck('id')->toArray(),
            'status' => 'processed',
        ]);

        if ($request->boolean('dry-run')) {
            $results = $matchedRules->map(fn ($rule) => [
                'rule' => $rule->id,
                'name' => $rule->name,
                'prompt' => $this->promptRenderer->render($rule->prompt, $payload),
            ])->values()->toArray();

            return response()->json([
                'ok' => true,
                'event' => $eventType,
                'matched' => $matchedRules->count(),
                'dryRun' => true,
                'webhook_log_id' => $webhookLog->id,
                'results' => $results,
            ]);
        }

        $project = Project::where('repo', $repo)->firstOrFail();
        $config = $this->configLoader->load($project->path);

        $results = $this->dispatcher->dispatch($webhookLog, $matchedRules, $payload, $project, $config);

        return response()->json([
            'ok' => true,
            'event' => $eventType,
            'matched' => $matchedRules->count(),
            'webhook_log_id' => $webhookLog->id,
            'results' => $results,
        ]);
    }

    protected function logWebhook(string $eventType, Request $request, string $status, ?string $error = null, string $source = 'github'): WebhookLog
    {
        $payload = $request->all();
        $repo = $payload['repository']['full_name'] ?? null;

        return WebhookLog::create([
            'event_type' => $eventType,
            'repo' => $repo,
            'payload' => $payload,
            'source' => $source,
            'status' => $status,
            'error' => $error,
            'created_at' => now(),
        ]);
    }
}
