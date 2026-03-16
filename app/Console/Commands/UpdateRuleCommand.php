<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class UpdateRuleCommand extends Command
{
    protected $signature = 'dispatch:update-rule
        {repo : GitHub full_name (e.g. owner/repo)}
        {rule_id : User-defined rule identifier}
        {--name= : Human-readable name for the rule}
        {--event= : Webhook event type (e.g. issues.labeled)}
        {--prompt= : Prompt template for the agent}
        {--continue-on-error= : Continue on error (true/false)}
        {--sort-order= : Sort order for rule execution}';

    protected $description = 'Update an existing rule for a project';

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

        $updates = [];

        if ($this->option('name') !== null) {
            $updates['name'] = $this->option('name');
        }

        if ($this->option('event') !== null) {
            $updates['event'] = $this->option('event');
        }

        if ($this->option('prompt') !== null) {
            $updates['prompt'] = $this->option('prompt');
        }

        if ($this->option('continue-on-error') !== null) {
            $updates['continue_on_error'] = filter_var($this->option('continue-on-error'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($this->option('sort-order') !== null) {
            $updates['sort_order'] = (int) $this->option('sort-order');
        }

        if (empty($updates)) {
            $this->warn('No options provided. Nothing to update.');

            return self::SUCCESS;
        }

        $rule->update($updates);

        $this->info("Rule '{$ruleId}' updated for project '{$repo}'.");

        return self::SUCCESS;
    }
}
