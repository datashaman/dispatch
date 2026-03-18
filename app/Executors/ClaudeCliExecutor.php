<?php

namespace App\Executors;

use App\Contracts\Executor;
use App\DataTransferObjects\ExecutionResult;
use App\Models\AgentRun;
use App\Services\ConversationMemory;
use App\Services\StructuralMapper;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class ClaudeCliExecutor implements Executor
{
    public function __construct(
        protected StructuralMapper $structuralMapper,
    ) {}

    public function execute(AgentRun $run, string $renderedPrompt, array $agentConfig, array $conversationHistory = []): ExecutionResult
    {
        $startTime = hrtime(true);

        try {
            $promptWithHistory = $this->prependConversationHistory($renderedPrompt, $conversationHistory);
            $command = $this->buildCommand($promptWithHistory, $agentConfig);
            $workingDirectory = $agentConfig['project_path'] ?? getcwd();

            Log::info('ClaudeCliExecutor: starting execution', [
                'agent_run_id' => $run->id,
                'working_directory' => $workingDirectory,
                'command_args' => $this->redactCommand($command),
                'prompt_length' => strlen($renderedPrompt),
                'history_entries' => count($conversationHistory),
                'model' => $agentConfig['model'] ?? 'default',
                'tools' => $agentConfig['tools'] ?? [],
                'disallowed_tools' => $agentConfig['disallowed_tools'] ?? [],
            ]);

            Log::info('ClaudeCliExecutor: running command', [
                'agent_run_id' => $run->id,
                'full_command' => implode(' ', array_map('escapeshellarg', $command)),
            ]);

            $result = Process::path($workingDirectory)
                ->timeout(600)
                ->run($command);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $output = trim($result->output());
            $stderr = trim($result->errorOutput());

            Log::info('ClaudeCliExecutor: command completed', [
                'agent_run_id' => $run->id,
                'exit_code' => $result->exitCode(),
                'successful' => $result->successful(),
                'duration_ms' => $durationMs,
                'output_length' => strlen($output),
                'stderr_length' => strlen($stderr),
                'output_preview' => substr($output, 0, 500),
            ]);

            if ($stderr) {
                Log::warning('ClaudeCliExecutor: stderr output', [
                    'agent_run_id' => $run->id,
                    'stderr' => substr($stderr, 0, 1000),
                ]);
            }

            if ($result->successful()) {
                return new ExecutionResult(
                    status: 'success',
                    output: $output,
                    durationMs: $durationMs,
                );
            }

            Log::error('ClaudeCliExecutor: command failed', [
                'agent_run_id' => $run->id,
                'exit_code' => $result->exitCode(),
                'error' => $stderr ?: 'No stderr output',
                'output' => substr($output, 0, 1000),
            ]);

            return new ExecutionResult(
                status: 'failed',
                output: $output,
                error: $stderr ?: 'Claude CLI exited with code '.$result->exitCode(),
                durationMs: $durationMs,
            );
        } catch (Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            Log::error('ClaudeCliExecutor: exception during execution', [
                'agent_run_id' => $run->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration_ms' => $durationMs,
            ]);

            return new ExecutionResult(
                status: 'failed',
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }
    }

    /**
     * Build the claude CLI command with all flags.
     *
     * @param  array<string, mixed>  $agentConfig
     * @return list<string>
     */
    protected function buildCommand(string $renderedPrompt, array $agentConfig): array
    {
        $command = ['claude', '--print', '--output-format', 'text'];

        $systemPrompt = $this->loadSystemPrompt($agentConfig);
        $structuralMap = $this->buildStructuralMap($agentConfig);
        if ($structuralMap !== '') {
            $systemPrompt = ($systemPrompt ?? '').$structuralMap;
        }
        if ($systemPrompt !== null) {
            $command[] = '--system-prompt';
            $command[] = $systemPrompt;

            Log::debug('ClaudeCliExecutor: system prompt loaded', [
                'length' => strlen($systemPrompt),
                'source' => $agentConfig['instructions_file'] ?? 'inline',
            ]);
        }

        $model = $agentConfig['model'] ?? null;
        if ($model) {
            $command[] = '--model';
            $command[] = $model;
        }

        $maxTokens = $agentConfig['max_tokens'] ?? null;
        if ($maxTokens) {
            $command[] = '--max-turns';
            $command[] = (string) $maxTokens;
        }

        $allowedTools = $agentConfig['tools'] ?? [];
        foreach ($allowedTools as $tool) {
            $command[] = '--allowedTools';
            $command[] = $tool;
        }

        $disallowedTools = $agentConfig['disallowed_tools'] ?? [];
        foreach ($disallowedTools as $tool) {
            $command[] = '--disallowedTools';
            $command[] = $tool;
        }

        $command[] = '--prompt';
        $command[] = $renderedPrompt;

        return $command;
    }

    /**
     * Prepend conversation history to the rendered prompt.
     *
     * @param  list<array{role: string, content: string}>  $conversationHistory
     */
    protected function prependConversationHistory(string $renderedPrompt, array $conversationHistory): string
    {
        if (empty($conversationHistory)) {
            return $renderedPrompt;
        }

        $historyText = app(ConversationMemory::class)->formatAsText($conversationHistory);

        return $historyText."## Current Request\n\n".$renderedPrompt;
    }

    /**
     * Load the system prompt from the instructions file if available.
     *
     * @param  array<string, mixed>  $agentConfig
     */
    protected function loadSystemPrompt(array $agentConfig): ?string
    {
        $instructionsFile = $agentConfig['instructions_file'] ?? null;
        $projectPath = $agentConfig['project_path'] ?? null;

        if ($instructionsFile && $projectPath) {
            $fullPath = rtrim($projectPath, '/').'/'.$instructionsFile;

            if (File::exists($fullPath)) {
                return File::get($fullPath);
            }

            Log::warning('ClaudeCliExecutor: instructions file not found', [
                'path' => $fullPath,
            ]);
        }

        return null;
    }

    /**
     * Build a structural map of the project to include in the system prompt.
     *
     * @param  array<string, mixed>  $agentConfig
     */
    protected function buildStructuralMap(array $agentConfig): string
    {
        $projectPath = $agentConfig['project_path'] ?? null;

        if (! $projectPath) {
            return '';
        }

        $map = $this->structuralMapper->generate($projectPath);

        if ($map === null) {
            return '';
        }

        return "\n\n## Codebase Structure\n\nThis is a structural map of the project — classes, methods, and signatures ranked by importance:\n\n```\n{$map}\n```";
    }

    /**
     * Redact the prompt from the command for logging (it can be very long).
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    protected function redactCommand(array $command): array
    {
        $redacted = [];
        $skipNext = false;

        foreach ($command as $arg) {
            if ($skipNext) {
                $redacted[] = '[REDACTED '.strlen($arg).' chars]';
                $skipNext = false;

                continue;
            }

            if ($arg === '--prompt' || $arg === '--system-prompt') {
                $redacted[] = $arg;
                $skipNext = true;

                continue;
            }

            $redacted[] = $arg;
        }

        return $redacted;
    }
}
