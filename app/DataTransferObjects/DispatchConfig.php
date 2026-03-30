<?php

namespace App\DataTransferObjects;

readonly class DispatchConfig
{
    /**
     * @param  list<RuleConfig>  $rules
     * @param  list<CustomEventConfig>  $customEvents
     */
    public function __construct(
        public int $version,
        public ?string $agentInstructionsFile = null,
        public ?string $agentProvider = null,
        public ?string $agentModel = null,
        public bool $cacheConfig = false,
        public array $rules = [],
        public array $customEvents = [],
    ) {}
}
