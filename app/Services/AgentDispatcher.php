<?php

namespace App\Services;

use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\RuleConfig;
use App\Exceptions\PipelineException;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\WebhookLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class AgentDispatcher
{
    public function __construct(
        protected PromptRenderer $promptRenderer,
        protected PipelineOrchestrator $orchestrator,
    ) {}

    /**
     * Dispatch agent jobs for matched rules.
     * If rules have dependencies, they are topologically sorted and
     * upstream outputs are passed to dependent rules.
     *
     * @param  Collection<int, RuleConfig>  $matchedRules
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function dispatch(WebhookLog $webhookLog, Collection $matchedRules, array $payload, Project $project, DispatchConfig $config): array
    {
        if ($this->orchestrator->hasPipeline($matchedRules)) {
            return $this->dispatchPipeline($webhookLog, $matchedRules, $payload, $project, $config);
        }

        return $this->dispatchParallel($webhookLog, $matchedRules, $payload, $project, $config);
    }

    /**
     * Dispatch rules in parallel (no dependencies).
     *
     * @param  Collection<int, RuleConfig>  $matchedRules
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    protected function dispatchParallel(WebhookLog $webhookLog, Collection $matchedRules, array $payload, Project $project, DispatchConfig $config): array
    {
        $results = [];
        $haltPipeline = false;

        foreach ($matchedRules as $rule) {
            if ($haltPipeline) {
                $agentRun = $this->createSkippedRun($webhookLog, $rule, 'Skipped due to a previous rule failure');

                $results[] = [
                    'rule' => $rule->id,
                    'name' => $rule->name,
                    'status' => 'skipped',
                    'reason' => 'previous_failure',
                    'agent_run_id' => $agentRun->id,
                ];

                continue;
            }

            $agentRun = AgentRun::create([
                'webhook_log_id' => $webhookLog->id,
                'rule_id' => $rule->id,
                'status' => 'queued',
                'created_at' => now(),
            ]);

            ProcessAgentRun::dispatch($agentRun, $rule, $payload, $project, $config);

            $results[] = [
                'rule' => $rule->id,
                'name' => $rule->name,
                'status' => 'queued',
                'agent_run_id' => $agentRun->id,
            ];

            // Pipeline halting only works with sync queue driver (e.g. in tests).
            if (config('queue.default') === 'sync') {
                $agentRun->refresh();
                if (! $rule->continueOnError && $agentRun->status === 'failed') {
                    $haltPipeline = true;
                    $results[array_key_last($results)]['status'] = 'failed';
                }
            }
        }

        return $results;
    }

    /**
     * Dispatch rules as a pipeline with dependency resolution.
     * Rules are topologically sorted, AgentRun records are created upfront
     * with upstream_run_ids so dependent jobs can load outputs at execution time,
     * and jobs are dispatched via Bus::chain() to enforce execution order.
     *
     * @param  Collection<int, RuleConfig>  $matchedRules
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    protected function dispatchPipeline(WebhookLog $webhookLog, Collection $matchedRules, array $payload, Project $project, DispatchConfig $config): array
    {
        try {
            $sortedRules = $this->orchestrator->resolve($matchedRules);
        } catch (PipelineException $e) {
            Log::error('Pipeline resolution failed', ['error' => $e->getMessage()]);

            return $matchedRules->map(function (RuleConfig $rule) use ($webhookLog, $e) {
                $agentRun = $this->createSkippedRun($webhookLog, $rule, $e->getMessage());

                return [
                    'rule' => $rule->id,
                    'name' => $rule->name,
                    'status' => 'skipped',
                    'reason' => 'pipeline_error',
                    'agent_run_id' => $agentRun->id,
                ];
            })->all();
        }

        // Create all AgentRun records upfront so we can wire upstream_run_ids
        /** @var array<string, AgentRun> $agentRuns rule_id => AgentRun */
        $agentRuns = [];
        $results = [];

        foreach ($sortedRules as $rule) {
            $upstreamRunIds = [];
            foreach ($rule->dependsOn as $depId) {
                if (isset($agentRuns[$depId])) {
                    $upstreamRunIds[$depId] = $agentRuns[$depId]->id;
                }
            }

            $agentRun = AgentRun::create([
                'webhook_log_id' => $webhookLog->id,
                'rule_id' => $rule->id,
                'upstream_run_ids' => ! empty($upstreamRunIds) ? $upstreamRunIds : null,
                'status' => 'queued',
                'created_at' => now(),
            ]);

            $agentRuns[$rule->id] = $agentRun;

            $results[] = [
                'rule' => $rule->id,
                'name' => $rule->name,
                'status' => 'queued',
                'agent_run_id' => $agentRun->id,
                'pipeline' => true,
            ];
        }

        // Build the chain of jobs in topological order
        $chainedJobs = [];
        foreach ($sortedRules as $rule) {
            $chainedJobs[] = new ProcessAgentRun(
                $agentRuns[$rule->id],
                $rule,
                $payload,
                $project,
                $config,
            );
        }

        Bus::chain($chainedJobs)->onQueue('agents')->dispatch();

        return $results;
    }

    /**
     * Create a skipped agent run.
     */
    protected function createSkippedRun(WebhookLog $webhookLog, RuleConfig $rule, string $reason): AgentRun
    {
        return AgentRun::create([
            'webhook_log_id' => $webhookLog->id,
            'rule_id' => $rule->id,
            'status' => 'skipped',
            'error' => $reason,
            'created_at' => now(),
        ]);
    }
}
