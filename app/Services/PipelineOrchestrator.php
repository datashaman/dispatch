<?php

namespace App\Services;

use App\DataTransferObjects\RuleConfig;
use App\Exceptions\PipelineException;
use Illuminate\Support\Collection;

class PipelineOrchestrator
{
    /**
     * Determine if any of the matched rules form a pipeline (have dependencies).
     *
     * @param  Collection<int, RuleConfig>  $rules
     */
    public function hasPipeline(Collection $rules): bool
    {
        return $rules->contains(fn (RuleConfig $rule) => ! empty($rule->dependsOn));
    }

    /**
     * Topologically sort rules by their depends_on relationships.
     * Rules without dependencies come first, then dependent rules follow their dependencies.
     *
     * @param  Collection<int, RuleConfig>  $rules
     * @return Collection<int, RuleConfig>
     *
     * @throws PipelineException
     */
    public function resolve(Collection $rules): Collection
    {
        // Detect duplicate rule IDs before building the map
        $ids = $rules->pluck('id');
        $duplicates = $ids->duplicates();
        if ($duplicates->isNotEmpty()) {
            $dupeList = $duplicates->unique()->implode(', ');
            throw new PipelineException("Duplicate rule IDs detected: {$dupeList}");
        }

        $ruleMap = $rules->keyBy('id');
        $sorted = [];
        $visited = [];
        $visiting = [];

        foreach ($ruleMap as $id => $rule) {
            if (! isset($visited[$id])) {
                $this->visit($id, $ruleMap, $sorted, $visited, $visiting);
            }
        }

        return collect($sorted);
    }

    /**
     * Depth-first visit for topological sort with cycle detection.
     *
     * @param  Collection<string, RuleConfig>  $ruleMap
     * @param  list<RuleConfig>  $sorted
     * @param  array<string, bool>  $visited
     * @param  array<string, bool>  $visiting
     *
     * @throws PipelineException
     */
    private function visit(string $id, Collection $ruleMap, array &$sorted, array &$visited, array &$visiting): void
    {
        if (isset($visiting[$id])) {
            throw new PipelineException("Circular dependency detected involving rule '{$id}'");
        }

        if (isset($visited[$id])) {
            return;
        }

        $visiting[$id] = true;

        $rule = $ruleMap->get($id);

        if (! $rule) {
            throw new PipelineException("Rule '{$id}' referenced in depends_on but not matched");
        }

        foreach ($rule->dependsOn as $depId) {
            if (! $ruleMap->has($depId)) {
                throw new PipelineException("Rule '{$id}' depends on '{$depId}' which was not matched");
            }
            $this->visit($depId, $ruleMap, $sorted, $visited, $visiting);
        }

        unset($visiting[$id]);
        $visited[$id] = true;
        $sorted[] = $rule;
    }
}
