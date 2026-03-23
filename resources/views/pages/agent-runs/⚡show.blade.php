<?php

use App\Enums\FeedbackRating;
use App\Models\AgentRun;
use App\Models\AgentRunFeedback;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Agent Run Detail')] class extends Component {
    public ?int $agentRunId = null;
    public string $feedbackComment = '';
    public string $feedbackMessage = '';

    public function mount(int $agentRun): void
    {
        $this->agentRunId = $agentRun;
    }

    public function getAgentRun(): ?AgentRun
    {
        return AgentRun::find($this->agentRunId);
    }

    public function submitFeedback(string $rating): void
    {
        $user = Auth::user();
        if (! $user || ! $this->agentRunId) {
            return;
        }

        $feedbackRating = FeedbackRating::from($rating);

        AgentRunFeedback::updateOrCreate(
            [
                'agent_run_id' => $this->agentRunId,
                'user_id' => $user->id,
            ],
            [
                'rating' => $feedbackRating,
                'comment' => $this->feedbackComment ?: null,
            ],
        );

        $this->feedbackMessage = 'Feedback saved.';
    }

    public function getExistingFeedback(): ?AgentRunFeedback
    {
        $user = Auth::user();
        if (! $user || ! $this->agentRunId) {
            return null;
        }

        return AgentRunFeedback::where('agent_run_id', $this->agentRunId)
            ->where('user_id', $user->id)
            ->first();
    }

    public function isRunning(): bool
    {
        $run = $this->getAgentRun();

        return $run && in_array($run->status, ['queued', 'running']);
    }
}; ?>

<section
    class="w-full"
    x-data="agentRunDetailStream(@js($this->agentRunId))"
    x-init="subscribe()"
    @agent-run-completed.window="$wire.$refresh()"
>
    @php $agentRun = $this->getAgentRun(); @endphp

    @if (! $agentRun)
        <flux:callout variant="danger">
            {{ __('Agent run not found.') }}
        </flux:callout>
    @else
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ __('Agent Run') }} #{{ $agentRun->id }}</flux:heading>
                <flux:text class="mt-1">{{ __('Rule:') }} {{ $agentRun->rule_id }}</flux:text>
            </div>
            <flux:button variant="ghost" icon="arrow-left" :href="route('webhooks.show', $agentRun->webhook_log_id)" wire:navigate>
                {{ __('Back to Webhook') }}
            </flux:button>
        </div>

        {{-- Stats Grid --}}
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                <flux:text variant="subtle" class="text-xs uppercase">{{ __('Status') }}</flux:text>
                @php
                    $statusColors = [
                        'queued' => 'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-300',
                        'running' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
                        'success' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
                        'failed' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                        'skipped' => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400',
                    ];
                    $statusColor = $statusColors[$agentRun->status] ?? 'bg-zinc-100 text-zinc-800';
                @endphp
                <span class="mt-1 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium {{ $statusColor }}">
                    {{ $agentRun->status }}
                </span>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                <flux:text variant="subtle" class="text-xs uppercase">{{ __('Attempt') }}</flux:text>
                <flux:text class="mt-1 font-medium">{{ $agentRun->attempt ?? 1 }}</flux:text>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                <flux:text variant="subtle" class="text-xs uppercase">{{ __('Duration') }}</flux:text>
                <flux:text class="mt-1 font-medium">{{ $agentRun->duration_ms ? number_format($agentRun->duration_ms / 1000, 1) . 's' : '—' }}</flux:text>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                <flux:text variant="subtle" class="text-xs uppercase">{{ __('Tokens') }}</flux:text>
                <flux:text class="mt-1 font-medium">{{ $agentRun->tokens_used ? number_format($agentRun->tokens_used) : '—' }}</flux:text>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                <flux:text variant="subtle" class="text-xs uppercase">{{ __('Cost') }}</flux:text>
                <flux:text class="mt-1 font-medium">{{ $agentRun->cost ? '$' . number_format((float) $agentRun->cost, 4) : '—' }}</flux:text>
            </div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                <flux:text variant="subtle" class="text-xs uppercase">{{ __('Time') }}</flux:text>
                <flux:text class="mt-1 font-medium">{{ $agentRun->created_at?->format('Y-m-d H:i:s') }}</flux:text>
            </div>
        </div>

        {{-- Live Stream --}}
        <template x-if="activeStream">
            <div class="mb-6">
                <flux:heading size="lg" class="mb-3">{{ __('Live Stream') }}</flux:heading>
                <div class="rounded-xl border border-yellow-200 dark:border-yellow-700 bg-yellow-50/50 dark:bg-yellow-900/10 p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-yellow-500"></span>
                        </span>
                        <flux:text class="text-xs font-medium">{{ __('Agent Running') }}</flux:text>
                    </div>
                    {{-- Tool calls and text --}}
                    <div class="space-y-1.5 mb-3 max-h-[600px] overflow-y-auto">
                        <template x-for="(event, index) in activeStream.events" :key="index">
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
                                            <pre class="mt-1 text-xs font-mono text-red-600 dark:text-red-400 whitespace-pre-wrap" x-text="event.error"></pre>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="event.type === 'text'">
                                    <pre class="text-sm font-mono whitespace-pre-wrap text-zinc-700 dark:text-zinc-300 px-1" x-text="event.text"></pre>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        {{-- Agent Steps --}}
        @if ($agentRun->steps && count($agentRun->steps) > 0)
            <div class="mb-6">
                <flux:heading size="lg" class="mb-3">{{ __('Agent Steps') }}</flux:heading>
                <div class="space-y-3">
                    @foreach ($agentRun->steps as $stepIndex => $step)
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                            <div class="flex items-center justify-between mb-2">
                                <flux:text class="text-sm font-medium">{{ __('Step') }} {{ $stepIndex + 1 }}</flux:text>
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
                                            <div class="flex items-center gap-2">
                                                <flux:text class="text-xs font-medium text-blue-700 dark:text-blue-300">
                                                    {{ preg_replace('/Tool$/', '', $toolCall['name'] ?? 'unknown') }}
                                                </flux:text>
                                                @php
                                                    $summary = match($toolCall['name'] ?? '') {
                                                        'GlobTool' => $toolCall['arguments']['pattern'] ?? null,
                                                        'GrepTool' => $toolCall['arguments']['pattern'] ?? null,
                                                        'ReadTool' => $toolCall['arguments']['file_path'] ?? null,
                                                        'EditTool' => $toolCall['arguments']['file_path'] ?? null,
                                                        'WriteTool' => $toolCall['arguments']['file_path'] ?? null,
                                                        'BashTool' => isset($toolCall['arguments']['command']) ? Str::limit($toolCall['arguments']['command'], 80) : null,
                                                        default => null,
                                                    };
                                                @endphp
                                                @if ($summary)
                                                    <span class="text-xs font-mono text-blue-500 dark:text-blue-400 opacity-70">{{ $summary }}</span>
                                                @endif
                                            </div>
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
                                                {{ preg_replace('/Tool$/', '', $toolResult['name'] ?? 'result') }}
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

        {{-- Diff --}}
        @if ($agentRun->diff)
            <div class="mb-6">
                <flux:heading size="lg" class="mb-3">{{ __('Diff') }}</flux:heading>
                <pre class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4 text-xs font-mono whitespace-pre-wrap max-h-[600px] overflow-y-auto overflow-x-auto">@foreach (explode("\n", $agentRun->diff) as $line)@if (str_starts_with($line, '+') && ! str_starts_with($line, '+++'))<span class="text-green-600 dark:text-green-400">{{ $line }}</span>
@elseif (str_starts_with($line, '-') && ! str_starts_with($line, '---'))<span class="text-red-600 dark:text-red-400">{{ $line }}</span>
@elseif (str_starts_with($line, '@@'))<span class="text-blue-600 dark:text-blue-400">{{ $line }}</span>
@elseif (str_starts_with($line, 'diff '))<span class="font-bold text-zinc-700 dark:text-zinc-300">{{ $line }}</span>
@else{{ $line }}
@endif @endforeach</pre>
            </div>
        @endif

        {{-- Final Output --}}
        @if ($agentRun->output)
            <div class="mb-6">
                <flux:heading size="lg" class="mb-3">{{ __('Final Output') }}</flux:heading>
                <pre class="rounded-lg bg-zinc-100 dark:bg-zinc-800 p-4 text-sm font-mono whitespace-pre-wrap max-h-96 overflow-y-auto">{{ $agentRun->output }}</pre>
            </div>
        @endif

        {{-- Error --}}
        @if ($agentRun->error)
            <div class="mb-6">
                <flux:heading size="lg" class="mb-3 text-red-600 dark:text-red-400">{{ __('Error') }}</flux:heading>
                <pre class="rounded-lg bg-red-50 dark:bg-red-900/20 p-4 text-sm font-mono whitespace-pre-wrap text-red-800 dark:text-red-300">{{ $agentRun->error }}</pre>
            </div>
        @endif

        {{-- Quality Feedback --}}
        @if ($agentRun->status === 'success' || $agentRun->status === 'failed')
            @php $existingFeedback = $this->getExistingFeedback(); @endphp
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:text variant="subtle" class="text-xs uppercase mb-3">{{ __('Was this helpful?') }}</flux:text>

                @if ($feedbackMessage)
                    <flux:callout variant="success" class="mb-3">
                        {{ $feedbackMessage }}
                    </flux:callout>
                @endif

                <div class="flex items-center gap-3 mb-3">
                    <flux:button
                        size="sm"
                        variant="{{ $existingFeedback?->rating === FeedbackRating::Helpful ? 'primary' : 'ghost' }}"
                        icon="hand-thumb-up"
                        wire:click="submitFeedback('helpful')"
                    >
                        {{ __('Helpful') }}
                    </flux:button>
                    <flux:button
                        size="sm"
                        variant="{{ $existingFeedback?->rating === FeedbackRating::NotHelpful ? 'danger' : 'ghost' }}"
                        icon="hand-thumb-down"
                        wire:click="submitFeedback('not_helpful')"
                    >
                        {{ __('Not helpful') }}
                    </flux:button>
                </div>

                <flux:textarea
                    wire:model="feedbackComment"
                    placeholder="{{ __('Optional: tell us more...') }}"
                    rows="2"
                    class="text-sm"
                />
            </div>
        @endif
    @endif
</section>

@script
<script>
Alpine.data('agentRunDetailStream', (runId) => ({
    activeStream: null,
    channel: null,

    subscribe() {
        if (!window.Echo || !runId) return;

        const channel = window.Echo.private(`agent-run.${runId}`);

        channel.listen('.text_delta', (e) => {
            const stream = this.getOrCreateStream();
            stream.textBuffer = (stream.textBuffer || '') + (e.delta ?? '');
            clearTimeout(stream.textFlushTimer);
            stream.textFlushTimer = setTimeout(() => {
                if (stream.textBuffer) {
                    stream.events.push({ type: 'text', text: stream.textBuffer });
                    stream.textBuffer = '';
                }
            }, 300);
        });

        channel.listen('.tool_call', (e) => {
            const stream = this.getOrCreateStream();
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
            const stream = this.getOrCreateStream();
            const toolName = e.tool_name ?? 'unknown';
            for (let i = stream.events.length - 1; i >= 0; i--) {
                if (stream.events[i].type === 'tool' && stream.events[i].tool_name === toolName && stream.events[i].status === 'running') {
                    stream.events[i].status = (e.successful ?? true) ? 'done' : 'error';
                    stream.events[i].error = e.error ?? null;
                    return;
                }
            }
        });

        channel.listen('.completed', () => {
            const stream = this.getOrCreateStream();
            if (stream.textBuffer) {
                clearTimeout(stream.textFlushTimer);
                stream.events.push({ type: 'text', text: stream.textBuffer });
                stream.textBuffer = '';
            }
            setTimeout(() => {
                this.activeStream = null;
                window.dispatchEvent(new CustomEvent('agent-run-completed'));
            }, 2000);
        });

        this.channel = channel;
    },

    getOrCreateStream() {
        if (!this.activeStream) {
            this.activeStream = { events: [], textBuffer: '', textFlushTimer: null };
        }
        return this.activeStream;
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

    destroy() {
        if (this.channel) {
            this.channel.stopListening();
        }
    }
}));
</script>
@endscript
