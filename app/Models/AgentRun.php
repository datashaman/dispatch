<?php

namespace App\Models;

use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'webhook_log_id',
        'rule_id',
        'status',
        'output',
        'tokens_used',
        'cost',
        'duration_ms',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'tokens_used' => 'integer',
            'cost' => 'decimal:6',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookLog, $this>
     */
    public function webhookLog(): BelongsTo
    {
        return $this->belongsTo(WebhookLog::class);
    }
}
