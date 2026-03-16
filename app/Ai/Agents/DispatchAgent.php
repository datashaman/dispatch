<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class DispatchAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * @param  list<Tool>  $agentTools
     */
    public function __construct(
        protected string $systemPrompt,
        protected array $agentTools = [],
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->systemPrompt;
    }

    public function tools(): iterable
    {
        return $this->agentTools;
    }
}
