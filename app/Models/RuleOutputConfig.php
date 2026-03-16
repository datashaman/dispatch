<?php

namespace App\Models;

use Database\Factories\RuleOutputConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleOutputConfig extends Model
{
    /** @use HasFactory<RuleOutputConfigFactory> */
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'log',
        'github_comment',
        'github_reaction',
    ];

    protected function casts(): array
    {
        return [
            'log' => 'boolean',
            'github_comment' => 'boolean',
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
