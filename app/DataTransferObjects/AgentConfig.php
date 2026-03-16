<?php

namespace App\DataTransferObjects;

readonly class AgentConfig
{
    /**
     * @param  list<string>|null  $tools
     * @param  list<string>|null  $disallowedTools
     */
    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
        public ?int $maxTokens = null,
        public ?int $maxSteps = null,
        public ?array $tools = null,
        public ?array $disallowedTools = null,
        public bool $isolation = false,
    ) {}
}
