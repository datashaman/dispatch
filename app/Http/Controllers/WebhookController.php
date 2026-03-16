<?php

namespace App\Http\Controllers;

use App\Exceptions\RuleMatchingException;
use App\Models\WebhookLog;
use App\Services\AgentDispatcher;
use App\Services\RuleMatchingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        protected RuleMatchingEngine $engine,
        protected AgentDispatcher $dispatcher,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $githubEvent = $request->header('X-GitHub-Event');

        if (! $githubEvent) {
            return response()->json([
                'ok' => false,
                'error' => 'Missing X-GitHub-Event header',
            ], 400);
        }

        if ($this->shouldVerifySignature()) {
            $signature = $request->header('X-Hub-Signature-256');
            $secret = config('services.github.webhook_secret');

            if (! $signature || ! $secret) {
                $this->logWebhook($githubEvent, $request, 'error', 'Missing signature or webhook secret');

                return response()->json([
                    'ok' => false,
                    'error' => 'Missing X-Hub-Signature-256 header or webhook secret not configured',
                ], 401);
            }

            $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

            if (! hash_equals($expectedSignature, $signature)) {
                $this->logWebhook($githubEvent, $request, 'error', 'Invalid signature');

                return response()->json([
                    'ok' => false,
                    'error' => 'Invalid webhook signature',
                ], 401);
            }
        }

        if ($this->isSelfLoop($request)) {
            $action = $request->input('action');
            $eventType = $action ? "{$githubEvent}.{$action}" : $githubEvent;

            $this->logWebhook($eventType, $request, 'received', 'Self-loop detected');

            return response()->json([
                'ok' => true,
                'event' => $eventType,
                'skipped' => 'self-loop',
            ]);
        }

        if ($githubEvent === 'ping') {
            $this->logWebhook('ping', $request, 'received');

            return response()->json([
                'ok' => true,
                'event' => 'ping',
            ]);
        }

        $action = $request->input('action');
        $eventType = $action ? "{$githubEvent}.{$action}" : $githubEvent;

        $webhookLog = $this->logWebhook($eventType, $request, 'received');

        $repo = $request->input('repository.full_name');

        if (! $repo) {
            $webhookLog->update(['status' => 'error', 'error' => 'Missing repository.full_name in payload']);

            return response()->json([
                'ok' => false,
                'error' => 'Missing repository.full_name in payload',
                'webhook_log_id' => $webhookLog->id,
            ], 422);
        }

        try {
            $matchedRules = $this->engine->match($repo, $eventType, $request->all());
        } catch (RuleMatchingException $e) {
            $webhookLog->update(['status' => 'error', 'error' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'webhook_log_id' => $webhookLog->id,
            ], 422);
        }

        $webhookLog->update([
            'matched_rules' => $matchedRules->pluck('rule_id')->toArray(),
            'status' => 'processed',
        ]);

        $results = $this->dispatcher->dispatch($webhookLog, $matchedRules, $request->all());

        return response()->json([
            'ok' => true,
            'event' => $eventType,
            'matched' => $matchedRules->count(),
            'webhook_log_id' => $webhookLog->id,
            'results' => $results,
        ]);
    }

    protected function isSelfLoop(Request $request): bool
    {
        $botUsername = config('services.github.bot_username');

        if (! $botUsername) {
            return false;
        }

        $senderLogin = $request->input('sender.login');

        return $senderLogin === $botUsername;
    }

    protected function shouldVerifySignature(): bool
    {
        return config('services.github.verify_webhook_signature', true);
    }

    protected function logWebhook(string $eventType, Request $request, string $status, ?string $error = null): WebhookLog
    {
        $payload = $request->all();
        $repo = $payload['repository']['full_name'] ?? null;

        return WebhookLog::create([
            'event_type' => $eventType,
            'repo' => $repo,
            'payload' => $payload,
            'status' => $status,
            'error' => $error,
            'created_at' => now(),
        ]);
    }
}
