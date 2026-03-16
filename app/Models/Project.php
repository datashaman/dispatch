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
    ];

    /**
     * @return HasMany<Rule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class)->orderBy('sort_order');
    }
}
