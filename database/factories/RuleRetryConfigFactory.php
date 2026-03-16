<?php

namespace Database\Factories;

use App\Models\Rule;
use App\Models\RuleRetryConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuleRetryConfig>
 */
class RuleRetryConfigFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rule_id' => Rule::factory(),
            'enabled' => false,
            'max_attempts' => 3,
            'delay' => 60,
        ];
    }
}
