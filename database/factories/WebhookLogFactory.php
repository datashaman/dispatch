<?php

namespace Database\Factories;

use App\Models\WebhookLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookLog>
 */
class WebhookLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_type' => 'issues.labeled',
            'repo' => fake()->userName().'/'.fake()->slug(2),
            'payload' => ['action' => 'labeled', 'repository' => ['full_name' => 'test/repo']],
            'matched_rules' => null,
            'status' => 'received',
            'error' => null,
            'created_at' => now(),
        ];
    }
}
