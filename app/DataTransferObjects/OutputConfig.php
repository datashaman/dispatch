<?php

namespace App\DataTransferObjects;

readonly class OutputConfig
{
    public function __construct(
        public bool $log = true,
        public bool $githubComment = false,
        public ?string $githubReaction = null,
    ) {}
}
