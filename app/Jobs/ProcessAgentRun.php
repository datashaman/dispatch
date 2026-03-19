<?php

namespace App\Jobs;

use App\Contracts\Executor;
use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\OutputConfig;
use App\DataTransferObjects\RuleConfig;
use App\Events\AgentRunUpdated;
use App\Executors\ClaudeCliExecutor;
use App\Executors\LaravelAiExecutor;
use App\Models\AgentRun;
use App\Models\Project;
use App\Services\ConversationMemory;
use App\Services\OutputHandler;
use App\Services\PromptRenderer;
use App\Services\WorktreeManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessAgentRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $backoff = 0;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public AgentRun $agentRun,
        public RuleConfig $ruleConfig,
        public array $payload,
        public Project $project,
        public DispatchConfig $dispatchConfig,
    ) {
        $this->onQueue('agents');
        $this->configureRetry();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $attempt = $this->attempts();
        $this->agentRun->update([
            'status' => 'running',
            'attempt' => $attempt,
        ]);

        $this->broadcastUpdate();

        // Add reaction immediately so the user knows the agent is working
        $outputConfig = $this->ruleConfig->output ?? new OutputConfig;
        if ($outputConfig->githubReaction) {
            app(OutputHandler::class)->addReaction(
                $outputConfig->githubReaction,
                $this->payload,
            );
        }

        $executor = $this->resolveExecutor();
        $renderedPrompt = app(PromptRenderer::class)->render(
            $this->ruleConfig->prompt,
            $this->payload,
        );
        $agentConfig = $this->resolveAgentConfig();
        $conversationHistory = $this->loadConversationHistory();

        $worktree = null;

        if ($agentConfig['isolation']) {
            $worktree = $this->createWorktree($agentConfig);
            $agentConfig['project_path'] = $worktree['path'];
        }

        try {
            $result = $executor->execute($this->agentRun, $renderedPrompt, $agentConfig, $conversationHistory);

            $this->agentRun->update([
                'status' => $result->status,
                'output' => $result->output,
                'steps' => $result->steps,
                'tokens_used' => $result->tokensUsed,
                'cost' => $result->cost,
                'duration_ms' => $result->durationMs,
                'error' => $result->error,
            ]);

            $this->broadcastUpdate();

            if ($result->status === 'success') {
                app(OutputHandler::class)->handle(
                    $this->agentRun,
                    $outputConfig,
                    $this->payload,
                );
            } elseif ($result->status === 'failed' && $this->shouldRetry()) {
                throw new \RuntimeException($result->error ?? 'Agent execution failed');
            }
        } finally {
            if ($worktree) {
                $this->cleanupWorktree($worktree, $agentConfig);
            }
        }
    }

    /**
     * Handle a job failure (called only after all retries are exhausted).
     */
    public function failed(?\Throwable $exception): void
    {
        $this->agentRun->update([
            'status' => 'failed',
            'attempt' => $this->attempts(),
            'error' => $exception?->getMessage(),
        ]);

        $this->broadcastUpdate();
    }

    /**
     * Broadcast agent run status update, swallowing failures so
     * broadcasting issues don't affect agent execution.
     */
    protected function broadcastUpdate(): void
    {
        try {
            AgentRunUpdated::dispatch($this->agentRun);
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast AgentRunUpdated', [
                'agent_run_id' => $this->agentRun->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Configure retry behavior from the rule's retry config.
     */
    protected function configureRetry(): void
    {
        $retryConfig = $this->ruleConfig->retry;

        if ($retryConfig && $retryConfig->enabled) {
            $this->tries = $retryConfig->maxAttempts;
            $this->backoff = $retryConfig->delay;
        }
    }

    /**
     * Determine if the job should be retried (more attempts remaining).
     */
    protected function shouldRetry(): bool
    {
        return $this->tries > 1 && $this->attempts() < $this->tries;
    }

    /**
     * Load conversation history for the current thread.
     *
     * @return list<array{role: string, content: string}>
     */
    protected function loadConversationHistory(): array
    {
        $memory = app(ConversationMemory::class);
        $threadKey = $memory->deriveThreadKey($this->payload);

        if (! $threadKey) {
            return [];
        }

        return $memory->retrieveHistory($threadKey, $this->agentRun->id);
    }

    /**
     * Resolve the executor based on the dispatch config.
     */
    protected function resolveExecutor(): Executor
    {
        $executor = $this->dispatchConfig->agentExecutor;

        return match ($executor) {
            'claude-cli' => app(ClaudeCliExecutor::class),
            'laravel-ai' => app(LaravelAiExecutor::class),
            default => app(LaravelAiExecutor::class),
        };
    }

    /**
     * Resolve agent config by merging rule-level config with dispatch-level defaults.
     *
     * @return array<string, mixed>
     */
    protected function resolveAgentConfig(): array
    {
        $agent = $this->ruleConfig->agent;
        $output = $this->ruleConfig->output;

        return [
            'provider' => $agent?->provider ?? $this->dispatchConfig->agentProvider,
            'model' => $agent?->model ?? $this->dispatchConfig->agentModel,
            'max_tokens' => $agent?->maxTokens,
            'max_steps' => $agent?->maxSteps,
            'tools' => $agent?->tools ?? [],
            'disallowed_tools' => $agent?->disallowedTools ?? [],
            'isolation' => $agent?->isolation ?? false,
            'instructions_file' => $this->dispatchConfig->agentInstructionsFile,
            'project_path' => $this->project->path,
            'output_github_comment' => $output?->githubComment ?? false,
            'output_github_reaction' => $output?->githubReaction,
        ];
    }

    /**
     * Create a git worktree for isolated execution.
     *
     * @param  array<string, mixed>  $agentConfig
     * @return array{path: string, branch: string}
     */
    protected function createWorktree(array $agentConfig): array
    {
        $worktreeManager = app(WorktreeManager::class);
        $projectPath = $agentConfig['project_path'];
        $ruleId = $this->ruleConfig->id;

        $worktree = $worktreeManager->create($projectPath, $ruleId);

        Log::info("Created worktree for rule {$ruleId}", [
            'path' => $worktree['path'],
            'branch' => $worktree['branch'],
        ]);

        return $worktree;
    }

    /**
     * Clean up a worktree after execution.
     *
     * @param  array{path: string, branch: string}  $worktree
     * @param  array<string, mixed>  $agentConfig
     */
    protected function cleanupWorktree(array $worktree, array $agentConfig): void
    {
        $worktreeManager = app(WorktreeManager::class);
        $projectPath = $this->project->path ?? $agentConfig['project_path'];

        $removed = $worktreeManager->cleanup(
            $worktree['path'],
            $worktree['branch'],
            $projectPath,
        );

        $ruleId = $this->ruleConfig->id;

        if ($removed) {
            Log::info("Cleaned up worktree for rule {$ruleId} (no new commits)");
        } else {
            Log::info("Retained worktree for rule {$ruleId} (commits found)", [
                'path' => $worktree['path'],
                'branch' => $worktree['branch'],
            ]);
        }
    }
}
