<?php

namespace Database\Seeders;

use App\Enums\FilterOperator;
use App\Models\Filter;
use App\Models\Project;
use App\Models\Rule;
use App\Models\RuleAgentConfig;
use App\Models\RuleOutputConfig;
use App\Models\RuleRetryConfig;
use Illuminate\Database\Seeder;

class SparkyNanoSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::updateOrCreate(
            ['repo' => 'datashaman/sparky-nano'],
            [
                'path' => env('SPARKY_NANO_PATH', '/home/user/Projects/datashaman/sparky-nano'),
                'agent_name' => 'sparky',
                'agent_executor' => 'laravel-ai',
                'agent_provider' => 'anthropic',
                'agent_model' => 'claude-sonnet-4-6',
                'agent_instructions_file' => 'SPARKY.md',
                'agent_secrets' => ['api_key' => 'ANTHROPIC_API_KEY'],
                'cache_config' => true,
            ]
        );

        $this->seedRules($project);
    }

    private function seedRules(Project $project): void
    {
        $rules = $this->ruleDefinitions();

        foreach ($rules as $index => $definition) {
            $rule = Rule::updateOrCreate(
                ['project_id' => $project->id, 'rule_id' => $definition['rule_id']],
                [
                    'name' => $definition['name'],
                    'event' => $definition['event'],
                    'continue_on_error' => $definition['continue_on_error'] ?? false,
                    'prompt' => $definition['prompt'],
                    'sort_order' => $index,
                ]
            );

            RuleAgentConfig::updateOrCreate(
                ['rule_id' => $rule->id],
                $definition['agent_config']
            );

            RuleOutputConfig::updateOrCreate(
                ['rule_id' => $rule->id],
                $definition['output_config']
            );

            RuleRetryConfig::updateOrCreate(
                ['rule_id' => $rule->id],
                $definition['retry_config'] ?? [
                    'enabled' => false,
                    'max_attempts' => 3,
                    'delay' => 60,
                ]
            );

            $rule->filters()->delete();

            foreach ($definition['filters'] as $filterIndex => $filter) {
                Filter::create([
                    'rule_id' => $rule->id,
                    'filter_id' => $filter['filter_id'],
                    'field' => $filter['field'],
                    'operator' => $filter['operator'],
                    'value' => $filter['value'],
                    'sort_order' => $filterIndex,
                ]);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function ruleDefinitions(): array
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

                    Post your analysis as a comment on the issue.
                    PROMPT,
                'filters' => [
                    [
                        'filter_id' => 'label-sparky',
                        'field' => 'event.label.name',
                        'operator' => FilterOperator::Equals,
                        'value' => 'sparky',
                    ],
                ],
                'agent_config' => [
                    'tools' => ['read', 'glob', 'grep', 'bash'],
                    'disallowed_tools' => ['edit', 'write'],
                    'isolation' => false,
                ],
                'output_config' => [
                    'log' => true,
                    'github_comment' => true,
                    'github_reaction' => 'eyes',
                ],
            ],
            [
                'rule_id' => 'discuss',
                'name' => 'Discussion Responder',
                'event' => 'discussion_comment.created',
                'prompt' => <<<'PROMPT'
                    You are responding to a discussion comment.

                    Discussion: {{ event.discussion.title }}
                    Comment by {{ event.comment.user.login }}:
                    {{ event.comment.body }}

                    Respond helpfully to the comment. Use `gh` CLI for any GitHub interactions.
                    PROMPT,
                'filters' => [
                    [
                        'filter_id' => 'mentions-sparky',
                        'field' => 'event.comment.body',
                        'operator' => FilterOperator::Contains,
                        'value' => '@sparky',
                    ],
                ],
                'agent_config' => [
                    'tools' => ['bash'],
                    'disallowed_tools' => ['read', 'edit', 'write', 'glob', 'grep'],
                    'isolation' => false,
                ],
                'output_config' => [
                    'log' => true,
                    'github_comment' => true,
                    'github_reaction' => null,
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
                        'filter_id' => 'sparky-implement',
                        'field' => 'event.comment.body',
                        'operator' => FilterOperator::Contains,
                        'value' => '@sparky implement',
                    ],
                ],
                'agent_config' => [
                    'tools' => ['read', 'edit', 'write', 'bash', 'glob', 'grep'],
                    'disallowed_tools' => [],
                    'isolation' => true,
                ],
                'output_config' => [
                    'log' => true,
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
                    Use `gh` CLI for any GitHub interactions.
                    PROMPT,
                'filters' => [
                    [
                        'filter_id' => 'mentions-sparky',
                        'field' => 'event.comment.body',
                        'operator' => FilterOperator::Contains,
                        'value' => '@sparky',
                    ],
                    [
                        'filter_id' => 'not-implement',
                        'field' => 'event.comment.body',
                        'operator' => FilterOperator::NotContains,
                        'value' => '@sparky implement',
                    ],
                ],
                'agent_config' => [
                    'tools' => ['read', 'glob', 'grep', 'bash'],
                    'disallowed_tools' => ['edit', 'write'],
                    'isolation' => false,
                ],
                'output_config' => [
                    'log' => true,
                    'github_comment' => true,
                    'github_reaction' => null,
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
                    Use `gh` CLI for any GitHub interactions.
                    PROMPT,
                'filters' => [
                    [
                        'filter_id' => 'mentions-sparky',
                        'field' => 'event.comment.body',
                        'operator' => FilterOperator::Contains,
                        'value' => '@sparky',
                    ],
                ],
                'agent_config' => [
                    'tools' => ['read', 'glob', 'grep', 'bash'],
                    'disallowed_tools' => ['edit', 'write'],
                    'isolation' => false,
                ],
                'output_config' => [
                    'log' => true,
                    'github_comment' => true,
                    'github_reaction' => null,
                ],
            ],
        ];
    }
}
