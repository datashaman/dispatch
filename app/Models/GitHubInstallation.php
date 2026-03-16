<?php

namespace App\Models;

use Database\Factories\GitHubInstallationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GitHubInstallation extends Model
{
    /** @use HasFactory<GitHubInstallationFactory> */
    use HasFactory;

    protected $table = 'github_installations';

    protected $fillable = [
        'installation_id',
        'account_login',
        'account_type',
        'account_id',
        'permissions',
        'events',
        'target_type',
        'suspended_at',
    ];

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'account_id' => 'integer',
            'permissions' => 'array',
            'events' => 'array',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'github_installation_id');
    }
}
