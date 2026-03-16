<?php

namespace App\Models;

use Database\Factories\RuleAgentConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleAgentConfig extends Model
{
    /** @use HasFactory<RuleAgentConfigFactory> */
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'provider',
        'model',
        'max_tokens',
        'max_steps',
        'tools',
        'disallowed_tools',
        'isolation',
    ];

    protected function casts(): array
    {
        return [
            'max_tokens' => 'integer',
            'max_steps' => 'integer',
            'tools' => 'array',
            'disallowed_tools' => 'array',
            'isolation' => 'boolean',
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
