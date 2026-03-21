<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'repo' => fake()->userName().'/'.fake()->slug(2),
            'path' => '/home/user/Projects/'.fake()->slug(2),
            'enabled' => true,
        ];
    }
}
