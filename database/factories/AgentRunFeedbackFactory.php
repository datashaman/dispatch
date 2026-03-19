<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\AgentRunFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRunFeedback>
 */
class AgentRunFeedbackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_run_id' => AgentRun::factory(),
            'user_id' => User::factory(),
            'rating' => fake()->randomElement(['helpful', 'not_helpful']),
            'comment' => null,
        ];
    }

    public function helpful(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => 'helpful',
        ]);
    }

    public function notHelpful(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => 'not_helpful',
        ]);
    }
}
