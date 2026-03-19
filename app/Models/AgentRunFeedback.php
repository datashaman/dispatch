<?php

namespace App\Models;

use Database\Factories\AgentRunFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRunFeedback extends Model
{
    /** @use HasFactory<AgentRunFeedbackFactory> */
    use HasFactory;

    protected $table = 'agent_run_feedback';

    protected $fillable = [
        'agent_run_id',
        'user_id',
        'rating',
        'comment',
    ];

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
