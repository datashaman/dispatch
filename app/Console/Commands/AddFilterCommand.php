<?php

namespace App\Console\Commands;

use App\Enums\FilterOperator;
use App\Models\Project;
use Illuminate\Console\Command;

class AddFilterCommand extends Command
{
    protected $signature = 'dispatch:add-filter
        {repo : GitHub full_name (e.g. owner/repo)}
        {rule_id : Rule identifier to add filter to}
        {--filter-id= : User-defined filter identifier}
        {--field= : Dot-path into the webhook payload (e.g. event.label.name)}
        {--operator= : Filter operator (equals, not_equals, contains, not_contains, starts_with, ends_with, matches)}
        {--value= : Value to compare against}
        {--sort-order=0 : Sort order for filter evaluation}';

    protected $description = 'Add a filter to a rule';

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

        $field = $this->option('field');
        $operator = $this->option('operator');
        $value = $this->option('value');

        if (! $field || ! $operator || $value === null) {
            $this->error('The --field, --operator, and --value options are required.');

            return self::FAILURE;
        }

        $operatorEnum = FilterOperator::tryFrom($operator);

        if (! $operatorEnum) {
            $allowed = implode(', ', array_column(FilterOperator::cases(), 'value'));
            $this->error("Invalid operator '{$operator}'. Allowed: {$allowed}");

            return self::FAILURE;
        }

        $rule->filters()->create([
            'filter_id' => $this->option('filter-id'),
            'field' => $field,
            'operator' => $operatorEnum,
            'value' => $value,
            'sort_order' => (int) $this->option('sort-order'),
        ]);

        $this->info("Filter added to rule '{$ruleId}' on project '{$repo}'.");

        return self::SUCCESS;
    }
}
