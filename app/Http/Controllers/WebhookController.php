<?php

namespace App\Http\Controllers;

use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
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

        return response()->json([
            'ok' => true,
            'event' => $eventType,
            'webhook_log_id' => $webhookLog->id,
        ]);
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
