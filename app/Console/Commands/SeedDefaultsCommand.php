<?php

namespace App\Console\Commands;

use App\Enums\FilterOperator;
use App\Models\Project;
use App\Services\ConfigSyncer;
use Illuminate\Console\Command;

class SeedDefaultsCommand extends Command
{
    protected $signature = 'dispatch:seed-defaults {repo : GitHub full_name (e.g. owner/repo)}';

    protected $description = 'Seed default agent rules (sparky-nano examples) for a project and export to dispatch.yml';

    public function handle(ConfigSyncer $syncer): int
    {
        $repo = $this->argument('repo');

        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            $this->error("Project '{$repo}' not found.");

            return self::FAILURE;
        }

        $defaults = $this->getDefaultRules();
        $sortOrder = 0;

        foreach ($defaults as $def) {
            if ($project->rules()->where('rule_id', $def['rule_id'])->exists()) {
                $this->warn("Rule '{$def['rule_id']}' already exists, skipping.");

                continue;
            }

            $rule = $project->rules()->create([
                'rule_id' => $def['rule_id'],
                'name' => $def['name'],
                'event' => $def['event'],
                'prompt' => $def['prompt'],
                'circuit_break' => $def['circuit_break'] ?? false,
                'sort_order' => $sortOrder++,
            ]);

            // Create agent config
            $rule->agentConfig()->create([
                'tools' => $def['agent']['tools'],
                'disallowed_tools' => $def['agent']['disallowed_tools'] ?? [],
                'isolation' => $def['agent']['isolation'] ?? false,
            ]);

            // Create output config
            $rule->outputConfig()->create([
                'log' => true,
                'github_comment' => true,
                'github_reaction' => $def['output']['github_reaction'] ?? null,
            ]);

            // Create filters
            foreach ($def['filters'] as $index => $filter) {
                $rule->filters()->create([
                    'filter_id' => $filter['filter_id'],
                    'field' => $filter['field'],
                    'operator' => $filter['operator'],
                    'value' => $filter['value'],
                    'sort_order' => $index,
                ]);
            }

            $this->info("Seeded rule '{$def['rule_id']}'.");
        }

        $syncer->export($project);

        $this->info("Exported seeded rules to dispatch.yml for '{$repo}'.");

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getDefaultRules(): array
    {
        return [
            [
                'rule_id' => 'analyze',
                'name' => 'Analyze Issue',
                'event' => 'issues.labeled',
                'prompt' => <<<'PROMPT'
Analyze the following GitHub issue and provide insights:

Title: {{ event.issue.title }}
Body: {{ event.issue.body }}
Author: {{ event.issue.user.login }}
Labels: {{ event.label.name }}

Provide a detailed analysis of the issue, including:
1. A summary of the problem
2. Potential root causes
3. Suggested approach for resolution
PROMPT,
                'circuit_break' => false,
                'filters' => [
                    [
                        'filter_id' => 'label-sparky',
                        'field' => 'label.name',
                        'operator' => FilterOperator::Equals->value,
                        'value' => 'sparky',
                    ],
                ],
                'agent' => [
                    'tools' => ['Read', 'Glob', 'Grep'],
                    'isolation' => false,
                ],
                'output' => [
                    'github_reaction' => 'eyes',
                ],
            ],
            [
                'rule_id' => 'discuss',
                'name' => 'Discussion Response',
                'event' => 'discussion_comment.created',
                'prompt' => <<<'PROMPT'
Respond to this discussion comment:

Discussion: {{ event.discussion.title }}
Comment by {{ event.comment.user.login }}:
{{ event.comment.body }}

Provide a helpful and informative response.
PROMPT,
                'circuit_break' => false,
                'filters' => [
                    [
                        'filter_id' => 'mentions-sparky',
                        'field' => 'comment.body',
                        'operator' => FilterOperator::Contains->value,
                        'value' => '@sparky',
                    ],
                ],
                'agent' => [
                    'tools' => ['Bash'],
                    'isolation' => false,
                ],
                'output' => [
                    'github_reaction' => 'eyes',
                ],
            ],
            [
                'rule_id' => 'implement',
                'name' => 'Implement Request',
                'event' => 'issue_comment.created',
                'prompt' => <<<'PROMPT'
Implement the following request from a GitHub issue comment:

Issue: {{ event.issue.title }}
Comment by {{ event.comment.user.login }}:
{{ event.comment.body }}

Read the issue context, understand the request, and implement the changes.
Create a new branch, make the changes, and commit them.
PROMPT,
                'circuit_break' => false,
                'filters' => [
                    [
                        'filter_id' => 'mentions-sparky-implement',
                        'field' => 'comment.body',
                        'operator' => FilterOperator::Contains->value,
                        'value' => '@sparky implement',
                    ],
                ],
                'agent' => [
                    'tools' => ['Read', 'Edit', 'Write', 'Bash', 'Glob', 'Grep'],
                    'isolation' => true,
                ],
                'output' => [
                    'github_reaction' => 'rocket',
                ],
            ],
            [
                'rule_id' => 'interactive',
                'name' => 'Interactive Response',
                'event' => 'issue_comment.created',
                'prompt' => <<<'PROMPT'
Respond to this issue comment interactively:

Issue: {{ event.issue.title }}
Comment by {{ event.comment.user.login }}:
{{ event.comment.body }}

Provide a helpful response. You can read the codebase and run commands to gather information.
PROMPT,
                'circuit_break' => false,
                'filters' => [
                    [
                        'filter_id' => 'mentions-sparky',
                        'field' => 'comment.body',
                        'operator' => FilterOperator::Contains->value,
                        'value' => '@sparky',
                    ],
                    [
                        'filter_id' => 'not-implement',
                        'field' => 'comment.body',
                        'operator' => FilterOperator::NotContains->value,
                        'value' => '@sparky implement',
                    ],
                ],
                'agent' => [
                    'tools' => ['Read', 'Bash', 'Glob', 'Grep'],
                    'isolation' => false,
                ],
                'output' => [
                    'github_reaction' => 'eyes',
                ],
            ],
            [
                'rule_id' => 'review',
                'name' => 'Code Review Response',
                'event' => 'pull_request_review_comment.created',
                'prompt' => <<<'PROMPT'
Respond to this pull request review comment:

PR: {{ event.pull_request.title }}
Comment by {{ event.comment.user.login }}:
{{ event.comment.body }}
File: {{ event.comment.path }}
Line: {{ event.comment.line }}

Provide a helpful review response, analyzing the code in context.
PROMPT,
                'circuit_break' => false,
                'filters' => [
                    [
                        'filter_id' => 'mentions-sparky',
                        'field' => 'comment.body',
                        'operator' => FilterOperator::Contains->value,
                        'value' => '@sparky',
                    ],
                ],
                'agent' => [
                    'tools' => ['Read', 'Bash', 'Glob', 'Grep'],
                    'isolation' => false,
                ],
                'output' => [
                    'github_reaction' => 'eyes',
                ],
            ],
        ];
    }
}
