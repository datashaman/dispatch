<?php

namespace App\Contracts;

interface ThreadKeyDeriver
{
    /**
     * Derive a conversation thread key from the event payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deriveKey(string $eventType, array $payload): ?string;
}
