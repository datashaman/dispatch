<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class ConfigureRetryCommand extends Command
{
    protected $signature = 'dispatch:configure-retry
        {repo : GitHub full_name (e.g. owner/repo)}
        {rule_id : Rule identifier to configure retry for}
        {--enabled= : Enable retry on failure (true/false)}
        {--max-attempts= : Maximum number of retry attempts}
        {--delay= : Delay between retries in seconds}';

    protected $description = 'Configure retry settings for a rule';

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

        if ($this->option('enabled') !== null) {
            $updates['enabled'] = filter_var($this->option('enabled'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($this->option('max-attempts') !== null) {
            $updates['max_attempts'] = (int) $this->option('max-attempts');
        }

        if ($this->option('delay') !== null) {
            $updates['delay'] = (int) $this->option('delay');
        }

        if (empty($updates)) {
            $this->warn('No options provided. Nothing to configure.');

            return self::SUCCESS;
        }

        $rule->retryConfig()->updateOrCreate(
            ['rule_id' => $rule->id],
            $updates,
        );

        $this->info("Retry config updated for rule '{$ruleId}' on project '{$repo}'.");

        return self::SUCCESS;
    }
}
