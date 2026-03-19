<?php

namespace App\Services;

use Illuminate\Support\Arr;

class PromptRenderer
{
    /**
     * Fields known to contain user-generated content that should be
     * wrapped in XML tags for structural separation.
     *
     * @var list<string>
     */
    protected array $untrustedFields = [
        'issue.body',
        'issue.title',
        'comment.body',
        'pull_request.body',
        'pull_request.title',
        'review.body',
        'discussion.body',
        'discussion.title',
    ];

    /**
     * Render a prompt template by replacing {{ event.field.path }} placeholders
     * with values resolved from the webhook payload.
     *
     * User-generated content fields are wrapped in XML tags to create
     * structural separation between trusted instructions and untrusted data.
     *
     * @param  array<string, mixed>  $payload
     */
    public function render(string $template, array $payload): string
    {
        return preg_replace_callback('/\{\{\s*event\.([^}]+?)\s*\}\}/', function (array $matches) use ($payload) {
            $path = trim($matches[1]);
            $value = (string) Arr::get($payload, $path, '');

            if ($this->isUntrustedField($path)) {
                return $this->wrapUntrusted($path, $value);
            }

            return $value;
        }, $template);
    }

    /**
     * Check if a field path refers to user-generated content.
     */
    protected function isUntrustedField(string $path): bool
    {
        foreach ($this->untrustedFields as $field) {
            if ($path === $field || str_ends_with($path, '.'.$field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wrap untrusted content in XML tags for structural separation.
     *
     * The value is XML-escaped to prevent content from breaking the
     * structural boundary (e.g., via `</user-content>` or `<`, `&`).
     */
    protected function wrapUntrusted(string $field, string $value): string
    {
        $tag = str_replace('.', '-', $field);
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');

        return "<user-content field=\"{$tag}\">\n{$escaped}\n</user-content>";
    }
}
