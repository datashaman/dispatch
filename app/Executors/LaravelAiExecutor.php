<?php

namespace App\Executors;

use App\Ai\Agents\DispatchAgent;
use App\Contracts\Executor;
use App\DataTransferObjects\ExecutionResult;
use App\Models\AgentRun;
use App\Services\ToolRegistry;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use Throwable;

class LaravelAiExecutor implements Executor
{
    public function __construct(
        protected ToolRegistry $toolRegistry,
    ) {}

    public function execute(AgentRun $run, string $renderedPrompt, array $agentConfig, array $conversationHistory = []): ExecutionResult
    {
        $startTime = hrtime(true);

        try {
            $systemPrompt = $this->loadSystemPrompt($agentConfig);
            $tools = $this->resolveTools($agentConfig);

            $agent = new DispatchAgent(
                systemPrompt: $systemPrompt,
                agentTools: $tools,
            );

            if (! empty($conversationHistory)) {
                $agent->withConversationHistory($conversationHistory);
            }

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

    /**
     * Resolve tool instances from the agent config.
     *
     * @param  array<string, mixed>  $agentConfig
     * @return list<Tool>
     */
    protected function resolveTools(array $agentConfig): array
    {
        $workingDirectory = $agentConfig['project_path'] ?? '';

        return $this->toolRegistry->resolve(
            tools: $agentConfig['tools'] ?? [],
            workingDirectory: $workingDirectory,
        );
    }
}
