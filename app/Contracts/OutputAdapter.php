<?php

namespace App\Contracts;

use App\Models\AgentRun;

interface OutputAdapter
{
    /**
     * Post a comment on the source resource (issue, PR, merge request, etc.).
     *
     * @param  array<string, mixed>  $payload
     */
    public function postComment(AgentRun $agentRun, array $payload): bool;

    /**
     * Add a reaction to the triggering resource or comment.
     *
     * @param  array<string, mixed>  $payload
     */
    public function addReaction(string $reaction, array $payload): bool;
}
