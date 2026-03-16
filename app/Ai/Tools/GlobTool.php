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
    protected const array EXCLUDED_DIRS = ['node_modules', 'vendor', '.git', '.worktrees', 'storage'];

    public function __construct(
        protected string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Find files matching a glob pattern in the project directory. Supports patterns like "*.php", "app/**/*.php", "app/Models/*.php". Excludes vendor, node_modules, .git, storage.';
    }

    public function handle(Request $request): Stringable|string
    {
        $pattern = $request->string('pattern');

        $baseDir = realpath($this->workingDirectory) ?: $this->workingDirectory;

        if (! File::isDirectory($baseDir)) {
            return "Error: Working directory does not exist: {$baseDir}";
        }

        // If pattern contains a directory component (e.g. "app/**/*.php"),
        // split into directory prefix and filename pattern
        $searchDir = $baseDir;
        $namePattern = $pattern;
        $pathPattern = null;

        if (str_contains($pattern, '/')) {
            $parts = explode('/', $pattern);
            $namePart = array_pop($parts);
            $dirPart = implode('/', $parts);

            // Handle ** (recursive) patterns
            if (str_contains($dirPart, '**')) {
                $pathPattern = str_replace('**', '.*', preg_quote($dirPart, '#'));
                $namePattern = $namePart;
            } else {
                // Direct directory prefix — narrow the search dir
                $candidateDir = rtrim($baseDir, '/').'/'.$dirPart;
                if (File::isDirectory($candidateDir)) {
                    $searchDir = $candidateDir;
                    $namePattern = $namePart;
                }
            }
        }

        $finder = new Finder;
        $finder->files()
            ->in($searchDir)
            ->exclude(self::EXCLUDED_DIRS)
            ->name($namePattern)
            ->sortByName();

        if ($pathPattern) {
            $finder->path($pathPattern);
        }

        $files = [];
        foreach ($finder as $file) {
            // Show paths relative to the original base dir
            if ($searchDir !== $baseDir) {
                $relative = str_replace($baseDir.'/', '', $file->getPathname());
                $files[] = $relative;
            } else {
                $files[] = $file->getRelativePathname();
            }
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
                ->description('The glob pattern to match files against (e.g., "*.php", "app/**/*.php", "app/Models/*.php").')
                ->required(),
        ];
    }
}
