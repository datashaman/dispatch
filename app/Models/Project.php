<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'repo',
        'path',
        'source',
        'agent_name',
        'agent_executor',
        'agent_provider',
        'agent_model',
        'agent_instructions_file',
        'agent_secrets',
        'cache_config',
        'monthly_budget',
        'github_installation_id',
    ];

    protected function casts(): array
    {
        return [
            'agent_secrets' => 'array',
            'cache_config' => 'boolean',
            'monthly_budget' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<GitHubInstallation, $this>
     */
    public function githubInstallation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class);
    }
}
