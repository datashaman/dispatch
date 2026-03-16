<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'repo',
        'path',
        'agent_name',
        'agent_executor',
        'agent_provider',
        'agent_model',
        'agent_instructions_file',
        'agent_secrets',
    ];

    protected function casts(): array
    {
        return [
            'agent_secrets' => 'array',
        ];
    }

    /**
     * @return HasMany<Rule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class)->orderBy('sort_order');
    }
}
