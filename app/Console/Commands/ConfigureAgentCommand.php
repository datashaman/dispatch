<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class ConfigureAgentCommand extends Command
{
    protected $signature = 'dispatch:configure-agent
        {repo : GitHub full_name (e.g. owner/repo)}
        {rule_id : Rule identifier to configure agent for}
        {--provider= : AI provider (e.g. anthropic, openai)}
        {--model= : Model name (e.g. claude-sonnet-4-5-20250514)}
        {--max-tokens= : Maximum tokens for the response}
        {--tools= : Comma-separated list of allowed tools}
        {--disallowed-tools= : Comma-separated list of disallowed tools}
        {--isolation= : Run in isolated worktree (true/false)}';

    protected $description = 'Configure agent settings for a rule';

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

        if ($this->option('provider') !== null) {
            $updates['provider'] = $this->option('provider');
        }

        if ($this->option('model') !== null) {
            $updates['model'] = $this->option('model');
        }

        if ($this->option('max-tokens') !== null) {
            $updates['max_tokens'] = (int) $this->option('max-tokens');
        }

        if ($this->option('tools') !== null) {
            $updates['tools'] = array_map('trim', explode(',', $this->option('tools')));
        }

        if ($this->option('disallowed-tools') !== null) {
            $updates['disallowed_tools'] = array_map('trim', explode(',', $this->option('disallowed-tools')));
        }

        if ($this->option('isolation') !== null) {
            $updates['isolation'] = filter_var($this->option('isolation'), FILTER_VALIDATE_BOOLEAN);
        }

        if (empty($updates)) {
            $this->warn('No options provided. Nothing to configure.');

            return self::SUCCESS;
        }

        $rule->agentConfig()->updateOrCreate(
            ['rule_id' => $rule->id],
            $updates,
        );

        $this->info("Agent config updated for rule '{$ruleId}' on project '{$repo}'.");

        return self::SUCCESS;
    }
}
