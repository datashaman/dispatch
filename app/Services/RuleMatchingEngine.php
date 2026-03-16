<?php

namespace App\Services;

use App\Enums\FilterOperator;
use App\Exceptions\RuleMatchingException;
use App\Models\Filter;
use App\Models\Project;
use App\Models\Rule;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RuleMatchingEngine
{
    /**
     * Match rules for a given repo and event type against the webhook payload.
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<int, Rule>
     *
     * @throws RuleMatchingException
     */
    public function match(string $repo, string $eventType, array $payload): Collection
    {
        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            Log::error("Rule matching: project not found for repo '{$repo}'");

            throw new RuleMatchingException("Project not found for repo '{$repo}'");
        }

        $rules = $project->rules()->with('filters')->where('event', $eventType)->get();

        if ($rules->isEmpty()) {
            return collect();
        }

        return $rules->filter(function (Rule $rule) use ($payload) {
            return $this->evaluateFilters($rule, $payload);
        })->values();
    }

    /**
     * Evaluate all filters on a rule against the payload (AND logic).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function evaluateFilters(Rule $rule, array $payload): bool
    {
        if ($rule->filters->isEmpty()) {
            return true;
        }

        foreach ($rule->filters as $filter) {
            if (! $this->evaluateFilter($filter, $payload)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single filter against the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function evaluateFilter(Filter $filter, array $payload): bool
    {
        $fieldValue = $this->resolveFieldPath($filter->field, $payload);

        return $this->applyOperator($filter->operator, $fieldValue, $filter->value);
    }

    /**
     * Resolve a dot-path field against the payload.
     * The field may have an "event." prefix which is stripped.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function resolveFieldPath(string $field, array $payload): mixed
    {
        // Strip "event." prefix if present
        if (str_starts_with($field, 'event.')) {
            $field = substr($field, 6);
        }

        return Arr::get($payload, $field);
    }

    /**
     * Apply a filter operator to compare field value against expected value.
     */
    protected function applyOperator(FilterOperator $operator, mixed $fieldValue, string $expectedValue): bool
    {
        $fieldString = (string) ($fieldValue ?? '');

        return match ($operator) {
            FilterOperator::Equals => $fieldString === $expectedValue,
            FilterOperator::NotEquals => $fieldString !== $expectedValue,
            FilterOperator::Contains => str_contains($fieldString, $expectedValue),
            FilterOperator::NotContains => ! str_contains($fieldString, $expectedValue),
            FilterOperator::StartsWith => str_starts_with($fieldString, $expectedValue),
            FilterOperator::EndsWith => str_ends_with($fieldString, $expectedValue),
            FilterOperator::Matches => $this->safeRegexMatch($expectedValue, $fieldString),
        };
    }

    /**
     * Safely evaluate a regex match, returning false on invalid patterns.
     */
    protected function safeRegexMatch(string $pattern, string $subject): bool
    {
        try {
            $result = @preg_match($pattern, $subject);
        } catch (\Throwable) {
            Log::warning("RuleMatchingEngine: invalid regex pattern '{$pattern}'");

            return false;
        }

        if ($result === false) {
            Log::warning("RuleMatchingEngine: invalid regex pattern '{$pattern}'");

            return false;
        }

        return (bool) $result;
    }
}
