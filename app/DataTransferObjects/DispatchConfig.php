<?php

namespace App\DataTransferObjects;

readonly class DispatchConfig
{
    /**
     * @param  list<RuleConfig>  $rules
     */
    public function __construct(
        public int $version,
        public string $agentName,
        public string $agentExecutor,
        public ?string $agentInstructionsFile = null,
        public ?string $agentProvider = null,
        public ?string $agentModel = null,
        public bool $cacheConfig = false,
        public array $rules = [],
    ) {}
}
