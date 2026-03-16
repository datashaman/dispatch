<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class RemoveFilterCommand extends Command
{
    protected $signature = 'dispatch:remove-filter
        {repo : GitHub full_name (e.g. owner/repo)}
        {rule_id : Rule identifier}
        {filter_id : Filter identifier to remove}';

    protected $description = 'Remove a filter from a rule';

    public function handle(): int
    {
        $repo = $this->argument('repo');
        $ruleId = $this->argument('rule_id');
        $filterId = $this->argument('filter_id');

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

        $filter = $rule->filters()->where('filter_id', $filterId)->first();

        if (! $filter) {
            $this->error("Filter '{$filterId}' does not exist for rule '{$ruleId}'.");

            return self::FAILURE;
        }

        if (! $this->confirm("Are you sure you want to remove filter '{$filterId}' from rule '{$ruleId}'?")) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $filter->delete();

        $this->info("Filter '{$filterId}' removed from rule '{$ruleId}' on project '{$repo}'.");

        return self::SUCCESS;
    }
}
