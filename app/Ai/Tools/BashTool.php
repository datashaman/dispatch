<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class BashTool implements Tool
{
    public function __construct(
        protected string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Run a shell command in the project directory and return its output.';
    }

    public function handle(Request $request): Stringable|string
    {
        $command = $request->string('command');

        $result = Process::path($this->workingDirectory)
            ->timeout(120)
            ->run($command);

        $output = $result->output();
        $errorOutput = $result->errorOutput();

        if ($result->successful()) {
            return $output ?: 'Command completed successfully with no output.';
        }

        return "Exit code: {$result->exitCode()}\n"
            .($output ? "stdout:\n{$output}\n" : '')
            .($errorOutput ? "stderr:\n{$errorOutput}" : '');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()
                ->description('The shell command to execute.')
                ->required(),
        ];
    }
}
