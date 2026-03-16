<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\WebhookLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'webhook_log_id' => WebhookLog::factory(),
            'rule_id' => fake()->slug(2),
            'attempt' => 1,
            'status' => 'queued',
            'output' => null,
            'tokens_used' => null,
            'cost' => null,
            'duration_ms' => null,
            'error' => null,
            'created_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'success',
            'output' => fake()->paragraph(),
            'tokens_used' => fake()->numberBetween(100, 5000),
            'cost' => fake()->randomFloat(6, 0.001, 1.0),
            'duration_ms' => fake()->numberBetween(1000, 30000),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error' => fake()->sentence(),
        ]);
    }
}
