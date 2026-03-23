<?php

use App\Models\WebhookLog;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Webhook Log Detail')] class extends Component {
    public ?int $webhookLogId = null;
    public bool $replaying = false;
    public string $replayMessage = '';

    public function mount(int $webhookLog): void
    {
        $this->webhookLogId = $webhookLog;
    }

    public function getWebhookLog(): ?WebhookLog
    {
        return WebhookLog::with('agentRuns')->find($this->webhookLogId);
    }

    public function refreshAgentRuns(): void
    {
        // Called from Echo listener — forces re-render with fresh data
    }

    public function getAgentRunIds(): array
    {
        $log = $this->getWebhookLog();
        if (! $log) {
            return [];
        }

        return $log->agentRuns->pluck('id')->toArray();
    }

    public function hasInProgressRuns(): bool
    {
        $log = $this->getWebhookLog();
        if (! $log) {
            return false;
        }

        return $log->agentRuns->whereIn('status', ['queued', 'running'])->isNotEmpty();
    }

    public function replay(): void
    {
        $log = $this->getWebhookLog();
        if (! $log) {
            return;
        }

        $this->replaying = true;
        $this->replayMessage = '';

        try {
            $payload = $log->payload ?? [];
            $eventType = $log->event_type ?? '';
            $baseEvent = str_contains($eventType, '.') ? substr($eventType, 0, strpos($eventType, '.')) : $eventType;

            $secret = config('services.github.webhook_secret');
            $body = json_encode($payload);
            $signature = $secret ? 'sha256=' . hash_hmac('sha256', $body, $secret) : null;

            $headers = [
                'X-GitHub-Event' => $baseEvent,
                'Content-Type' => 'application/json',
            ];

            if ($signature) {
                $headers['X-Hub-Signature-256'] = $signature;
            }

            $response = Http::withoutVerifying()->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post(url('/api/webhook'));

            $data = $response->json();

            if ($response->successful() && ($data['ok'] ?? false)) {
                $matched = $data['matched'] ?? 0;
                $newLogId = $data['webhook_log_id'] ?? null;
                $this->replayMessage = "Replayed successfully — {$matched} rule(s) matched.";

                if ($newLogId) {
                    $this->redirect(route('webhooks.show', $newLogId), navigate: true);
                }
            } else {
                $error = $data['error'] ?? $response->body();
                $this->replayMessage = "Replay failed: {$error}";
            }
        } catch (\Throwable $e) {
            $this->replayMessage = "Replay error: {$e->getMessage()}";
        } finally {
            $this->replaying = false;
        }
    }
}; ?>

<section
    class="w-full"
    x-data="agentRunStream(@js($this->getAgentRunIds()))"
    x-init="subscribe()"
    @agent-run-completed.window="$wire.refreshAgentRuns()"
>
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
            <div class="flex items-center gap-2">
                <flux:button variant="ghost" icon="arrow-path" wire:click="replay" wire:loading.attr="disabled" wire:target="replay">
                    <span wire:loading.remove wire:target="replay">{{ __('Replay') }}</span>
                    <span wire:loading wire:target="replay">{{ __('Replaying...') }}</span>
                </flux:button>
                <flux:button variant="ghost" icon="arrow-left" :href="route('webhooks.index')" wire:navigate>
                    {{ __('Back to Logs') }}
                </flux:button>
            </div>
        </div>

        @if ($replayMessage)
            <flux:callout variant="{{ str_starts_with($replayMessage, 'Replayed successfully') ? 'success' : 'danger' }}" class="mb-6">
                {{ $replayMessage }}
            </flux:callout>
        @endif

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
                                        <flux:button variant="ghost" size="sm" icon="eye" :href="route('agent-runs.show', $run->id)" wire:navigate>
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

        {{-- Live Stream --}}
        <template x-if="activeStreams.length > 0">
            <div class="mb-6">
                <flux:heading size="lg" class="mb-3">{{ __('Live Stream') }}</flux:heading>
                <template x-for="stream in activeStreams" :key="stream.runId">
                    <div class="mb-4 rounded-xl border border-yellow-200 dark:border-yellow-700 bg-yellow-50/50 dark:bg-yellow-900/10 p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-yellow-500"></span>
                            </span>
                            <flux:text class="text-sm font-medium">Agent Run #<span x-text="stream.runId"></span></flux:text>
                        </div>

                        {{-- Tool calls and text --}}
                        <div class="space-y-1.5 mb-3 max-h-80 overflow-y-auto">
                            <template x-for="(event, index) in stream.events" :key="index">
                                <div>
                                    <template x-if="event.type === 'tool'">
                                        <div class="rounded px-3 py-1.5" :class="event.status === 'error' ? 'bg-red-50 dark:bg-red-900/20' : event.status === 'done' ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-blue-50 dark:bg-blue-900/20'">
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-medium" :class="event.status === 'error' ? 'text-red-700 dark:text-red-300' : event.status === 'done' ? 'text-emerald-700 dark:text-emerald-300' : 'text-blue-700 dark:text-blue-300'" x-text="(event.status === 'error' ? '✗ ' : event.status === 'done' ? '✓ ' : '→ ') + event.tool_name.replace(/Tool$/, '')"></span>
                                                <template x-if="event.summary">
                                                    <span class="text-xs font-mono opacity-70" x-text="event.summary"></span>
                                                </template>
                                            </div>
                                            <template x-if="event.error">
                                                <div class="text-xs text-red-600 dark:text-red-400 mt-1" x-text="event.error"></div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="event.type === 'text'">
                                        <pre class="text-sm font-mono whitespace-pre-wrap text-zinc-700 dark:text-zinc-300 mt-2" x-text="event.text"></pre>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        {{-- Payload Summary --}}
        <div class="mb-6" x-data="{ showPayload: false }">
            <div class="flex items-center justify-between mb-3">
                <flux:heading size="lg">{{ __('Payload') }}</flux:heading>
                <flux:button variant="ghost" size="sm" icon="code-bracket" x-on:click="showPayload = !showPayload">
                    <span x-text="showPayload ? '{{ __('Hide JSON') }}' : '{{ __('Show JSON') }}'"></span>
                </flux:button>
            </div>

            @php
                $payload = $log->payload ?? [];
                $eventBase = str_contains($log->event_type, '.') ? substr($log->event_type, 0, strpos($log->event_type, '.')) : $log->event_type;
                $action = $payload['action'] ?? null;

                $summaryFields = [];

                // Common: repo
                if ($repo = data_get($payload, 'repository.full_name')) {
                    $summaryFields['Repo'] = $repo;
                }

                // Common: sender
                if ($sender = data_get($payload, 'sender.login')) {
                    $summaryFields['Sender'] = $sender;
                }

                // Event-specific fields
                match ($eventBase) {
                    'issues' => (function () use ($payload, &$summaryFields) {
                        $summaryFields['Issue'] = '#' . data_get($payload, 'issue.number', '?') . ' — ' . data_get($payload, 'issue.title', '');
                        if ($labels = data_get($payload, 'issue.labels')) {
                            $summaryFields['Labels'] = collect($labels)->pluck('name')->join(', ');
                        }
                        if ($assignee = data_get($payload, 'issue.assignee.login')) {
                            $summaryFields['Assignee'] = $assignee;
                        }
                    })(),
                    'pull_request' => (function () use ($payload, &$summaryFields) {
                        $summaryFields['PR'] = '#' . data_get($payload, 'pull_request.number', '?') . ' — ' . data_get($payload, 'pull_request.title', '');
                        $summaryFields['Branch'] = data_get($payload, 'pull_request.head.ref', '') . ' → ' . data_get($payload, 'pull_request.base.ref', '');
                        if ($labels = data_get($payload, 'pull_request.labels')) {
                            $summaryFields['Labels'] = collect($labels)->pluck('name')->join(', ');
                        }
                    })(),
                    'issue_comment' => (function () use ($payload, &$summaryFields) {
                        $issueNumber = data_get($payload, 'issue.number', '?');
                        $summaryFields['Issue'] = '#' . $issueNumber . ' — ' . data_get($payload, 'issue.title', '');
                        $body = data_get($payload, 'comment.body', '');
                        $summaryFields['Comment'] = Str::limit($body, 200);
                    })(),
                    'pull_request_review_comment', 'pull_request_review' => (function () use ($payload, &$summaryFields) {
                        $summaryFields['PR'] = '#' . data_get($payload, 'pull_request.number', '?') . ' — ' . data_get($payload, 'pull_request.title', '');
                        $body = data_get($payload, 'comment.body', data_get($payload, 'review.body', ''));
                        if ($body) {
                            $summaryFields['Comment'] = Str::limit($body, 200);
                        }
                    })(),
                    'push' => (function () use ($payload, &$summaryFields) {
                        $ref = data_get($payload, 'ref', '');
                        $summaryFields['Branch'] = str_replace('refs/heads/', '', $ref);
                        $commits = data_get($payload, 'commits', []);
                        $summaryFields['Commits'] = count($commits);
                        if ($head = data_get($payload, 'head_commit.message')) {
                            $summaryFields['Head Commit'] = Str::limit($head, 120);
                        }
                    })(),
                    default => null,
                };

                if ($action) {
                    $summaryFields = array_merge(['Action' => $action], $summaryFields);
                }
            @endphp

            @if (! empty($summaryFields))
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($summaryFields as $label => $value)
                        <div class="flex px-4 py-2.5">
                            <flux:text variant="subtle" class="text-xs uppercase w-28 shrink-0 pt-0.5">{{ $label }}</flux:text>
                            <flux:text class="text-sm">{{ $value }}</flux:text>
                        </div>
                    @endforeach
                </div>
            @endif

            <div x-show="showPayload" x-cloak class="mt-3">
                <pre class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4 text-sm font-mono overflow-x-auto max-h-96 overflow-y-auto">{{ json_encode($log->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </div>
    @endif
</section>

@script
<script>
Alpine.data('agentRunStream', (runIds) => ({
    activeStreams: [],
    channels: [],

    subscribe() {
        if (!window.Echo || !runIds.length) return;

        runIds.forEach(runId => {
            const channel = window.Echo.private(`agent-run.${runId}`);

            channel.listen('.text_delta', (e) => {
                const stream = this.getOrCreateStream(runId);
                stream.textBuffer = (stream.textBuffer || '') + (e.delta ?? '');
                // Flush text buffer as an event when we get a complete chunk
                clearTimeout(stream.textFlushTimer);
                stream.textFlushTimer = setTimeout(() => {
                    if (stream.textBuffer) {
                        stream.events.push({ type: 'text', text: stream.textBuffer });
                        stream.textBuffer = '';
                    }
                }, 300);
            });

            channel.listen('.tool_call', (e) => {
                const stream = this.getOrCreateStream(runId);
                // Flush any pending text before tool call
                if (stream.textBuffer) {
                    clearTimeout(stream.textFlushTimer);
                    stream.events.push({ type: 'text', text: stream.textBuffer });
                    stream.textBuffer = '';
                }
                stream.events.push({
                    type: 'tool',
                    tool_name: e.tool_name ?? 'unknown',
                    summary: this.summarizeArgs(e.tool_name, e.arguments),
                    status: 'running',
                    error: null,
                });
            });

            channel.listen('.tool_result', (e) => {
                const stream = this.getOrCreateStream(runId);
                const toolName = e.tool_name ?? 'unknown';
                // Find the last matching tool call and update it
                for (let i = stream.events.length - 1; i >= 0; i--) {
                    if (stream.events[i].type === 'tool' && stream.events[i].tool_name === toolName && stream.events[i].status === 'running') {
                        stream.events[i].status = (e.successful ?? true) ? 'done' : 'error';
                        stream.events[i].error = e.error ?? null;
                        return;
                    }
                }
            });

            channel.listen('.stream_end', () => {
                this.removeStream(runId);
                this.$dispatch('agent-run-completed');
            });

            channel.listen('.AgentRunUpdated', (e) => {
                this.removeStream(runId);
                this.$wire.refreshAgentRuns();
            });

            this.channels.push(channel);
        });
    },

    getOrCreateStream(runId) {
        let stream = this.activeStreams.find(s => s.runId === runId);
        if (!stream) {
            stream = { runId, events: [], textBuffer: '', textFlushTimer: null };
            this.activeStreams.push(stream);
        }
        return stream;
    },

    summarizeArgs(toolName, args) {
        if (!args) return null;
        switch (toolName) {
            case 'GlobTool': return args.pattern || null;
            case 'GrepTool': return args.pattern || null;
            case 'ReadTool': return args.file_path || null;
            case 'EditTool': return args.file_path || null;
            case 'WriteTool': return args.file_path || null;
            case 'BashTool': return args.command ? args.command.substring(0, 80) : null;
            default: return null;
        }
    },

    removeStream(runId) {
        this.activeStreams = this.activeStreams.filter(s => s.runId !== runId);
    },

    destroy() {
        this.channels.forEach(ch => ch.stopListening());
    }
}));
</script>
@endscript
