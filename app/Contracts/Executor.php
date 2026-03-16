<?php

namespace App\Contracts;

use App\DataTransferObjects\ExecutionResult;
use App\Models\AgentRun;

interface Executor
{
    /**
     * Execute an agent run with the given rendered prompt and resolved agent config.
     *
     * @param  array<string, mixed>  $agentConfig  Resolved agent config with keys: provider, model, max_tokens, tools, disallowed_tools, isolation, instructions_file, project_path
     */
    public function execute(AgentRun $run, string $renderedPrompt, array $agentConfig): ExecutionResult;
}
