<?php

namespace App\Services;

use App\Enums\FilterOperator;
use App\Models\Project;

class DefaultRulesService
{
    /**
     * Seed the default set of dispatch rules for a project.
     *
     * @return int Number of rules created.
     */
    public function seed(Project $project): int
    {
        $rules = $this->getDefaultRules();
        $created = 0;

        foreach ($rules as $sortOrder => $def) {
            if ($project->rules()->where('rule_id', $def['rule_id'])->exists()) {
                continue;
            }

            $rule = $project->rules()->create([
                'rule_id' => $def['rule_id'],
                'name' => $def['name'],
                'event' => $def['event'],
                'prompt' => $def['prompt'],
                'continue_on_error' => false,
                'sort_order' => $sortOrder,
            ]);

            $rule->agentConfig()->create([
                'tools' => $def['agent']['tools'],
                'isolation' => $def['agent']['isolation'] ?? false,
            ]);

            $rule->outputConfig()->create([
                'log' => true,
                'github_comment' => $def['output']['github_comment'] ?? true,
                'github_reaction' => $def['output']['github_reaction'] ?? null,
            ]);

            foreach ($def['filters'] as $index => $filter) {
                $rule->filters()->create([
                    'filter_id' => $filter['filter_id'],
                    'field' => $filter['field'],
                    'operator' => $filter['operator'],
                    'value' => $filter['value'],
                    'sort_order' => $index,
                ]);
            }

            $created++;
        }

        return $created;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function getDefaultRules(): array
    {
        return [
            [
                'rule_id' => 'analyze',
                'name' => 'Analyze Issue',
                'event' => 'issues.labeled',
                'prompt' => <<<'PROMPT'
                    You are triaging issue #{{ event.issue.number }}.

                    Title: {{ event.issue.title }}
                    Body:
                    {{ event.issue.body }}

                    Analyze the issue and produce a detailed plan. Consider:
                    1. What files and components are likely involved
                    2. What changes are needed
                    3. Potential risks or edge cases
                    4. A step-by-step implementation plan

                    Write your analysis as a well-structured markdown document.
                    PROMPT,
                'filters' => [
                    [
                        'filter_id' => 'label-dispatch',
                        'field' => 'event.label.name',
                        'operator' => FilterOperator::Equals,
                        'value' => 'dispatch',
                    ],
                ],
                'agent' => [
                    'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                ],
                'output' => [
                    'github_comment' => true,
                    'github_reaction' => 'eyes',
                ],
            ],
            [
                'rule_id' => 'implement',
                'name' => 'Implement Plan',
                'event' => 'issue_comment.created',
                'prompt' => <<<'PROMPT'
                    You are implementing the approved plan for issue #{{ event.issue.number }}.

                    Issue title: {{ event.issue.title }}
                    Issue body:
                    {{ event.issue.body }}

                    Trigger comment by {{ event.comment.user.login }}:
                    {{ event.comment.body }}

                    Read the issue and prior comments for the analysis and plan.
                    Implement the changes, commit them, and create a pull request.
                    Use `gh` CLI for GitHub operations (creating PRs, posting comments).
                    PROMPT,
                'filters' => [
                    [
                        'filter_id' => 'dispatch-implement',
                        'field' => 'event.comment.body',
                        'operator' => FilterOperator::Contains,
                        'value' => '@dispatch implement',
                    ],
                ],
                'agent' => [
                    'tools' => ['Read', 'Edit', 'Write', 'Bash', 'Glob', 'Grep'],
                    'isolation' => true,
                ],
                'output' => [
                    'github_comment' => true,
                    'github_reaction' => 'rocket',
                ],
            ],
            [
                'rule_id' => 'interactive',
                'name' => 'Interactive Q&A',
                'event' => 'issue_comment.created',
                'prompt' => <<<'PROMPT'
                    You are responding to a question or comment on issue #{{ event.issue.number }}.

                    Issue title: {{ event.issue.title }}
                    Issue body:
                    {{ event.issue.body }}

                    Comment by {{ event.comment.user.login }}:
                    {{ event.comment.body }}

                    Respond helpfully. You can read the codebase to answer questions.
                    PROMPT,
                'filters' => [
                    [
                        'filter_id' => 'mentions-dispatch',
                        'field' => 'event.comment.body',
                        'operator' => FilterOperator::Contains,
                        'value' => '@dispatch',
                    ],
                    [
                        'filter_id' => 'not-implement',
                        'field' => 'event.comment.body',
                        'operator' => FilterOperator::NotContains,
                        'value' => '@dispatch implement',
                    ],
                ],
                'agent' => [
                    'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                ],
                'output' => [
                    'github_comment' => true,
                ],
            ],
            [
                'rule_id' => 'review',
                'name' => 'Code Review Responder',
                'event' => 'pull_request_review_comment.created',
                'prompt' => <<<'PROMPT'
                    You are responding to a PR review comment.

                    PR: #{{ event.pull_request.number }} — {{ event.pull_request.title }}

                    Review comment by {{ event.comment.user.login }}:
                    {{ event.comment.body }}

                    File: {{ event.comment.path }}
                    Diff hunk:
                    {{ event.comment.diff_hunk }}

                    Respond to the review feedback. You can read the codebase for context.
                    PROMPT,
                'filters' => [
                    [
                        'filter_id' => 'mentions-dispatch',
                        'field' => 'event.comment.body',
                        'operator' => FilterOperator::Contains,
                        'value' => '@dispatch',
                    ],
                ],
                'agent' => [
                    'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                ],
                'output' => [
                    'github_comment' => true,
                ],
            ],
        ];
    }
}
