<?php

namespace App\Services;

class TemplateRegistry
{
    /**
     * Get all available rule templates.
     *
     * @return list<array{id: string, name: string, description: string, category: string, event: string, tools: list<string>, rule: array<string, mixed>}>
     */
    public function all(): array
    {
        return [
            [
                'id' => 'issue-triage',
                'name' => 'Issue Triage',
                'description' => 'Auto-label, prioritize, and analyze new issues. Reads the codebase to identify affected files and suggest an approach.',
                'category' => 'triage',
                'event' => 'issues.opened',
                'tools' => ['Read', 'Glob', 'Grep'],
                'rule' => [
                    'id' => 'issue-triage',
                    'event' => 'issues.opened',
                    'name' => 'Issue Triage',
                    'prompt' => implode("\n", [
                        'You are triaging issue #{{ event.issue.number }}.',
                        '',
                        'Title: {{ event.issue.title }}',
                        'Body:',
                        '{{ event.issue.body }}',
                        '',
                        'Analyze the issue and produce:',
                        '1. What files and components are likely involved',
                        '2. Severity assessment (critical, high, medium, low)',
                        '3. Whether this is a bug, feature request, or question',
                        '4. A brief recommended approach',
                    ]),
                    'agent' => [
                        'tools' => ['Read', 'Glob', 'Grep'],
                    ],
                    'output' => [
                        'log' => true,
                        'github_comment' => true,
                        'github_reaction' => 'eyes',
                    ],
                ],
            ],
            [
                'id' => 'pr-review',
                'name' => 'PR Code Review',
                'description' => 'Review pull requests for bugs, security issues, and style. Posts structured feedback as a PR comment.',
                'category' => 'review',
                'event' => 'pull_request.opened',
                'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                'rule' => [
                    'id' => 'pr-review',
                    'event' => 'pull_request.opened',
                    'name' => 'PR Code Review',
                    'prompt' => implode("\n", [
                        'You are reviewing PR #{{ event.pull_request.number }}.',
                        '',
                        'Title: {{ event.pull_request.title }}',
                        'Body:',
                        '{{ event.pull_request.body }}',
                        '',
                        'Review the changes for:',
                        '1. Bugs and logic errors',
                        '2. Security vulnerabilities (injection, auth bypass, secrets)',
                        '3. Performance issues',
                        '4. Code style and readability',
                        '',
                        'Use `git diff origin/{{ event.pull_request.base.ref }}...HEAD` to see changes.',
                        'Post a structured review with sections for each category.',
                    ]),
                    'agent' => [
                        'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                    ],
                    'output' => [
                        'log' => true,
                        'github_comment' => true,
                        'github_reaction' => 'eyes',
                    ],
                ],
            ],
            [
                'id' => 'review-responder',
                'name' => 'Review Comment Responder',
                'description' => 'Respond to PR review comments mentioning @dispatch. Reads the codebase for context and posts a reply.',
                'category' => 'review',
                'event' => 'pull_request_review_comment.created',
                'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                'rule' => [
                    'id' => 'review-responder',
                    'event' => 'pull_request_review_comment.created',
                    'name' => 'Review Comment Responder',
                    'prompt' => implode("\n", [
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
                        'Respond to the review feedback with context from the codebase.',
                    ]),
                    'filters' => [
                        [
                            'id' => 'mentions-dispatch',
                            'field' => 'event.comment.body',
                            'operator' => 'contains',
                            'value' => '@dispatch',
                        ],
                    ],
                    'agent' => [
                        'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                    ],
                    'output' => [
                        'log' => true,
                        'github_comment' => true,
                    ],
                ],
            ],
            [
                'id' => 'implement',
                'name' => 'Issue Implementer',
                'description' => 'Implement approved plans when triggered by "@dispatch implement" in issue comments. Creates a branch, commits changes, and opens a PR.',
                'category' => 'implementation',
                'event' => 'issue_comment.created',
                'tools' => ['Read', 'Edit', 'Write', 'Bash', 'Glob', 'Grep'],
                'rule' => [
                    'id' => 'implement',
                    'event' => 'issue_comment.created',
                    'name' => 'Issue Implementer',
                    'prompt' => implode("\n", [
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
                    ]),
                    'filters' => [
                        [
                            'id' => 'dispatch-implement',
                            'field' => 'event.comment.body',
                            'operator' => 'contains',
                            'value' => '@dispatch implement',
                        ],
                    ],
                    'agent' => [
                        'tools' => ['Read', 'Edit', 'Write', 'Bash', 'Glob', 'Grep'],
                        'isolation' => true,
                    ],
                    'output' => [
                        'log' => true,
                        'github_comment' => true,
                        'github_reaction' => 'rocket',
                    ],
                ],
            ],
            [
                'id' => 'interactive-qa',
                'name' => 'Interactive Q&A',
                'description' => 'Answer questions in issue comments when mentioned with @dispatch. Reads the codebase to provide context-aware answers.',
                'category' => 'triage',
                'event' => 'issue_comment.created',
                'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                'rule' => [
                    'id' => 'interactive-qa',
                    'event' => 'issue_comment.created',
                    'name' => 'Interactive Q&A',
                    'sort_order' => 2,
                    'prompt' => implode("\n", [
                        'You are responding to a question on issue #{{ event.issue.number }}.',
                        '',
                        'Issue title: {{ event.issue.title }}',
                        'Issue body:',
                        '{{ event.issue.body }}',
                        '',
                        'Comment by {{ event.comment.user.login }}:',
                        '{{ event.comment.body }}',
                        '',
                        'Respond helpfully. Read the codebase to answer questions accurately.',
                    ]),
                    'filters' => [
                        [
                            'id' => 'mentions-dispatch',
                            'field' => 'event.comment.body',
                            'operator' => 'contains',
                            'value' => '@dispatch',
                        ],
                        [
                            'id' => 'not-implement',
                            'field' => 'event.comment.body',
                            'operator' => 'not_contains',
                            'value' => '@dispatch implement',
                        ],
                    ],
                    'agent' => [
                        'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                    ],
                    'output' => [
                        'log' => true,
                        'github_comment' => true,
                    ],
                ],
            ],
            [
                'id' => 'ci-fix',
                'name' => 'CI Failure Fixer',
                'description' => 'When a check suite fails, analyze the failure and suggest or apply a fix. Works with any CI system that reports via GitHub checks.',
                'category' => 'ci-cd',
                'event' => 'check_suite.completed',
                'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                'rule' => [
                    'id' => 'ci-fix',
                    'event' => 'check_suite.completed',
                    'name' => 'CI Failure Fixer',
                    'prompt' => implode("\n", [
                        'A CI check suite has completed with conclusion: {{ event.check_suite.conclusion }}.',
                        '',
                        'Branch: {{ event.check_suite.head_branch }}',
                        'Commit: {{ event.check_suite.head_sha }}',
                        '',
                        'Investigate the failure:',
                        '1. Check the CI logs and test output',
                        '2. Identify the root cause',
                        '3. Suggest a fix with specific file and line references',
                    ]),
                    'filters' => [
                        [
                            'id' => 'only-failures',
                            'field' => 'event.check_suite.conclusion',
                            'operator' => 'equals',
                            'value' => 'failure',
                        ],
                    ],
                    'agent' => [
                        'tools' => ['Read', 'Glob', 'Grep', 'Bash'],
                    ],
                    'output' => [
                        'log' => true,
                        'github_comment' => true,
                    ],
                ],
            ],
            [
                'id' => 'release-notes',
                'name' => 'Release Notes Generator',
                'description' => 'Generate release notes when a new release is published. Analyzes commits since the last release and categorizes changes.',
                'category' => 'ci-cd',
                'event' => 'release.published',
                'tools' => ['Read', 'Bash', 'Glob', 'Grep'],
                'rule' => [
                    'id' => 'release-notes',
                    'event' => 'release.published',
                    'name' => 'Release Notes Generator',
                    'prompt' => implode("\n", [
                        'A new release has been published: {{ event.release.tag_name }}.',
                        '',
                        'Release name: {{ event.release.name }}',
                        '',
                        'Generate comprehensive release notes by:',
                        '1. Listing commits since the previous tag using `git log`',
                        '2. Categorizing changes (features, fixes, refactors, docs)',
                        '3. Highlighting breaking changes',
                        '4. Writing user-facing descriptions for each notable change',
                    ]),
                    'agent' => [
                        'tools' => ['Read', 'Bash', 'Glob', 'Grep'],
                    ],
                    'output' => [
                        'log' => true,
                        'github_comment' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get templates filtered by category.
     *
     * @return list<array{id: string, name: string, description: string, category: string, event: string, tools: list<string>, rule: array<string, mixed>}>
     */
    public function byCategory(string $category): array
    {
        if ($category === 'all') {
            return $this->all();
        }

        return array_values(array_filter(
            $this->all(),
            fn (array $template) => $template['category'] === $category,
        ));
    }

    /**
     * Find a template by ID.
     *
     * @return array{id: string, name: string, description: string, category: string, event: string, tools: list<string>, rule: array<string, mixed>}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Get available categories.
     *
     * @return list<array{id: string, label: string}>
     */
    public function categories(): array
    {
        return [
            ['id' => 'all', 'label' => 'All'],
            ['id' => 'triage', 'label' => 'Triage'],
            ['id' => 'review', 'label' => 'Review'],
            ['id' => 'implementation', 'label' => 'Implementation'],
            ['id' => 'ci-cd', 'label' => 'CI/CD'],
        ];
    }
}
