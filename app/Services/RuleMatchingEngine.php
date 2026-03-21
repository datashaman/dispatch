<?php

namespace App\Services;

use App\DataTransferObjects\FilterConfig;
use App\DataTransferObjects\RuleConfig;
use App\Enums\FilterOperator;
use App\Exceptions\RuleMatchingException;
use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RuleMatchingEngine
{
    public function __construct(
        protected ConfigLoader $configLoader,
    ) {}

    /**
     * Match rules for a given repo and event type against the webhook payload.
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<int, RuleConfig>
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

        if (! $project->enabled) {
            throw new RuleMatchingException("Project '{$repo}' is paused");
        }

        if (! $project->path) {
            throw new RuleMatchingException("Project '{$repo}' has no local path configured");
        }

        try {
            $config = $this->configLoader->load($project->path);
        } catch (\Throwable $e) {
            throw new RuleMatchingException("Failed to load dispatch.yml for '{$repo}': {$e->getMessage()}");
        }

        $rules = collect($config->rules)
            ->filter(fn (RuleConfig $rule) => $rule->event === $eventType);

        if ($rules->isEmpty()) {
            return collect();
        }

        return $rules->filter(fn (RuleConfig $rule) => $this->evaluateFilters($rule, $payload))
            ->sortBy('sortOrder')
            ->values();
    }

    /**
     * Evaluate all filters on a rule against the payload (AND logic).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function evaluateFilters(RuleConfig $rule, array $payload): bool
    {
        if (empty($rule->filters)) {
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
    protected function evaluateFilter(FilterConfig $filter, array $payload): bool
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
