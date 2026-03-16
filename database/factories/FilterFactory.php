<?php

namespace Database\Factories;

use App\Enums\FilterOperator;
use App\Models\Filter;
use App\Models\Rule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Filter>
 */
class FilterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rule_id' => Rule::factory(),
            'filter_id' => fake()->slug(2),
            'field' => 'event.label.name',
            'operator' => FilterOperator::Equals,
            'value' => fake()->word(),
            'sort_order' => 0,
        ];
    }
}
