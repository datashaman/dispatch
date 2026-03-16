<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class EditTool implements Tool
{
    public function __construct(
        protected string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Apply a targeted string replacement to a file. Replaces the first occurrence of old_string with new_string.';
    }

    public function handle(Request $request): Stringable|string
    {
        $path = $this->resolvePath($request->string('path'));

        if ($path === null) {
            return "Error: Path is outside the working directory: {$request->string('path')}";
        }

        if (! File::exists($path)) {
            return "Error: File not found: {$request->string('path')}";
        }

        $oldString = $request->string('old_string');
        $newString = $request->string('new_string');

        $content = File::get($path);

        if (! str_contains($content, $oldString)) {
            return "Error: old_string not found in file: {$request->string('path')}";
        }

        $pos = strpos($content, $oldString);
        $updated = substr_replace($content, $newString, $pos, strlen($oldString));

        File::put($path, $updated);

        return "Successfully edited {$request->string('path')}";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The relative path to the file to edit.')
                ->required(),
            'old_string' => $schema->string()
                ->description('The exact string to find and replace.')
                ->required(),
            'new_string' => $schema->string()
                ->description('The replacement string.')
                ->required(),
        ];
    }

    protected function resolvePath(string $relativePath): ?string
    {
        $full = rtrim($this->workingDirectory, '/').'/'.ltrim($relativePath, '/');
        $resolved = realpath($full);

        if ($resolved !== false) {
            $baseDir = rtrim(realpath($this->workingDirectory) ?: $this->workingDirectory, '/').'/';

            return str_starts_with($resolved, $baseDir) ? $resolved : null;
        }

        // File doesn't exist yet — compare using raw working directory
        $baseDir = rtrim($this->workingDirectory, '/').'/';

        return str_starts_with($full, $baseDir) ? $full : null;
    }
}
