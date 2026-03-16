<?php

namespace Database\Factories;

use App\Models\GitHubInstallation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitHubInstallation>
 */
class GitHubInstallationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'installation_id' => fake()->unique()->randomNumber(8),
            'account_login' => fake()->userName(),
            'account_type' => fake()->randomElement(['Organization', 'User']),
            'account_id' => fake()->unique()->randomNumber(8),
            'permissions' => ['issues' => 'write', 'pull_requests' => 'write', 'contents' => 'read'],
            'events' => ['issues', 'pull_request', 'push'],
            'target_type' => 'Organization',
        ];
    }
}
