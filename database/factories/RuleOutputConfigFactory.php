<?php

namespace Database\Factories;

use App\Models\Rule;
use App\Models\RuleOutputConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuleOutputConfig>
 */
class RuleOutputConfigFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rule_id' => Rule::factory(),
            'log' => true,
            'github_comment' => false,
            'github_reaction' => null,
        ];
    }
}
