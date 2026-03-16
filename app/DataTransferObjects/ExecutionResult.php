<?php

namespace App\DataTransferObjects;

readonly class ExecutionResult
{
    public function __construct(
        public string $status,
        public ?string $output = null,
        public ?int $tokensUsed = null,
        public ?string $cost = null,
        public ?int $durationMs = null,
        public ?string $error = null,
    ) {}
}
