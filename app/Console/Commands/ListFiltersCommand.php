<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class ListFiltersCommand extends Command
{
    protected $signature = 'dispatch:list-filters
        {repo : GitHub full_name (e.g. owner/repo)}
        {rule_id : Rule identifier to list filters for}';

    protected $description = 'List all filters for a rule';

    public function handle(): int
    {
        $repo = $this->argument('repo');
        $ruleId = $this->argument('rule_id');

        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            $this->error("Project '{$repo}' does not exist.");

            return self::FAILURE;
        }

        $rule = $project->rules()->where('rule_id', $ruleId)->first();

        if (! $rule) {
            $this->error("Rule '{$ruleId}' does not exist for project '{$repo}'.");

            return self::FAILURE;
        }

        $filters = $rule->filters()->orderBy('sort_order')->get();

        if ($filters->isEmpty()) {
            $this->info("No filters found for rule '{$ruleId}'.");

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Filter ID', 'Field', 'Operator', 'Value', 'Sort Order'],
            $filters->map(fn ($filter) => [
                $filter->id,
                $filter->filter_id ?? '-',
                $filter->field,
                $filter->operator->value,
                $filter->value,
                $filter->sort_order,
            ])->toArray()
        );

        return self::SUCCESS;
    }
}
