<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'agent_executor',
        'agent_provider',
        'agent_model',
        'agent_instructions_file',
        'agent_secrets',
        'stitch_project_id',
        'cache_config',
        'enabled',
        'monthly_budget',
        'github_installation_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'agent_secrets' => 'encrypted:array',
            'cache_config' => 'boolean',
            'enabled' => 'boolean',
            'monthly_budget' => 'decimal:2',
        ];
    }

    /**
     * Derive the local path from the repo name so it always uses
     * the current storage directory, surviving deploys.
     */
    protected function path(): Attribute
    {
        return Attribute::get(fn ($value) => $value ?: storage_path("repos/{$this->repo}"));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<GitHubInstallation, $this>
     */
    public function githubInstallation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class);
    }
}
