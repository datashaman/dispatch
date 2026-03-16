<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GrepTool implements Tool
{
    public function __construct(
        protected string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Search file contents for a pattern using grep. Returns matching lines with file paths and line numbers.';
    }

    public function handle(Request $request): Stringable|string
    {
        $pattern = $request->string('pattern');

        $args = ['grep', '-rn', '--include='.$request->string('include', '*'), $pattern, '.'];

        $result = Process::path($this->workingDirectory)
            ->timeout(30)
            ->run($args);

        $output = $result->output();

        if (empty(trim($output))) {
            return "No matches found for pattern: {$pattern}";
        }

        return $output;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pattern' => $schema->string()
                ->description('The search pattern (regex supported).')
                ->required(),
            'include' => $schema->string()
                ->description('File glob to filter search (e.g., "*.php"). Defaults to all files.'),
        ];
    }
}
