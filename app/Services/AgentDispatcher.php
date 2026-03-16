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
        $circuitBroken = false;

        foreach ($matchedRules as $rule) {
            if ($circuitBroken) {
                $agentRun = AgentRun::create([
                    'webhook_log_id' => $webhookLog->id,
                    'rule_id' => $rule->rule_id,
                    'status' => 'skipped',
                    'error' => 'Skipped due to circuit breaker from a previous rule failure',
                    'created_at' => now(),
                ]);

                $results[] = [
                    'rule' => $rule->rule_id,
                    'name' => $rule->name,
                    'status' => 'skipped',
                    'reason' => 'circuit_break',
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

            // If this rule has circuit_break enabled, mark for potential breaking
            // The actual circuit break happens when the job fails (handled in ProcessAgentRun)
            // For synchronous processing (sync queue driver in tests), check if the run already failed
            $agentRun->refresh();
            if ($rule->circuit_break && $agentRun->status === 'failed') {
                $circuitBroken = true;
                $results[array_key_last($results)]['status'] = 'failed';
            }
        }

        return $results;
    }
}
