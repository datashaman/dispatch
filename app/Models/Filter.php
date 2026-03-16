<?php

namespace App\Models;

use App\Enums\FilterOperator;
use Database\Factories\FilterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Filter extends Model
{
    /** @use HasFactory<FilterFactory> */
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'filter_id',
        'field',
        'operator',
        'value',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'operator' => FilterOperator::class,
            'sort_order' => 'integer',
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
