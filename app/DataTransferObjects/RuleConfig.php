<?php

namespace App\DataTransferObjects;

readonly class RuleConfig
{
    /**
     * @param  list<FilterConfig>  $filters
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public string $id,
        public string $event,
        public string $prompt,
        public ?string $name = null,
        public bool $continueOnError = false,
        public int $sortOrder = 0,
        public array $filters = [],
        public ?AgentConfig $agent = null,
        public ?OutputConfig $output = null,
        public ?RetryConfig $retry = null,
        public array $dependsOn = [],
    ) {}
}
