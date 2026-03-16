<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Rule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rule>
 */
class RuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'rule_id' => fake()->slug(2),
            'name' => fake()->sentence(3),
            'event' => fake()->randomElement(['issues.labeled', 'issue_comment.created', 'pull_request_review_comment.created', 'discussion_comment.created']),
            'continue_on_error' => false,
            'prompt' => fake()->paragraph(),
            'sort_order' => 0,
        ];
    }

    public function withContinueOnError(): static
    {
        return $this->state(fn (array $attributes) => [
            'continue_on_error' => true,
        ]);
    }
}
