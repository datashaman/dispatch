<?php

namespace App\Services;

use App\DataTransferObjects\AgentConfig;
use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\FilterConfig;
use App\DataTransferObjects\OutputConfig;
use App\DataTransferObjects\RuleConfig;
use App\Enums\FilterOperator;
use App\Models\Project;

class DefaultRulesService
{
    public function __construct(
        protected ConfigWriter $configWriter,
    ) {}

    /**
     * Seed a default dispatch.yml for a project (only if one doesn't already exist).
     */
    public function seed(Project $project): bool
    {
        $dispatchYml = rtrim($project->path, '/').'/dispatch.yml';

        if (file_exists($dispatchYml)) {
            return false;
        }

        $config = $this->buildDefaultConfig($project);
        $this->configWriter->write($config, $project->path);

        return true;
    }

    /**
     * Build the default DispatchConfig for a project.
     */
    protected function buildDefaultConfig(Project $project): DispatchConfig
    {
        $agentName = basename($project->repo);

        return new DispatchConfig(
            version: 1,
            agentName: $agentName,
            agentExecutor: 'laravel-ai',
            agentInstructionsFile: 'AGENTS.md',
            agentProvider: 'anthropic',
            agentModel: 'claude-sonnet-4-6',
            cacheConfig: true,
            rules: $this->getDefaultRules(),
        );
    }

    /**
     * @return list<RuleConfig>
     */
    protected function getDefaultRules(): array
    {
        return [
            new RuleConfig(
                id: 'analyze',
                event: 'issues.labeled',
                prompt: implode("\n", [
                    'You are triaging issue #{{ event.issue.number }}.',
                    '',
                    'Title: {{ event.issue.title }}',
                    'Body:',
                    '{{ event.issue.body }}',
                    '',
                    'Analyze the issue and produce a detailed plan. Consider:',
                    '1. What files and components are likely involved',
                    '2. What changes are needed',
                    '3. Potential risks or edge cases',
                    '4. A step-by-step implementation plan',
                    '',
                    'Write your analysis as a well-structured markdown document.',
                ]),
                name: 'Analyze Issue',
                filters: [
                    new FilterConfig(
                        id: 'label-dispatch',
                        field: 'event.label.name',
                        operator: FilterOperator::Equals,
                        value: 'dispatch',
                    ),
                ],
                agent: new AgentConfig(
                    tools: ['Read', 'Glob', 'Grep', 'Bash'],
                ),
                output: new OutputConfig(
                    githubComment: true,
                    githubReaction: 'eyes',
                ),
            ),
            new RuleConfig(
                id: 'implement',
                event: 'issue_comment.created',
                prompt: implode("\n", [
                    'You are implementing the approved plan for issue #{{ event.issue.number }}.',
                    '',
                    'Issue title: {{ event.issue.title }}',
                    'Issue body:',
                    '{{ event.issue.body }}',
                    '',
                    'Trigger comment by {{ event.comment.user.login }}:',
                    '{{ event.comment.body }}',
                    '',
                    'Read the issue and prior comments for the analysis and plan.',
                    'Implement the changes, commit them, and create a pull request.',
                    'Use `gh` CLI for GitHub operations (creating PRs, posting comments).',
                ]),
                name: 'Implement Plan',
                sortOrder: 1,
                filters: [
                    new FilterConfig(
                        id: 'dispatch-implement',
                        field: 'event.comment.body',
                        operator: FilterOperator::Contains,
                        value: '@dispatch implement',
                    ),
                ],
                agent: new AgentConfig(
                    tools: ['Read', 'Edit', 'Write', 'Bash', 'Glob', 'Grep'],
                    isolation: true,
                ),
                output: new OutputConfig(
                    githubComment: true,
                    githubReaction: 'rocket',
                ),
            ),
            new RuleConfig(
                id: 'interactive',
                event: 'issue_comment.created',
                prompt: implode("\n", [
                    'You are responding to a question or comment on issue #{{ event.issue.number }}.',
                    '',
                    'Issue title: {{ event.issue.title }}',
                    'Issue body:',
                    '{{ event.issue.body }}',
                    '',
                    'Comment by {{ event.comment.user.login }}:',
                    '{{ event.comment.body }}',
                    '',
                    'Respond helpfully. You can read the codebase to answer questions.',
                ]),
                name: 'Interactive Q&A',
                sortOrder: 2,
                filters: [
                    new FilterConfig(
                        id: 'mentions-dispatch',
                        field: 'event.comment.body',
                        operator: FilterOperator::Contains,
                        value: '@dispatch',
                    ),
                    new FilterConfig(
                        id: 'not-implement',
                        field: 'event.comment.body',
                        operator: FilterOperator::NotContains,
                        value: '@dispatch implement',
                    ),
                ],
                agent: new AgentConfig(
                    tools: ['Read', 'Glob', 'Grep', 'Bash'],
                ),
                output: new OutputConfig(
                    githubComment: true,
                ),
            ),
            new RuleConfig(
                id: 'review',
                event: 'pull_request_review_comment.created',
                prompt: implode("\n", [
                    'You are responding to a PR review comment.',
                    '',
                    'PR: #{{ event.pull_request.number }} — {{ event.pull_request.title }}',
                    '',
                    'Review comment by {{ event.comment.user.login }}:',
                    '{{ event.comment.body }}',
                    '',
                    'File: {{ event.comment.path }}',
                    'Diff hunk:',
                    '{{ event.comment.diff_hunk }}',
                    '',
                    'Respond to the review feedback. You can read the codebase for context.',
                ]),
                name: 'Code Review Responder',
                sortOrder: 3,
                filters: [
                    new FilterConfig(
                        id: 'mentions-dispatch',
                        field: 'event.comment.body',
                        operator: FilterOperator::Contains,
                        value: '@dispatch',
                    ),
                ],
                agent: new AgentConfig(
                    tools: ['Read', 'Glob', 'Grep', 'Bash'],
                ),
                output: new OutputConfig(
                    githubComment: true,
                ),
            ),
        ];
    }
}
