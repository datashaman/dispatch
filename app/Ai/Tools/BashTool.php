<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class BashTool implements Tool
{
    /**
     * Patterns that indicate dangerous or abusive commands.
     * Each pattern is matched case-insensitively against the full command string.
     *
     * @var list<string>
     */
    protected array $blockedPatterns = [
        '/\brm\s+(-[a-zA-Z]*r[a-zA-Z]*f|--recursive\b|--force\b).*\s+\/(?!\S)/', // rm -rf /
        '/\brm\s+(-[a-zA-Z]*r[a-zA-Z]*f|--recursive\b|--force\b).*\s+~/', // rm -rf ~
        '/\bmkfs\b/', // format filesystem
        '/\bdd\b.*\bof=\/dev\//', // dd to device
        '/\b:(){ :\|:& };:/', // fork bomb
        '/\bcurl\b.*\|\s*(bash|sh|zsh)\b/', // curl pipe to shell
        '/\bwget\b.*\|\s*(bash|sh|zsh)\b/', // wget pipe to shell
        '/\bchmod\s+(-[a-zA-Z]*R|--recursive).*\s+\/(?!\S)/', // chmod -R /
        '/\bchown\s+(-[a-zA-Z]*R|--recursive).*\s+\/(?!\S)/', // chown -R /
        '/>\s*\/dev\/[sh]d[a-z]/', // write to disk device
        '/\bshutdown\b/', // shutdown
        '/\breboot\b/', // reboot
        '/\binit\s+[06]\b/', // init 0 or init 6
    ];

    public function __construct(
        protected string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Run a shell command in the project directory and return its output. Destructive system-level commands are blocked for safety.';
    }

    public function handle(Request $request): Stringable|string
    {
        $command = $request->string('command');

        $blocked = $this->checkBlockedCommand($command);
        if ($blocked) {
            return "Command blocked: {$blocked}. This command could cause system damage and is not allowed.";
        }

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

    /**
     * Check if a command matches any blocked pattern.
     *
     * @return string|null Description of why it was blocked, or null if allowed
     */
    protected function checkBlockedCommand(string $command): ?string
    {
        foreach ($this->blockedPatterns as $pattern) {
            if (@preg_match($pattern, $command)) {
                return 'matches a blocked destructive pattern';
            }
        }

        return null;
    }
}
