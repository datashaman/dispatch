<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class ShowRuleCommand extends Command
{
    protected $signature = 'dispatch:show-rule
        {repo : GitHub full_name (e.g. owner/repo)}
        {rule_id : Rule identifier to display}';

    protected $description = 'Display full rule configuration including agent, output, retry, and filters';

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

        $rule->load(['agentConfig', 'outputConfig', 'retryConfig', 'filters']);

        $this->info("Rule: {$rule->rule_id}");
        $this->line("  Name:          {$rule->name}");
        $this->line("  Event:         {$rule->event}");
        $this->line('  Continue on Error: '.($rule->continue_on_error ? 'Yes' : 'No'));
        $this->line("  Sort Order:    {$rule->sort_order}");
        $this->line("  Prompt:        {$rule->prompt}");

        $this->newLine();
        $this->info('Agent Config:');
        $agentConfig = $rule->agentConfig;

        $provider = $agentConfig?->provider ?? $project->agent_provider;
        $model = $agentConfig?->model ?? $project->agent_model;
        $maxTokens = $agentConfig?->max_tokens;
        $tools = $agentConfig?->tools;
        $disallowedTools = $agentConfig?->disallowed_tools;
        $isolation = $agentConfig?->isolation ?? false;

        $this->line('  Provider:         '.($provider ?? '(not set)').($agentConfig?->provider === null && $project->agent_provider ? ' (from project)' : ''));
        $this->line('  Model:            '.($model ?? '(not set)').($agentConfig?->model === null && $project->agent_model ? ' (from project)' : ''));
        $this->line('  Max Tokens:       '.($maxTokens !== null ? $maxTokens : '(not set)'));
        $this->line('  Tools:            '.($tools ? implode(', ', $tools) : '(not set)'));
        $this->line('  Disallowed Tools: '.($disallowedTools ? implode(', ', $disallowedTools) : '(not set)'));
        $this->line('  Isolation:        '.($isolation ? 'Yes' : 'No'));

        $this->newLine();
        $this->info('Output Config:');
        $outputConfig = $rule->outputConfig;

        $this->line('  Log:             '.($outputConfig?->log ?? true ? 'Yes' : 'No'));
        $this->line('  GitHub Comment:  '.($outputConfig?->github_comment ?? false ? 'Yes' : 'No'));
        $this->line('  GitHub Reaction: '.($outputConfig?->github_reaction ?? '(not set)'));

        $this->newLine();
        $this->info('Retry Config:');
        $retryConfig = $rule->retryConfig;

        $this->line('  Enabled:      '.($retryConfig?->enabled ?? false ? 'Yes' : 'No'));
        $this->line('  Max Attempts: '.($retryConfig?->max_attempts ?? 3));
        $this->line('  Delay:        '.($retryConfig?->delay ?? 60).'s');

        $this->newLine();
        $this->info('Filters:');

        if ($rule->filters->isEmpty()) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['ID', 'Filter ID', 'Field', 'Operator', 'Value', 'Sort Order'],
                $rule->filters->map(fn ($f) => [
                    $f->id,
                    $f->filter_id ?? '—',
                    $f->field,
                    $f->operator->value,
                    $f->value,
                    $f->sort_order,
                ])->toArray(),
            );
        }

        return self::SUCCESS;
    }
}
