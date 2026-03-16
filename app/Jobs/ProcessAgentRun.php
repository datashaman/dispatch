<?php

namespace App\Jobs;

use App\Contracts\Executor;
use App\Executors\LaravelAiExecutor;
use App\Models\AgentRun;
use App\Models\Rule;
use App\Services\PromptRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAgentRun implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public AgentRun $agentRun,
        public Rule $rule,
        public array $payload = [],
    ) {
        $this->onQueue('agents');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->agentRun->update(['status' => 'running']);

        $executor = $this->resolveExecutor();
        $renderedPrompt = app(PromptRenderer::class)->render(
            $this->rule->prompt ?? '',
            $this->payload,
        );
        $agentConfig = $this->resolveAgentConfig();

        $result = $executor->execute($this->agentRun, $renderedPrompt, $agentConfig);

        $this->agentRun->update([
            'status' => $result->status,
            'output' => $result->output,
            'tokens_used' => $result->tokensUsed,
            'cost' => $result->cost,
            'duration_ms' => $result->durationMs,
            'error' => $result->error,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->agentRun->update([
            'status' => 'failed',
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Resolve the executor based on the project's agent_executor setting.
     */
    protected function resolveExecutor(): Executor
    {
        $project = $this->rule->project;
        $executor = $project?->agent_executor ?? 'laravel-ai';

        return match ($executor) {
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
}
