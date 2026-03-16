<?php

namespace App\Models;

use Database\Factories\WebhookLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookLog extends Model
{
    /** @use HasFactory<WebhookLogFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'repo',
        'payload',
        'matched_rules',
        'status',
        'error',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'matched_rules' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AgentRun, $this>
     */
    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
