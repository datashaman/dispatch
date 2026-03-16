<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class ListRulesCommand extends Command
{
    protected $signature = 'dispatch:list-rules
        {repo : GitHub full_name (e.g. owner/repo)}';

    protected $description = 'List all rules for a project';

    public function handle(): int
    {
        $repo = $this->argument('repo');

        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            $this->error("Project '{$repo}' does not exist.");

            return self::FAILURE;
        }

        $rules = $project->rules()->orderBy('sort_order')->get();

        if ($rules->isEmpty()) {
            $this->info("No rules found for project '{$repo}'.");

            return self::SUCCESS;
        }

        $this->table(
            ['Rule ID', 'Name', 'Event', 'Continue on Error', 'Sort Order'],
            $rules->map(fn ($rule) => [
                $rule->rule_id,
                $rule->name,
                $rule->event,
                $rule->continue_on_error ? 'Yes' : 'No',
                $rule->sort_order,
            ]),
        );

        return self::SUCCESS;
    }
}
