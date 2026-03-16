<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported AI Providers & Models
    |--------------------------------------------------------------------------
    |
    | The providers and models available for agent configuration. Models are
    | grouped by provider. The first model in each list is the default.
    |
    */

    'providers' => [
        'anthropic' => [
            'label' => 'Anthropic',
            'models' => [
                'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
                'claude-opus-4-6' => 'Claude Opus 4.6',
                'claude-haiku-4-5' => 'Claude Haiku 4.5',
            ],
        ],
        'openai' => [
            'label' => 'OpenAI',
            'models' => [
                'gpt-4.1' => 'GPT-4.1',
                'gpt-4.1-mini' => 'GPT-4.1 Mini',
                'gpt-4.1-nano' => 'GPT-4.1 Nano',
                'o3' => 'o3',
                'o4-mini' => 'o4 Mini',
            ],
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'models' => [
                'gemini-2.5-pro' => 'Gemini 2.5 Pro',
                'gemini-2.5-flash' => 'Gemini 2.5 Flash',
                'gemini-2.0-flash' => 'Gemini 2.0 Flash',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub Webhook Events
    |--------------------------------------------------------------------------
    |
    | Supported GitHub webhook events with human-readable labels, actions,
    | and the template variables available in each event's payload.
    |
    */

    'events' => [
        'issues.opened' => [
            'label' => 'an issue is opened',
            'variables' => ['issue.number', 'issue.title', 'issue.body', 'issue.user.login', 'issue.html_url', 'issue.state'],
        ],
        'issues.labeled' => [
            'label' => 'an issue is labeled',
            'variables' => ['issue.number', 'issue.title', 'issue.body', 'issue.user.login', 'issue.html_url', 'label.name', 'label.color'],
        ],
        'issues.closed' => [
            'label' => 'an issue is closed',
            'variables' => ['issue.number', 'issue.title', 'issue.body', 'issue.user.login', 'issue.html_url'],
        ],
        'issues.reopened' => [
            'label' => 'an issue is reopened',
            'variables' => ['issue.number', 'issue.title', 'issue.body', 'issue.user.login', 'issue.html_url'],
        ],
        'issues.assigned' => [
            'label' => 'an issue is assigned',
            'variables' => ['issue.number', 'issue.title', 'issue.body', 'issue.user.login', 'assignee.login'],
        ],
        'issue_comment.created' => [
            'label' => 'a comment is posted on an issue',
            'variables' => ['issue.number', 'issue.title', 'issue.body', 'comment.body', 'comment.user.login', 'comment.html_url'],
        ],
        'pull_request.opened' => [
            'label' => 'a pull request is opened',
            'variables' => ['pull_request.number', 'pull_request.title', 'pull_request.body', 'pull_request.user.login', 'pull_request.html_url', 'pull_request.head.ref', 'pull_request.base.ref'],
        ],
        'pull_request.closed' => [
            'label' => 'a pull request is closed',
            'variables' => ['pull_request.number', 'pull_request.title', 'pull_request.body', 'pull_request.user.login', 'pull_request.merged'],
        ],
        'pull_request.synchronize' => [
            'label' => 'a pull request is updated (new commits)',
            'variables' => ['pull_request.number', 'pull_request.title', 'pull_request.user.login', 'pull_request.head.ref', 'pull_request.head.sha'],
        ],
        'pull_request.ready_for_review' => [
            'label' => 'a pull request is marked ready for review',
            'variables' => ['pull_request.number', 'pull_request.title', 'pull_request.body', 'pull_request.user.login', 'pull_request.html_url'],
        ],
        'pull_request.review_requested' => [
            'label' => 'a review is requested on a pull request',
            'variables' => ['pull_request.number', 'pull_request.title', 'pull_request.user.login', 'requested_reviewer.login'],
        ],
        'pull_request_review.submitted' => [
            'label' => 'a pull request review is submitted',
            'variables' => ['pull_request.number', 'pull_request.title', 'review.body', 'review.state', 'review.user.login', 'review.html_url'],
        ],
        'pull_request_review_comment.created' => [
            'label' => 'a PR review comment is posted',
            'variables' => ['pull_request.number', 'pull_request.title', 'comment.body', 'comment.user.login', 'comment.path', 'comment.diff_hunk', 'comment.html_url'],
        ],
        'discussion.created' => [
            'label' => 'a discussion is created',
            'variables' => ['discussion.number', 'discussion.title', 'discussion.body', 'discussion.user.login', 'discussion.html_url', 'discussion.category.name'],
        ],
        'discussion_comment.created' => [
            'label' => 'a discussion comment is posted',
            'variables' => ['discussion.number', 'discussion.title', 'comment.body', 'comment.user.login', 'comment.html_url'],
        ],
        'push' => [
            'label' => 'code is pushed',
            'variables' => ['ref', 'before', 'after', 'compare', 'pusher.name', 'head_commit.message', 'head_commit.id', 'head_commit.author.name'],
        ],
        'release.published' => [
            'label' => 'a release is published',
            'variables' => ['release.tag_name', 'release.name', 'release.body', 'release.html_url', 'release.author.login', 'release.prerelease'],
        ],
        'create' => [
            'label' => 'a branch or tag is created',
            'variables' => ['ref', 'ref_type', 'master_branch'],
        ],
        'delete' => [
            'label' => 'a branch or tag is deleted',
            'variables' => ['ref', 'ref_type'],
        ],
        'workflow_run.completed' => [
            'label' => 'a workflow run completes',
            'variables' => ['workflow_run.name', 'workflow_run.conclusion', 'workflow_run.html_url', 'workflow_run.head_branch', 'workflow_run.head_sha'],
        ],
    ],

];
