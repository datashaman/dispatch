<?php

namespace App\Models;

use Database\Factories\RuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Rule extends Model
{
    /** @use HasFactory<RuleFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'rule_id',
        'name',
        'event',
        'continue_on_error',
        'prompt',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'continue_on_error' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasOne<RuleAgentConfig, $this>
     */
    public function agentConfig(): HasOne
    {
        return $this->hasOne(RuleAgentConfig::class);
    }

    /**
     * @return HasOne<RuleOutputConfig, $this>
     */
    public function outputConfig(): HasOne
    {
        return $this->hasOne(RuleOutputConfig::class);
    }

    /**
     * @return HasOne<RuleRetryConfig, $this>
     */
    public function retryConfig(): HasOne
    {
        return $this->hasOne(RuleRetryConfig::class);
    }

    /**
     * @return HasMany<Filter, $this>
     */
    public function filters(): HasMany
    {
        return $this->hasMany(Filter::class)->orderBy('sort_order');
    }
}
