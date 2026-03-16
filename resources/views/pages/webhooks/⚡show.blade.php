<?php

use App\Models\AgentRun;
use App\Models\WebhookLog;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Webhook Log Detail')] class extends Component {
    public ?int $webhookLogId = null;
    public ?int $viewingAgentRunId = null;
    public bool $showAgentRunDetail = false;

    public function mount(int $webhookLog): void
    {
        $this->webhookLogId = $webhookLog;
    }

    public function getWebhookLog(): ?WebhookLog
    {
        return WebhookLog::with('agentRuns')->find($this->webhookLogId);
    }

    public function viewAgentRun(int $id): void
    {
        $this->viewingAgentRunId = $id;
        $this->showAgentRunDetail = true;
    }

    public function getViewingAgentRun(): ?AgentRun
    {
        if (! $this->viewingAgentRunId) {
            return null;
        }

        return AgentRun::find($this->viewingAgentRunId);
    }

    public function hasInProgressRuns(): bool
    {
        $log = $this->getWebhookLog();
        if (! $log) {
            return false;
        }

        return $log->agentRuns->whereIn('status', ['queued', 'running'])->isNotEmpty();
    }
}; ?>

<section class="w-full" @if ($this->hasInProgressRuns()) wire:poll.5s @endif>
    @php $log = $this->getWebhookLog(); @endphp

    @if (! $log)
        <flux:callout variant="danger">
            {{ __('Webhook log not found.') }}
        </flux:callout>
    @else
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ __('Webhook Log') }} #{{ $log->id }}</flux:heading>
                <flux:text class="mt-1">{{ $log->event_type }} &mdash; {{ $log->repo ?? __('No repo') }}</flux:text>
            </div>
            <flux:button variant="ghost" icon="arrow-left" :href="route('webhooks.index')" wire:navigate>
                {{ __('Back to Logs') }}
            </flux:button>
        </div>

        {{-- Summary --}}
        <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Event') }}</flux:text>
                <flux:heading size="sm" class="mt-1 font-mono">{{ $log->event_type }}</flux:heading>
            </div>
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Status') }}</flux:text>
                @php
                    $statusColors = [
                        'received' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
                        'processed' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
                        'error' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                    ];
                    $colorClass = $statusColors[$log->status] ?? 'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-300';
                @endphp
                <span class="mt-1 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium {{ $colorClass }}">
                    {{ $log->status }}
                </span>
            </div>
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Matched Rules') }}</flux:text>
                <flux:heading size="sm" class="mt-1">{{ is_array($log->matched_rules) ? count($log->matched_rules) : 0 }}</flux:heading>
            </div>
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Time') }}</flux:text>
                <flux:text class="mt-1 text-sm">{{ $log->created_at?->format('Y-m-d H:i:s') }}</flux:text>
            </div>
        </div>

        {{-- Error --}}
        @if ($log->error)
            <flux:callout variant="danger" class="mb-6">
                <strong>{{ __('Error:') }}</strong> {{ $log->error }}
            </flux:callout>
        @endif

        {{-- Matched Rules --}}
        @if (is_array($log->matched_rules) && count($log->matched_rules) > 0)
            <div class="mb-6">
                <flux:heading size="lg" class="mb-3">{{ __('Matched Rules') }}</flux:heading>
                <div class="flex flex-wrap gap-2">
                    @foreach ($log->matched_rules as $rule)
                        <span class="inline-flex items-center rounded-md bg-zinc-100 dark:bg-zinc-700 px-3 py-1 text-sm font-mono">
                            {{ is_array($rule) ? ($rule['rule_id'] ?? json_encode($rule)) : $rule }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Agent Runs --}}
        <div class="mb-6">
            <flux:heading size="lg" class="mb-3">{{ __('Agent Runs') }}</flux:heading>

            @if ($log->agentRuns->isEmpty())
                <flux:text variant="subtle">{{ __('No agent runs for this webhook.') }}</flux:text>
            @else
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Rule') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Duration') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Tokens') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Cost') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($log->agentRuns as $run)
                                <tr wire:key="run-{{ $run->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                    <td class="px-4 py-3 font-mono text-sm">{{ $run->rule_id }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        @php
                                            $runStatusColors = [
                                                'queued' => 'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-300',
                                                'running' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
                                                'success' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
                                                'failed' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                                                'skipped' => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400',
                                            ];
                                            $runColor = $runStatusColors[$run->status] ?? 'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-300';
                                        @endphp
                                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium {{ $runColor }}">
                                            {{ $run->status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        @if ($run->duration_ms)
                                            {{ number_format($run->duration_ms / 1000, 1) }}s
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm">{{ $run->tokens_used ? number_format($run->tokens_used) : '—' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ $run->cost ? '$' . number_format((float) $run->cost, 4) : '—' }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <flux:button variant="ghost" size="sm" icon="eye" wire:click="viewAgentRun({{ $run->id }})">
                                            {{ __('Detail') }}
                                        </flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Payload --}}
        <div class="mb-6">
            <flux:heading size="lg" class="mb-3">{{ __('Payload') }}</flux:heading>
            <pre class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4 text-sm font-mono overflow-x-auto max-h-96 overflow-y-auto">{{ json_encode($log->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>

        {{-- Agent Run Detail Modal --}}
        <flux:modal wire:model="showAgentRunDetail" class="md:w-4xl">
            @php $agentRun = $this->getViewingAgentRun(); @endphp
            @if ($agentRun)
                <flux:heading size="lg">{{ __('Agent Run Detail') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Rule:') }} {{ $agentRun->rule_id }}</flux:text>

                <div class="mt-4 grid grid-cols-2 gap-4">
                    <div>
                        <flux:text variant="subtle" class="text-xs uppercase">{{ __('Status') }}</flux:text>
                        <flux:text class="mt-1 font-medium">{{ $agentRun->status }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="subtle" class="text-xs uppercase">{{ __('Attempt') }}</flux:text>
                        <flux:text class="mt-1">{{ $agentRun->attempt ?? 1 }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="subtle" class="text-xs uppercase">{{ __('Duration') }}</flux:text>
                        <flux:text class="mt-1">{{ $agentRun->duration_ms ? number_format($agentRun->duration_ms / 1000, 1) . 's' : '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="subtle" class="text-xs uppercase">{{ __('Tokens') }}</flux:text>
                        <flux:text class="mt-1">{{ $agentRun->tokens_used ? number_format($agentRun->tokens_used) : '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="subtle" class="text-xs uppercase">{{ __('Cost') }}</flux:text>
                        <flux:text class="mt-1">{{ $agentRun->cost ? '$' . number_format((float) $agentRun->cost, 4) : '—' }}</flux:text>
                    </div>
                    <div>
                        <flux:text variant="subtle" class="text-xs uppercase">{{ __('Time') }}</flux:text>
                        <flux:text class="mt-1">{{ $agentRun->created_at?->format('Y-m-d H:i:s') }}</flux:text>
                    </div>
                </div>

                @if ($agentRun->steps && count($agentRun->steps) > 0)
                    <div class="mt-4">
                        <flux:text variant="subtle" class="text-xs uppercase mb-2">{{ __('Agent Steps') }}</flux:text>
                        <div class="space-y-3 max-h-96 overflow-y-auto">
                            @foreach ($agentRun->steps as $stepIndex => $step)
                                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <flux:text class="text-xs font-medium">{{ __('Step') }} {{ $stepIndex + 1 }}</flux:text>
                                        <div class="flex items-center gap-2">
                                            @if (isset($step['usage']))
                                                <flux:text class="text-xs text-zinc-500">
                                                    {{ number_format(($step['usage']['promptTokens'] ?? 0) + ($step['usage']['completionTokens'] ?? 0)) }} {{ __('tokens') }}
                                                </flux:text>
                                            @endif
                                            @if (isset($step['finish_reason']))
                                                <flux:badge size="sm" color="{{ $step['finish_reason'] === 'stop' ? 'green' : 'amber' }}">
                                                    {{ $step['finish_reason'] }}
                                                </flux:badge>
                                            @endif
                                        </div>
                                    </div>

                                    @if (! empty($step['tool_calls']))
                                        <div class="space-y-2 mb-2">
                                            @foreach ($step['tool_calls'] as $toolCall)
                                                <div class="rounded bg-blue-50 dark:bg-blue-900/20 p-2">
                                                    <flux:text class="text-xs font-medium text-blue-700 dark:text-blue-300">
                                                        {{ $toolCall['name'] ?? 'unknown' }}
                                                    </flux:text>
                                                    @if (! empty($toolCall['arguments']))
                                                        <pre class="mt-1 text-xs font-mono text-blue-600 dark:text-blue-400 whitespace-pre-wrap break-all">{{ json_encode($toolCall['arguments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if (! empty($step['tool_results']))
                                        <div class="space-y-2 mb-2">
                                            @foreach ($step['tool_results'] as $toolResult)
                                                <div class="rounded bg-emerald-50 dark:bg-emerald-900/20 p-2">
                                                    <flux:text class="text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                                        {{ $toolResult['name'] ?? 'result' }}
                                                    </flux:text>
                                                    <pre class="mt-1 text-xs font-mono text-emerald-600 dark:text-emerald-400 whitespace-pre-wrap break-all max-h-32 overflow-y-auto">{{ is_string($toolResult['result'] ?? null) ? $toolResult['result'] : json_encode($toolResult['result'] ?? '', JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if (! empty($step['text']))
                                        <pre class="text-sm font-mono whitespace-pre-wrap text-zinc-700 dark:text-zinc-300">{{ $step['text'] }}</pre>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($agentRun->output)
                    <div class="mt-4">
                        <flux:text variant="subtle" class="text-xs uppercase">{{ __('Final Output') }}</flux:text>
                        <pre class="mt-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 p-4 text-sm font-mono whitespace-pre-wrap max-h-64 overflow-y-auto">{{ $agentRun->output }}</pre>
                    </div>
                @endif

                @if ($agentRun->error)
                    <div class="mt-4">
                        <flux:text variant="subtle" class="text-xs uppercase text-red-600 dark:text-red-400">{{ __('Error') }}</flux:text>
                        <pre class="mt-2 rounded-lg bg-red-50 dark:bg-red-900/20 p-4 text-sm font-mono whitespace-pre-wrap text-red-800 dark:text-red-300">{{ $agentRun->error }}</pre>
                    </div>
                @endif
            @endif

            <div class="mt-6 flex justify-end">
                <flux:button variant="ghost" wire:click="$set('showAgentRunDetail', false)">{{ __('Close') }}</flux:button>
            </div>
        </flux:modal>
    @endif
</section>
