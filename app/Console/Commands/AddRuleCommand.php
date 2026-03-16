<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class AddRuleCommand extends Command
{
    protected $signature = 'dispatch:add-rule
        {repo : GitHub full_name (e.g. owner/repo)}
        {rule_id : User-defined rule identifier}
        {event : Webhook event type (e.g. issues.labeled)}
        {--name= : Human-readable name for the rule}
        {--prompt= : Prompt template for the agent}
        {--continue-on-error : Continue processing remaining rules even if this one fails}
        {--sort-order=0 : Sort order for rule execution}';

    protected $description = 'Add a new rule to a project';

    public function handle(): int
    {
        $repo = $this->argument('repo');
        $ruleId = $this->argument('rule_id');
        $event = $this->argument('event');

        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            $this->error("Project '{$repo}' does not exist.");

            return self::FAILURE;
        }

        if ($project->rules()->where('rule_id', $ruleId)->exists()) {
            $this->error("Rule '{$ruleId}' already exists for project '{$repo}'.");

            return self::FAILURE;
        }

        $project->rules()->create([
            'rule_id' => $ruleId,
            'name' => $this->option('name') ?? $ruleId,
            'event' => $event,
            'prompt' => $this->option('prompt') ?? '',
            'continue_on_error' => $this->option('continue-on-error'),
            'sort_order' => (int) $this->option('sort-order'),
        ]);

        $this->info("Rule '{$ruleId}' added to project '{$repo}'.");

        return self::SUCCESS;
    }
}
