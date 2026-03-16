<?php

namespace Database\Factories;

use App\Models\Rule;
use App\Models\RuleAgentConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuleAgentConfig>
 */
class RuleAgentConfigFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rule_id' => Rule::factory(),
            'provider' => null,
            'model' => null,
            'max_tokens' => null,
            'tools' => null,
            'disallowed_tools' => null,
            'isolation' => false,
        ];
    }

    public function withTools(array $tools): static
    {
        return $this->state(fn (array $attributes) => [
            'tools' => $tools,
        ]);
    }

    public function isolated(): static
    {
        return $this->state(fn (array $attributes) => [
            'isolation' => true,
        ]);
    }
}
