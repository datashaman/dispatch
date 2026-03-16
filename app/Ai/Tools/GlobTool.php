<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Symfony\Component\Finder\Finder;

class GlobTool implements Tool
{
    public function __construct(
        protected string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Find files matching a glob pattern in the project directory.';
    }

    public function handle(Request $request): Stringable|string
    {
        $pattern = $request->string('pattern');

        $baseDir = realpath($this->workingDirectory) ?: $this->workingDirectory;

        if (! File::isDirectory($baseDir)) {
            return "Error: Working directory does not exist: {$baseDir}";
        }

        $finder = new Finder;
        $finder->files()
            ->in($baseDir)
            ->name($pattern)
            ->sortByName();

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRelativePathname();
        }

        if (empty($files)) {
            return "No files found matching pattern: {$pattern}";
        }

        return implode("\n", $files);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pattern' => $schema->string()
                ->description('The glob pattern to match files against (e.g., "*.php", "*.js").')
                ->required(),
        ];
    }
}
