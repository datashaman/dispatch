<?php

namespace App\DataTransferObjects;

readonly class CustomEventConfig
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $description = null,
    ) {}
}
