<?php

namespace App\Services;

class CostCalculator
{
    /**
     * Pricing per million tokens (input, output) by model.
     *
     * @var array<string, array{input: float, output: float}>
     */
    protected const PRICING = [
        // Anthropic
        'claude-sonnet-4-5-20250514' => ['input' => 3.0, 'output' => 15.0],
        'claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0],
        'claude-haiku-4-5-20251001' => ['input' => 0.80, 'output' => 4.0],
        'claude-opus-4-6' => ['input' => 15.0, 'output' => 75.0],
        // OpenAI
        'gpt-4o' => ['input' => 2.50, 'output' => 10.0],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'o3-mini' => ['input' => 1.10, 'output' => 4.40],
        // Google
        'gemini-2.0-flash' => ['input' => 0.10, 'output' => 0.40],
        'gemini-2.5-pro-preview-03-25' => ['input' => 1.25, 'output' => 10.0],
    ];

    /**
     * Calculate cost from token counts and model name.
     *
     * Returns null if model pricing is unknown.
     */
    public function calculate(int $promptTokens, int $completionTokens, ?string $model): ?string
    {
        if (! $model) {
            return null;
        }

        $pricing = $this->resolvePricing($model);

        if (! $pricing) {
            return null;
        }

        $cost = ($promptTokens * $pricing['input'] / 1_000_000)
              + ($completionTokens * $pricing['output'] / 1_000_000);

        return number_format($cost, 6, '.', '');
    }

    /**
     * Resolve pricing for a model, supporting prefix matching.
     *
     * @return array{input: float, output: float}|null
     */
    protected function resolvePricing(string $model): ?array
    {
        // Exact match first
        if (isset(self::PRICING[$model])) {
            return self::PRICING[$model];
        }

        // Prefix match (e.g., 'claude-sonnet-4-5-20250514' matches 'claude-sonnet-4-5')
        foreach (self::PRICING as $key => $pricing) {
            if (str_starts_with($model, $key) || str_starts_with($key, $model)) {
                return $pricing;
            }
        }

        return null;
    }
}
