<?php

namespace App\Jobs;

use App\Models\AgentRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAgentRun implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public AgentRun $agentRun)
    {
        $this->onQueue('agents');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //
    }
}
