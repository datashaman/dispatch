<?php

namespace App\Events;

use App\Models\AgentRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class AgentRunUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public AgentRun $agentRun,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('agent-run.'.$this->agentRun->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->agentRun->id,
            'status' => $this->agentRun->status,
            'output' => $this->agentRun->output,
            'tokens_used' => $this->agentRun->tokens_used,
            'cost' => $this->agentRun->cost,
            'duration_ms' => $this->agentRun->duration_ms,
            'error' => $this->agentRun->error,
        ];
    }
}
