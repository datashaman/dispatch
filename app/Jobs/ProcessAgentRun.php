<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\Rule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAgentRun implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public AgentRun $agentRun,
        public Rule $rule,
        public array $payload = [],
    ) {
        $this->onQueue('agents');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->agentRun->update(['status' => 'running']);

        // Actual executor logic will be implemented in US-015
        // For now, mark as success
        $this->agentRun->update(['status' => 'success']);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->agentRun->update([
            'status' => 'failed',
            'error' => $exception?->getMessage(),
        ]);
    }
}
