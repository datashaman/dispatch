<?php

use App\Models\WebhookLog;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Webhook Logs')] class extends Component {
    public string $filterRepo = '';
    public string $filterEventType = '';
    public string $filterStatus = '';

    public function getLogs()
    {
        $query = WebhookLog::query()->orderByDesc('created_at');

        if ($this->filterRepo !== '') {
            $query->where('repo', $this->filterRepo);
        }

        if ($this->filterEventType !== '') {
            $query->where('event_type', $this->filterEventType);
        }

        if ($this->filterStatus !== '') {
            $query->where('status', $this->filterStatus);
        }

        return $query->withCount('agentRuns')->limit(100)->get();
    }

    public function getRepos(): array
    {
        return WebhookLog::query()->distinct()->whereNotNull('repo')->pluck('repo')->sort()->values()->all();
    }

    public function getEventTypes(): array
    {
        return WebhookLog::query()->distinct()->pluck('event_type')->sort()->values()->all();
    }

    public function getStatuses(): array
    {
        return WebhookLog::query()->distinct()->pluck('status')->sort()->values()->all();
    }

    public function clearFilters(): void
    {
        $this->filterRepo = '';
        $this->filterEventType = '';
        $this->filterStatus = '';
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ __('Webhook Logs') }}</flux:heading>
            <flux:text class="mt-1">{{ __('View incoming webhook events and their processing status.') }}</flux:text>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:field>
            <flux:label>{{ __('Repository') }}</flux:label>
            <flux:select wire:model.live="filterRepo" class="w-48">
                <flux:select.option value="">{{ __('All') }}</flux:select.option>
                @foreach ($this->getRepos() as $repo)
                    <flux:select.option value="{{ $repo }}">{{ $repo }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Event Type') }}</flux:label>
            <flux:select wire:model.live="filterEventType" class="w-48">
                <flux:select.option value="">{{ __('All') }}</flux:select.option>
                @foreach ($this->getEventTypes() as $eventType)
                    <flux:select.option value="{{ $eventType }}">{{ $eventType }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Status') }}</flux:label>
            <flux:select wire:model.live="filterStatus" class="w-48">
                <flux:select.option value="">{{ __('All') }}</flux:select.option>
                @foreach ($this->getStatuses() as $status)
                    <flux:select.option value="{{ $status }}">{{ $status }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>

        @if ($filterRepo !== '' || $filterEventType !== '' || $filterStatus !== '')
            <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                {{ __('Clear') }}
            </flux:button>
        @endif
    </div>

    {{-- Logs List --}}
    @php $logs = $this->getLogs(); @endphp

    @if ($logs->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
            <flux:icon name="inbox" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">{{ __('No webhook logs') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Webhook events will appear here once the server receives them.') }}</flux:text>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Event') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Repo') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Matched') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Time') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-3 font-mono text-sm">{{ $log->event_type }}</td>
                            <td class="px-4 py-3 text-sm">{{ $log->repo ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm">
                                @php $matchedCount = is_array($log->matched_rules) ? count($log->matched_rules) : 0; @endphp
                                {{ $matchedCount }} {{ __('rules') }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @php
                                    $statusColors = [
                                        'received' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
                                        'processed' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
                                        'error' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                                    ];
                                    $colorClass = $statusColors[$log->status] ?? 'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-300';
                                @endphp
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium {{ $colorClass }}">
                                    {{ $log->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $log->created_at?->diffForHumans() }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:button variant="ghost" size="sm" icon="eye" :href="route('webhooks.show', $log)" wire:navigate>
                                    {{ __('View') }}
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
