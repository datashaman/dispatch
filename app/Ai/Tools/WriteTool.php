<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class WriteTool implements Tool
{
    public function __construct(
        protected string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Create or overwrite a file with the given content.';
    }

    public function handle(Request $request): Stringable|string
    {
        $path = $this->resolvePath($request->string('path'));

        if ($path === null) {
            return "Error: Path is outside the working directory: {$request->string('path')}";
        }

        $dir = dirname($path);

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($path, $request->string('content'));

        return "Successfully wrote to {$request->string('path')}";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The relative path to the file to write.')
                ->required(),
            'content' => $schema->string()
                ->description('The content to write to the file.')
                ->required(),
        ];
    }

    protected function resolvePath(string $relativePath): ?string
    {
        $baseDir = rtrim($this->workingDirectory, '/').'/';
        $full = $baseDir.ltrim($relativePath, '/');

        // Normalize the path to remove ../ segments
        $normalized = $this->normalizePath($full);
        $normalizedBase = rtrim($this->normalizePath($baseDir), '/').'/';

        if (! str_starts_with($normalized, $normalizedBase)) {
            return null;
        }

        return $normalized;
    }

    protected function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }
}
