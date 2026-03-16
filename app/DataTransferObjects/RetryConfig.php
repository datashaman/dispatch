<?php

namespace App\DataTransferObjects;

readonly class RetryConfig
{
    public function __construct(
        public bool $enabled = false,
        public int $maxAttempts = 3,
        public int $delay = 60,
    ) {}
}
