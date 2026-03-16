<?php

namespace App\Models;

use Database\Factories\RuleRetryConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleRetryConfig extends Model
{
    /** @use HasFactory<RuleRetryConfigFactory> */
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'enabled',
        'max_attempts',
        'delay',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_attempts' => 'integer',
            'delay' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Rule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }
}
