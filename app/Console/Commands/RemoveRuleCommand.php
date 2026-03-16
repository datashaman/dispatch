<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class RemoveRuleCommand extends Command
{
    protected $signature = 'dispatch:remove-rule
        {repo : GitHub full_name (e.g. owner/repo)}
        {rule_id : User-defined rule identifier}';

    protected $description = 'Remove a rule and its associated configs from a project';

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

        if (! $this->confirm("Are you sure you want to remove rule '{$ruleId}' and all its associated configs?")) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $rule->delete();

        $this->info("Rule '{$ruleId}' removed from project '{$repo}'.");

        return self::SUCCESS;
    }
}
