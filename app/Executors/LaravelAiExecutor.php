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
            $systemPrompt = $this->loadSystemPrompt($agentConfig).$this->buildOutputInstructions($agentConfig);
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

            $steps = $response->steps->map(fn ($step) => $step->toArray())->toArray();

            return new ExecutionResult(
                status: 'success',
                output: $response->text,
                steps: $steps ?: null,
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
     * Build output routing instructions to append to the system prompt.
     *
     * @param  array<string, mixed>  $agentConfig
     */
    protected function buildOutputInstructions(array $agentConfig): string
    {
        $lines = [];

        if ($agentConfig['output_github_comment'] ?? false) {
            $lines[] = 'Your final text response will be posted as a GitHub comment automatically.';
            $lines[] = 'Do NOT use `gh issue comment`, `gh pr comment`, or `gh api` to post comments — just write your response directly.';
            $lines[] = 'Do not narrate what you did — output only the deliverable itself.';
        }

        if ($agentConfig['output_github_reaction'] ?? null) {
            $lines[] = 'A "'.$agentConfig['output_github_reaction'].'" reaction will be added automatically. Do not add reactions yourself.';
        }

        return $lines ? "\n\n## Output Routing\n\n".implode("\n", $lines) : '';
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
