<?php

namespace App\Executors;

use App\Contracts\Executor;
use App\DataTransferObjects\ExecutionResult;
use App\Models\AgentRun;
use App\Services\ConversationMemory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

class ClaudeCliExecutor implements Executor
{
    public function execute(AgentRun $run, string $renderedPrompt, array $agentConfig, array $conversationHistory = []): ExecutionResult
    {
        $startTime = hrtime(true);

        try {
            $promptWithHistory = $this->prependConversationHistory($renderedPrompt, $conversationHistory);
            $command = $this->buildCommand($promptWithHistory, $agentConfig);
            $workingDirectory = $agentConfig['project_path'] ?? getcwd();

            $result = Process::path($workingDirectory)
                ->timeout(600)
                ->run($command);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $output = trim($result->output());

            if ($result->successful()) {
                return new ExecutionResult(
                    status: 'success',
                    output: $output,
                    durationMs: $durationMs,
                );
            }

            return new ExecutionResult(
                status: 'failed',
                output: $output,
                error: trim($result->errorOutput()) ?: 'Claude CLI exited with code '.$result->exitCode(),
                durationMs: $durationMs,
            );
        } catch (Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

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
        if ($systemPrompt !== null) {
            $command[] = '--system-prompt';
            $command[] = $systemPrompt;
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
        }

        return null;
    }
}
