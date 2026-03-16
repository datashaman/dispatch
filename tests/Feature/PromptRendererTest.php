<?php

use App\Services\PromptRenderer;

beforeEach(function () {
    $this->renderer = new PromptRenderer;
});

test('renders simple field path', function () {
    $template = 'Issue #{{ event.issue.number }} was opened.';
    $payload = ['issue' => ['number' => 42]];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('Issue #42 was opened.');
});

test('renders multiple placeholders', function () {
    $template = '{{ event.issue.title }} by {{ event.issue.user.login }}';
    $payload = [
        'issue' => [
            'title' => 'Fix bug',
            'user' => ['login' => 'octocat'],
        ],
    ];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('Fix bug by octocat');
});

test('renders nested paths', function () {
    $template = 'User {{ event.issue.user.login }} on repo {{ event.repository.full_name }}';
    $payload = [
        'issue' => ['user' => ['login' => 'octocat']],
        'repository' => ['full_name' => 'owner/repo'],
    ];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('User octocat on repo owner/repo');
});

test('unresolved paths render as empty string', function () {
    $template = 'Value: {{ event.nonexistent.path }}';
    $payload = ['issue' => ['number' => 42]];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('Value: ');
});

test('partially unresolved nested path renders as empty string', function () {
    $template = '{{ event.issue.labels.0.name }}';
    $payload = ['issue' => ['number' => 42]];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('');
});

test('renders with extra whitespace in template tags', function () {
    $template = '{{  event.issue.number  }}';
    $payload = ['issue' => ['number' => 99]];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('99');
});

test('template without placeholders is returned unchanged', function () {
    $template = 'No placeholders here.';
    $payload = ['issue' => ['number' => 42]];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('No placeholders here.');
});

test('renders array index access', function () {
    $template = 'First label: {{ event.issue.labels.0.name }}';
    $payload = [
        'issue' => [
            'labels' => [
                ['name' => 'bug', 'color' => 'red'],
                ['name' => 'urgent', 'color' => 'orange'],
            ],
        ],
    ];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('First label: bug');
});

test('renders deeply nested paths', function () {
    $template = '{{ event.pull_request.head.repo.owner.login }}';
    $payload = [
        'pull_request' => [
            'head' => [
                'repo' => [
                    'owner' => ['login' => 'contributor'],
                ],
            ],
        ],
    ];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('contributor');
});

test('numeric values are converted to string', function () {
    $template = 'PR #{{ event.number }} has {{ event.additions }} additions';
    $payload = ['number' => 123, 'additions' => 456];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('PR #123 has 456 additions');
});

test('boolean values are converted to string', function () {
    $template = 'Draft: {{ event.pull_request.draft }}';
    $payload = ['pull_request' => ['draft' => true]];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('Draft: 1');
});

test('null values render as empty string', function () {
    $template = 'Body: {{ event.issue.body }}';
    $payload = ['issue' => ['body' => null]];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe('Body: ');
});

test('multiline prompt template is rendered correctly', function () {
    $template = "Analyze issue #{{ event.issue.number }}.\nTitle: {{ event.issue.title }}\nBody: {{ event.issue.body }}";
    $payload = [
        'issue' => [
            'number' => 42,
            'title' => 'Something broke',
            'body' => 'Details here',
        ],
    ];

    $result = $this->renderer->render($template, $payload);

    expect($result)->toBe("Analyze issue #42.\nTitle: Something broke\nBody: Details here");
});

test('empty template returns empty string', function () {
    $result = $this->renderer->render('', ['issue' => ['number' => 1]]);

    expect($result)->toBe('');
});

test('empty payload resolves all placeholders to empty string', function () {
    $template = '{{ event.issue.number }} - {{ event.action }}';

    $result = $this->renderer->render($template, []);

    expect($result)->toBe(' - ');
});
