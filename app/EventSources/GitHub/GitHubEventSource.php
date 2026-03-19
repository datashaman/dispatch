<?php

namespace App\EventSources\GitHub;

use App\Contracts\EventSource;
use Illuminate\Http\Request;

class GitHubEventSource implements EventSource
{
    public function validates(Request $request): bool
    {
        return $request->hasHeader('X-GitHub-Event');
    }

    public function eventType(Request $request): ?string
    {
        $event = $request->header('X-GitHub-Event');

        if (! $event) {
            return null;
        }

        $action = $this->action($request);

        return $action ? "{$event}.{$action}" : $event;
    }

    public function action(Request $request): ?string
    {
        return $request->input('action');
    }

    public function normalizePayload(Request $request): array
    {
        return $request->all();
    }

    public function name(): string
    {
        return 'github';
    }

    /**
     * Verify the webhook signature using the shared secret.
     */
    public function verifySignature(Request $request): bool
    {
        if (! $this->shouldVerifySignature()) {
            return true;
        }

        $signature = $request->header('X-Hub-Signature-256');
        $secret = config('services.github.webhook_secret');

        if (! $signature || ! $secret) {
            return false;
        }

        $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Check if signature verification is enabled.
     */
    public function shouldVerifySignature(): bool
    {
        return config('services.github.verify_webhook_signature', true);
    }

    /**
     * Check if this webhook is a self-loop (sent by our own bot).
     */
    public function isSelfLoop(Request $request): bool
    {
        $botUsername = config('services.github.bot_username');

        if (! $botUsername) {
            return false;
        }

        return $request->input('sender.login') === $botUsername;
    }

    /**
     * Check if this is a ping event.
     */
    public function isPing(Request $request): bool
    {
        return $request->header('X-GitHub-Event') === 'ping';
    }

    /**
     * Determine the specific signature validation error.
     */
    public function signatureError(Request $request): ?string
    {
        $signature = $request->header('X-Hub-Signature-256');
        $secret = config('services.github.webhook_secret');

        if (! $signature || ! $secret) {
            return 'Missing signature or webhook secret';
        }

        return 'Invalid signature';
    }
}
