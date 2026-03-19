<?php

namespace App\Executors;

use App\Ai\Agents\DispatchAgent;
use App\Contracts\Executor;
use App\DataTransferObjects\ExecutionResult;
use App\Models\AgentRun;
use App\Services\CostCalculator;
use App\Services\ToolRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Throwable;

class LaravelAiExecutor implements Executor
{
    public function __construct(
        protected ToolRegistry $toolRegistry,
        protected CostCalculator $costCalculator,
    ) {}

    public function execute(AgentRun $run, string $renderedPrompt, array $agentConfig, array $conversationHistory = []): ExecutionResult
    {
        $startTime = hrtime(true);

        try {
            $systemPrompt = $this->loadSystemPrompt($agentConfig).$this->buildOutputInstructions($agentConfig);
            $tools = $this->resolveTools($agentConfig);

            $provider = $agentConfig['provider'] ?? null;
            $model = $agentConfig['model'] ?? null;

            Log::info('LaravelAiExecutor: starting execution', [
                'agent_run_id' => $run->id,
                'provider' => $provider,
                'model' => $model,
                'system_prompt_length' => strlen($systemPrompt),
                'rendered_prompt_length' => strlen($renderedPrompt),
                'tools' => array_map(fn (Tool $t) => $t::class, $tools),
                'tool_count' => count($tools),
                'conversation_history_entries' => count($conversationHistory),
                'project_path' => $agentConfig['project_path'] ?? null,
            ]);

            $agent = new DispatchAgent(
                systemPrompt: $systemPrompt,
                agentTools: $tools,
            );

            if (! empty($conversationHistory)) {
                $agent->withConversationHistory($conversationHistory);
            }

            Log::info('LaravelAiExecutor: calling Anthropic API', [
                'agent_run_id' => $run->id,
            ]);

            $response = $agent->prompt(
                prompt: $renderedPrompt,
                provider: $provider,
                model: $model,
            );

            Log::info('LaravelAiExecutor: API response received', [
                'agent_run_id' => $run->id,
                'prompt_tokens' => $response->usage->promptTokens ?? null,
                'completion_tokens' => $response->usage->completionTokens ?? null,
                'steps_count' => $response->steps->count(),
                'output_length' => strlen($response->text ?? ''),
            ]);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $promptTokens = $response->usage->promptTokens ?? 0;
            $completionTokens = $response->usage->completionTokens ?? 0;
            $tokensUsed = $promptTokens + $completionTokens;

            $cost = $this->costCalculator->calculate($promptTokens, $completionTokens, $model);

            $steps = $response->steps->map(fn ($step) => $step->toArray())->toArray();

            return new ExecutionResult(
                status: 'success',
                output: $response->text,
                steps: $steps ?: null,
                tokensUsed: $tokensUsed,
                cost: $cost,
                durationMs: $durationMs,
            );
        } catch (Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            Log::error('LaravelAiExecutor: execution failed', [
                'agent_run_id' => $run->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
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

        $lines[] = 'You have a limited number of tool-use steps. Do NOT exhaustively read every file — be strategic.';
        $lines[] = 'Gather only what you need, then write your final response. Your text response is the deliverable.';

        if ($agentConfig['output_github_comment'] ?? false) {
            $lines[] = 'Your final text response will be posted as a GitHub comment automatically.';
            $lines[] = 'Do NOT use `gh issue comment`, `gh pr comment`, or `gh api` to post comments — just write your response directly.';
            $lines[] = 'Do not narrate what you did — output only the deliverable itself.';
        }

        if ($agentConfig['output_github_reaction'] ?? null) {
            $lines[] = 'A "'.$agentConfig['output_github_reaction'].'" reaction will be added automatically. Do not add reactions yourself.';
        }

        return "\n\n## Output Routing\n\n".implode("\n", $lines);
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
