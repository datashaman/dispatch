<?php

namespace App\DataTransferObjects;

class StitchResult
{
    public function __construct(
        public readonly string $html,
        public readonly string $screenshotUrl,
        public readonly string $screenId,
    ) {}
}
