<?php

namespace App\Jobs;

use App\Contracts\Executor;
use App\Executors\ClaudeCliExecutor;
use App\Executors\LaravelAiExecutor;
use App\Models\AgentRun;
use App\Models\Rule;
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
        public Rule $rule,
        public array $payload = [],
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

        $executor = $this->resolveExecutor();
        $renderedPrompt = app(PromptRenderer::class)->render(
            $this->rule->prompt ?? '',
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

            if ($result->status === 'success') {
                app(OutputHandler::class)->handle(
                    $this->agentRun,
                    $this->rule,
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
    }

    /**
     * Configure retry behavior from the rule's retry config.
     */
    protected function configureRetry(): void
    {
        $retryConfig = $this->rule->retryConfig;

        if ($retryConfig && $retryConfig->enabled) {
            $this->tries = $retryConfig->max_attempts;
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
     * Resolve the executor based on the project's agent_executor setting.
     */
    protected function resolveExecutor(): Executor
    {
        $project = $this->rule->project;
        $executor = $project?->agent_executor ?? 'laravel-ai';

        return match ($executor) {
            'claude-cli' => app(ClaudeCliExecutor::class),
            'laravel-ai' => app(LaravelAiExecutor::class),
            default => app(LaravelAiExecutor::class),
        };
    }

    /**
     * Resolve agent config by merging rule-level config with project-level fallbacks.
     *
     * @return array<string, mixed>
     */
    protected function resolveAgentConfig(): array
    {
        $project = $this->rule->project;
        $ruleConfig = $this->rule->agentConfig;

        return [
            'provider' => $ruleConfig?->provider ?? $project?->agent_provider,
            'model' => $ruleConfig?->model ?? $project?->agent_model,
            'max_tokens' => $ruleConfig?->max_tokens,
            'tools' => $ruleConfig?->tools ?? [],
            'disallowed_tools' => $ruleConfig?->disallowed_tools ?? [],
            'isolation' => $ruleConfig?->isolation ?? false,
            'instructions_file' => $project?->agent_instructions_file,
            'project_path' => $project?->path,
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
        $ruleId = $this->rule->rule_id;

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
        $projectPath = $this->rule->project?->path ?? $agentConfig['project_path'];

        $removed = $worktreeManager->cleanup(
            $worktree['path'],
            $worktree['branch'],
            $projectPath,
        );

        if ($removed) {
            Log::info("Cleaned up worktree for rule {$this->rule->rule_id} (no new commits)");
        } else {
            Log::info("Retained worktree for rule {$this->rule->rule_id} (commits found)", [
                'path' => $worktree['path'],
                'branch' => $worktree['branch'],
            ]);
        }
    }
}
