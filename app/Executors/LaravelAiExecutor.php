<?php

namespace App\Executors;

use App\Ai\Agents\DispatchAgent;
use App\Contracts\Executor;
use App\DataTransferObjects\ExecutionResult;
use App\Models\AgentRun;
use Illuminate\Support\Facades\File;
use Throwable;

class LaravelAiExecutor implements Executor
{
    public function execute(AgentRun $run, string $renderedPrompt, array $agentConfig): ExecutionResult
    {
        $startTime = hrtime(true);

        try {
            $systemPrompt = $this->loadSystemPrompt($agentConfig);

            $agent = new DispatchAgent(
                systemPrompt: $systemPrompt,
            );

            $provider = $agentConfig['provider'] ?? null;
            $model = $agentConfig['model'] ?? null;

            $response = $agent->prompt(
                prompt: $renderedPrompt,
                provider: $provider,
                model: $model,
            );

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $tokensUsed = $response->usage->promptTokens + $response->usage->completionTokens;

            return new ExecutionResult(
                status: 'success',
                output: $response->text,
                tokensUsed: $tokensUsed,
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
     * Load the system prompt from the instructions file, or use a default.
     *
     * @param  array<string, mixed>  $agentConfig
     */
    protected function loadSystemPrompt(array $agentConfig): string
    {
        $instructionsFile = $agentConfig['instructions_file'] ?? null;
        $projectPath = $agentConfig['project_path'] ?? null;

        if ($instructionsFile && $projectPath) {
            $fullPath = rtrim($projectPath, '/').'/'.$instructionsFile;

            if (File::exists($fullPath)) {
                return File::get($fullPath);
            }
        }

        return 'You are a helpful AI assistant.';
    }
}
