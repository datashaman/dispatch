<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReadTool implements Tool
{
    public function __construct(
        protected string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Read the contents of a file from the project directory.';
    }

    public function handle(Request $request): Stringable|string
    {
        $path = $this->resolvePath($request->string('path'));

        if (! File::exists($path)) {
            return "Error: File not found: {$request->string('path')}";
        }

        if (! File::isFile($path)) {
            return "Error: Not a file: {$request->string('path')}";
        }

        return File::get($path);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The relative path to the file to read.')
                ->required(),
        ];
    }

    protected function resolvePath(string $relativePath): string
    {
        $full = rtrim($this->workingDirectory, '/').'/'.ltrim($relativePath, '/');
        $resolved = realpath($full) ?: $full;

        if (! str_starts_with($resolved, realpath($this->workingDirectory) ?: $this->workingDirectory)) {
            return '__invalid__';
        }

        return $resolved;
    }
}
