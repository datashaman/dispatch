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
        '/\bmkfs\b/i', // format filesystem
        '/\bdd\b.*\bof=\/dev\//i', // dd to device
        '/:\(\)\s*\{\s*:\s*\|\s*:\s*&\s*\}\s*;\s*:/i', // fork bomb :(){ :|:& };:
        '/\bcurl\b.*\|\s*(bash|sh|zsh)\b/i', // curl pipe to shell
        '/\bwget\b.*\|\s*(bash|sh|zsh)\b/i', // wget pipe to shell
        '/\bchmod\s+(-[a-zA-Z]*R|--recursive).*\s+\/(?!\S)/i', // chmod -R /
        '/\bchown\s+(-[a-zA-Z]*R|--recursive).*\s+\/(?!\S)/i', // chown -R /
        '/>\s*\/dev\/[sh]d[a-z]/i', // write to disk device
        '/\bshutdown\b/i', // shutdown
        '/\breboot\b/i', // reboot
        '/\binit\s+[06]\b/i', // init 0 or init 6
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
        if ($this->isDestructiveRm($command)) {
            return 'matches a blocked destructive pattern';
        }

        foreach ($this->blockedPatterns as $pattern) {
            if (@preg_match($pattern, $command)) {
                return 'matches a blocked destructive pattern';
            }
        }

        return null;
    }

    /**
     * Detect dangerous rm commands that combine recursive + force flags
     * targeting root (/) or home (~), regardless of flag order or grouping.
     *
     * Handles: rm -rf /, rm -fr /, rm -r -f /, rm --recursive --force /,
     * rm -rf /*, rm -rf ~, and variants with additional arguments or chaining.
     */
    protected function isDestructiveRm(string $command): bool
    {
        // Split on command separators to check each subcommand
        $subcommands = preg_split('/[;&|]+/', $command);

        foreach ($subcommands as $subcommand) {
            $subcommand = trim($subcommand);

            // Tokenize the subcommand
            $tokens = preg_split('/\s+/', $subcommand);

            if (empty($tokens) || ! preg_match('/\brm$/i', $tokens[0])) {
                continue;
            }

            $hasRecursive = false;
            $hasForce = false;
            $targets = [];

            for ($i = 1; $i < count($tokens); $i++) {
                $token = $tokens[$i];

                if ($token === '--recursive') {
                    $hasRecursive = true;
                } elseif ($token === '--force') {
                    $hasForce = true;
                } elseif ($token === '--') {
                    // Everything after -- is a target
                    $targets = array_merge($targets, array_slice($tokens, $i + 1));

                    break;
                } elseif (str_starts_with($token, '-') && ! str_starts_with($token, '--')) {
                    // Short flags like -rf, -r, -f, -fr, -rfi, etc.
                    $flags = substr($token, 1);
                    if (str_contains($flags, 'r') || str_contains($flags, 'R')) {
                        $hasRecursive = true;
                    }
                    if (str_contains($flags, 'f')) {
                        $hasForce = true;
                    }
                } else {
                    $targets[] = $token;
                }
            }

            if ($hasRecursive && $hasForce) {
                foreach ($targets as $target) {
                    // Matches /, /*, ~, or ~/*
                    if (preg_match('#^/\*?$#', $target) || preg_match('#^~(/\*?)?$#', $target)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
