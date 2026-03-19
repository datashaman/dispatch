<?php

namespace App\Contracts;

use Illuminate\Http\Request;

interface EventSource
{
    /**
     * Check if this source can handle the given request.
     */
    public function validates(Request $request): bool;

    /**
     * Extract the event type from the request (e.g., "issues.opened").
     */
    public function eventType(Request $request): ?string;

    /**
     * Extract the action from the request.
     */
    public function action(Request $request): ?string;

    /**
     * Normalize the request payload into Dispatch's internal format.
     *
     * @return array<string, mixed>
     */
    public function normalizePayload(Request $request): array;

    /**
     * Verify the webhook request is authentic (signature, token, etc.).
     * Returns true if verification passes or is not configured.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Get a human-readable error message when webhook verification fails.
     */
    public function verificationError(Request $request): string;

    /**
     * Get the source name identifier (e.g., "github", "gitlab").
     */
    public function name(): string;
}
