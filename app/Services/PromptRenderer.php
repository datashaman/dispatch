<?php

namespace App\Services;

use Illuminate\Support\Arr;

class PromptRenderer
{
    /**
     * Render a prompt template by replacing {{ event.field.path }} placeholders
     * with values resolved from the webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function render(string $template, array $payload): string
    {
        return preg_replace_callback('/\{\{\s*event\.([^}]+?)\s*\}\}/', function (array $matches) use ($payload) {
            $path = trim($matches[1]);

            return (string) Arr::get($payload, $path, '');
        }, $template);
    }
}
