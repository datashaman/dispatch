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
     * Get the source name identifier (e.g., "github", "gitlab").
     */
    public function name(): string;
}
