<?php

namespace Database\Factories;

use App\Enums\FeedbackRating;
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
            'rating' => fake()->randomElement(FeedbackRating::cases()),
            'comment' => null,
        ];
    }

    public function helpful(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => FeedbackRating::Helpful,
        ]);
    }

    public function notHelpful(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => FeedbackRating::NotHelpful,
        ]);
    }
}
