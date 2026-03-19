<?php

namespace App\Executors;

use App\Ai\Agents\DispatchAgent;
use App\Contracts\Executor;
use App\DataTransferObjects\ExecutionResult;
use App\Models\AgentRun;
use App\Services\ToolRegistry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
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

            Log::info('LaravelAiExecutor: calling Anthropic API (streaming)', [
                'agent_run_id' => $run->id,
            ]);

            $channel = new PrivateChannel('agent-run.'.$run->id);

            // stream() + each() iterates synchronously — events are broadcast
            // inline, and the response is fully consumed before we continue.
            $streamable = $agent->stream(
                prompt: $renderedPrompt,
                provider: $provider,
                model: $model,
            )->each(function (StreamEvent $event) use ($channel) {
                $event->broadcastNow([$channel]);
            });

            // At this point the stream has been fully consumed.
            $text = $streamable->text;
            $usage = $streamable->usage;

            Log::info('LaravelAiExecutor: stream completed', [
                'agent_run_id' => $run->id,
                'prompt_tokens' => $usage->promptTokens ?? null,
                'completion_tokens' => $usage->completionTokens ?? null,
                'output_length' => strlen($text ?? ''),
            ]);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $tokensUsed = ($usage->promptTokens ?? 0) + ($usage->completionTokens ?? 0);

            // Extract tool calls and results from stream events for step history
            $steps = $this->extractStepsFromEvents($streamable->events);

            return new ExecutionResult(
                status: 'success',
                output: $text,
                steps: $steps ?: null,
                tokensUsed: $tokensUsed,
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
     * Extract step data from stream events for storage.
     *
     * @return list<array<string, mixed>>
     */
    protected function extractStepsFromEvents(Collection $events): array
    {
        $steps = [];
        $currentStep = ['tool_calls' => [], 'tool_results' => []];

        foreach ($events as $event) {
            if ($event instanceof ToolCall) {
                $currentStep['tool_calls'][] = [
                    'name' => $event->toolCall->name ?? 'unknown',
                    'arguments' => $event->toolCall->arguments ?? [],
                ];
            } elseif ($event instanceof ToolResult) {
                $currentStep['tool_results'][] = [
                    'name' => $event->toolResult->name ?? 'unknown',
                    'result' => $event->toolResult->result ?? '',
                ];

                // Each tool result completes a step
                $steps[] = $currentStep;
                $currentStep = ['tool_calls' => [], 'tool_results' => []];
            }
        }

        return $steps;
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
