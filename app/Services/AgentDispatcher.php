<?php

namespace App\Services;

use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\RuleConfig;
use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\WebhookLog;
use Illuminate\Support\Collection;

class AgentDispatcher
{
    public function __construct(
        protected PromptRenderer $promptRenderer,
    ) {}

    /**
     * Dispatch agent jobs for matched rules.
     *
     * @param  Collection<int, RuleConfig>  $matchedRules
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function dispatch(WebhookLog $webhookLog, Collection $matchedRules, array $payload, Project $project, DispatchConfig $config): array
    {
        $results = [];
        $haltPipeline = false;

        foreach ($matchedRules as $rule) {
            if ($haltPipeline) {
                $agentRun = AgentRun::create([
                    'webhook_log_id' => $webhookLog->id,
                    'rule_id' => $rule->id,
                    'status' => 'skipped',
                    'error' => 'Skipped due to a previous rule failure',
                    'created_at' => now(),
                ]);

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
}
