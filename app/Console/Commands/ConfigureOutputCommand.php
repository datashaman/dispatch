<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class ConfigureOutputCommand extends Command
{
    protected $signature = 'dispatch:configure-output
        {repo : GitHub full_name (e.g. owner/repo)}
        {rule_id : Rule identifier to configure output for}
        {--log= : Log agent output (true/false)}
        {--github-comment= : Post output as GitHub comment (true/false)}
        {--github-reaction= : Add reaction to triggering comment (e.g. eyes, rocket)}';

    protected $description = 'Configure output settings for a rule';

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

        if ($this->option('log') !== null) {
            $updates['log'] = filter_var($this->option('log'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($this->option('github-comment') !== null) {
            $updates['github_comment'] = filter_var($this->option('github-comment'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($this->option('github-reaction') !== null) {
            $updates['github_reaction'] = $this->option('github-reaction');
        }

        if (empty($updates)) {
            $this->warn('No options provided. Nothing to configure.');

            return self::SUCCESS;
        }

        $rule->outputConfig()->updateOrCreate(
            ['rule_id' => $rule->id],
            $updates,
        );

        $this->info("Output config updated for rule '{$ruleId}' on project '{$repo}'.");

        return self::SUCCESS;
    }
}
