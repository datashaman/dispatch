<?php

namespace App\Services;

use App\Jobs\ProcessAgentRun;
use App\Models\AgentRun;
use App\Models\Rule;
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
     * @param  Collection<int, Rule>  $matchedRules
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function dispatch(WebhookLog $webhookLog, Collection $matchedRules, array $payload): array
    {
        $results = [];
        $haltPipeline = false;

        foreach ($matchedRules as $rule) {
            if ($haltPipeline) {
                $agentRun = AgentRun::create([
                    'webhook_log_id' => $webhookLog->id,
                    'rule_id' => $rule->rule_id,
                    'status' => 'skipped',
                    'error' => 'Skipped due to a previous rule failure',
                    'created_at' => now(),
                ]);

                $results[] = [
                    'rule' => $rule->rule_id,
                    'name' => $rule->name,
                    'status' => 'skipped',
                    'reason' => 'previous_failure',
                    'agent_run_id' => $agentRun->id,
                ];

                continue;
            }

            $agentRun = AgentRun::create([
                'webhook_log_id' => $webhookLog->id,
                'rule_id' => $rule->rule_id,
                'status' => 'queued',
                'created_at' => now(),
            ]);

            ProcessAgentRun::dispatch($agentRun, $rule, $payload);

            $results[] = [
                'rule' => $rule->rule_id,
                'name' => $rule->name,
                'status' => 'queued',
                'agent_run_id' => $agentRun->id,
            ];

            // Pipeline halting only works with sync queue driver (e.g. in tests).
            // For async queues, jobs check pipeline state before executing.
            if (config('queue.default') === 'sync') {
                $agentRun->refresh();
                if (! $rule->continue_on_error && $agentRun->status === 'failed') {
                    $haltPipeline = true;
                    $results[array_key_last($results)]['status'] = 'failed';
                }
            }
        }

        return $results;
    }
}
