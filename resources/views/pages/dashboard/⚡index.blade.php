<?php

use App\Models\AgentRun;
use App\Models\Project;
use App\Models\WebhookLog;
use App\Services\TemplateRegistry;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * Get the authenticated user's project repos, cached per-request.
     */
    public function getUserProjectRepos(): Collection
    {
        return once(fn () => auth()->user()->projects()->pluck('repo'));
    }

    /**
     * Calculate safe percentage change between two values.
     * Returns null when prior period is zero.
     */
    protected function percentChange(int|float $current, int|float $prior): ?float
    {
        if ($prior === 0 || $prior === 0.0) {
            return null;
        }

        return round((($current - $prior) / $prior) * 100, 1);
    }

    /**
     * Base query for user-scoped webhook logs.
     */
    protected function webhookQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return WebhookLog::whereIn('repo', $this->getUserProjectRepos());
    }

    /**
     * Base query for user-scoped agent runs.
     */
    protected function agentRunQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return AgentRun::whereHas('webhookLog', fn ($q) => $q->whereIn('repo', $this->getUserProjectRepos()));
    }

    public function getWebhookCount(): int
    {
        return $this->webhookQuery()
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
    }

    public function getAgentRunCount(): int
    {
        return $this->agentRunQuery()
            ->where('agent_runs.created_at', '>=', now()->subDays(30))
            ->count();
    }

    public function getSuccessRate(): ?float
    {
        $total = $this->agentRunQuery()
            ->where('agent_runs.created_at', '>=', now()->subDays(30))
            ->count();

        if ($total === 0) {
            return null;
        }

        $completed = $this->agentRunQuery()
            ->where('agent_runs.created_at', '>=', now()->subDays(30))
            ->where('agent_runs.status', 'success')
            ->count();

        return round(($completed / $total) * 100, 1);
    }

    public function getMtdSpend(): string
    {
        $spend = $this->agentRunQuery()
            ->where('agent_runs.created_at', '>=', now()->startOfMonth())
            ->sum('agent_runs.cost');

        return number_format((float) $spend, 2);
    }

    public function getWebhookTrend(): ?float
    {
        $current = $this->webhookQuery()
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $prior = $this->webhookQuery()
            ->where('created_at', '>=', now()->subDays(60))
            ->where('created_at', '<', now()->subDays(30))
            ->count();

        return $this->percentChange($current, $prior);
    }

    public function getRunTrend(): ?float
    {
        $current = $this->agentRunQuery()
            ->where('agent_runs.created_at', '>=', now()->subDays(30))
            ->count();

        $prior = $this->agentRunQuery()
            ->where('agent_runs.created_at', '>=', now()->subDays(60))
            ->where('agent_runs.created_at', '<', now()->subDays(30))
            ->count();

        return $this->percentChange($current, $prior);
    }

    public function getRecentWebhooks(): Collection
    {
        return $this->webhookQuery()
            ->with(['agentRuns'])
            ->latest('created_at')
            ->limit(5)
            ->get();
    }

    public function getRecentRuns(): Collection
    {
        return $this->agentRunQuery()
            ->with(['webhookLog'])
            ->latest('agent_runs.created_at')
            ->limit(5)
            ->get();
    }

    public function getBudgetProjects(): Collection
    {
        return auth()->user()->projects()
            ->whereNotNull('monthly_budget')
            ->where('monthly_budget', '>', 0)
            ->get()
            ->map(function (Project $project) {
                $spend = AgentRun::whereHas('webhookLog', fn ($q) => $q->where('repo', $project->repo))
                    ->where('agent_runs.created_at', '>=', now()->startOfMonth())
                    ->sum('agent_runs.cost');

                $pct = $project->monthly_budget > 0
                    ? round(((float) $spend / (float) $project->monthly_budget) * 100, 1)
                    : 0;

                return [
                    'repo' => $project->repo,
                    'spend' => (float) $spend,
                    'budget' => (float) $project->monthly_budget,
                    'pct' => $pct,
                ];
            });
    }

    public function getProjectCount(): int
    {
        return auth()->user()->projects()->count();
    }

    public function getTemplateCount(): int
    {
        return count(app(TemplateRegistry::class)->all());
    }

    public function getEditRulesUrl(): string
    {
        $project = auth()->user()->projects()->latest('updated_at')->first();

        if ($project) {
            return route('config.index', $project);
        }

        return route('projects.index');
    }

    public function isFirstTimeUser(): bool
    {
        $repos = $this->getUserProjectRepos();

        if ($repos->isEmpty()) {
            return true;
        }

        $hasWebhooks = WebhookLog::whereIn('repo', $repos)->exists();
        $hasRuns = AgentRun::whereHas('webhookLog', fn ($q) => $q->whereIn('repo', $repos))->exists();

        return ! $hasWebhooks && ! $hasRuns;
    }
}; ?>

<section class="w-full" wire:poll.60s>
    @if ($this->isFirstTimeUser())
        {{-- Onboarding --}}
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <flux:heading size="lg" class="mb-4">{{ __('Welcome to Dispatch') }}</flux:heading>
            <flux:text class="mb-6">{{ __('Get your first AI agent running in 3 steps:') }}</flux:text>

            <div class="space-y-4">
                <div class="flex items-start gap-4">
                    <span class="font-mono text-xs text-zinc-400 mt-0.5">01</span>
                    <div>
                        <a href="{{ route('projects.index') }}" wire:navigate class="text-indigo-500 hover:text-indigo-400 font-medium">
                            {{ __('Connect a repo') }}
                        </a>
                        <flux:text variant="subtle" class="text-sm">{{ __('Link a GitHub repository to Dispatch.') }}</flux:text>
                    </div>
                </div>

                <div class="flex items-start gap-4">
                    <span class="font-mono text-xs text-zinc-400 mt-0.5">02</span>
                    <div>
                        <flux:text class="font-medium">{{ __('Add dispatch.yml to your repo') }}</flux:text>
                        <flux:text variant="subtle" class="text-sm">{{ __('Define rules that tell agents what to do.') }}</flux:text>
                    </div>
                </div>

                <div class="flex items-start gap-4">
                    <span class="font-mono text-xs text-zinc-400 mt-0.5">03</span>
                    <div>
                        <flux:text class="font-medium">{{ __('Push') }}</flux:text>
                        <flux:text variant="subtle" class="text-sm">{{ __('Your first webhook arrives and agents start working.') }}</flux:text>
                    </div>
                </div>
            </div>

            <flux:text variant="subtle" class="mt-6 text-sm">{{ __('Your activity will appear here once webhooks start flowing.') }}</flux:text>
        </div>
    @else
        {{-- Normal Dashboard --}}
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
                <flux:text variant="subtle" class="mt-1">{{ __('Last 30 days') }}</flux:text>
            </div>
        </div>

        {{-- Stat Grid --}}
        @php
            $webhookCount = $this->getWebhookCount();
            $runCount = $this->getAgentRunCount();
            $successRate = $this->getSuccessRate();
            $mtdSpend = $this->getMtdSpend();
            $webhookTrend = $this->getWebhookTrend();
            $runTrend = $this->getRunTrend();
        @endphp
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Total Webhooks --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Total Webhooks') }}</flux:text>
                <flux:heading size="xl" class="mt-1 font-mono" style="font-variant-numeric: tabular-nums">
                    {{ number_format($webhookCount) }}
                </flux:heading>
                <div class="mt-1">
                    @if ($webhookTrend === null)
                        <span class="text-xs text-zinc-400" aria-label="{{ __('No prior data') }}">&mdash;</span>
                    @elseif ($webhookTrend >= 0)
                        <span class="text-xs text-green-600 dark:text-green-400" aria-label="{{ __('Up :pct%', ['pct' => $webhookTrend]) }}">
                            <svg class="inline-block w-3 h-3 mr-0.5" viewBox="0 0 12 12" fill="currentColor"><path d="M6 2l4 5H2z"/></svg>{{ $webhookTrend }}%
                        </span>
                    @else
                        <span class="text-xs text-red-600 dark:text-red-400" aria-label="{{ __('Down :pct%', ['pct' => abs($webhookTrend)]) }}">
                            <svg class="inline-block w-3 h-3 mr-0.5" viewBox="0 0 12 12" fill="currentColor"><path d="M6 10l4-5H2z"/></svg>{{ abs($webhookTrend) }}%
                        </span>
                    @endif
                </div>
            </div>

            {{-- Agent Runs --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Agent Runs') }}</flux:text>
                <flux:heading size="xl" class="mt-1 font-mono" style="font-variant-numeric: tabular-nums">
                    {{ number_format($runCount) }}
                </flux:heading>
                <div class="mt-1">
                    @if ($runTrend === null)
                        <span class="text-xs text-zinc-400" aria-label="{{ __('No prior data') }}">&mdash;</span>
                    @elseif ($runTrend >= 0)
                        <span class="text-xs text-green-600 dark:text-green-400" aria-label="{{ __('Up :pct%', ['pct' => $runTrend]) }}">
                            <svg class="inline-block w-3 h-3 mr-0.5" viewBox="0 0 12 12" fill="currentColor"><path d="M6 2l4 5H2z"/></svg>{{ $runTrend }}%
                        </span>
                    @else
                        <span class="text-xs text-red-600 dark:text-red-400" aria-label="{{ __('Down :pct%', ['pct' => abs($runTrend)]) }}">
                            <svg class="inline-block w-3 h-3 mr-0.5" viewBox="0 0 12 12" fill="currentColor"><path d="M6 10l4-5H2z"/></svg>{{ abs($runTrend) }}%
                        </span>
                    @endif
                </div>
            </div>

            {{-- Success Rate --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Success Rate') }}</flux:text>
                <flux:heading size="xl" class="mt-1 font-mono" style="font-variant-numeric: tabular-nums">
                    {{ $successRate !== null ? $successRate . '%' : '—' }}
                </flux:heading>
            </div>

            {{-- MTD Spend --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('MTD Spend') }}</flux:text>
                <flux:heading size="xl" class="mt-1 font-mono" style="font-variant-numeric: tabular-nums">
                    ${{ $mtdSpend }}
                </flux:heading>
            </div>
        </div>

        {{-- Recent Webhooks + Recent Agent Runs --}}
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            {{-- Recent Webhooks --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <div class="flex items-center justify-between mb-3">
                    <flux:heading size="lg">{{ __('Recent Webhooks') }}</flux:heading>
                    <a href="{{ route('webhooks.index') }}" wire:navigate class="text-xs text-indigo-500 hover:text-indigo-400">{{ __('View all') }} &rarr;</a>
                </div>

                @php $recentWebhooks = $this->getRecentWebhooks(); @endphp
                @if ($recentWebhooks->isEmpty())
                    <flux:text variant="subtle" class="text-sm">{{ __('No webhooks received yet. Connect a repo to get started.') }}</flux:text>
                @else
                    <div class="space-y-2">
                        @foreach ($recentWebhooks as $webhook)
                            @php
                                $runs = $webhook->agentRuns;
                                if ($webhook->status === 'error') {
                                    $dotColor = 'bg-red-500';
                                    $dotLabel = 'Error';
                                } elseif ($runs->isEmpty()) {
                                    $dotColor = 'bg-amber-500';
                                    $dotLabel = 'No rules matched';
                                } elseif ($runs->contains('status', 'failed')) {
                                    $dotColor = 'bg-red-500';
                                    $dotLabel = 'Failed';
                                } elseif ($runs->contains('status', 'running') || $runs->contains('status', 'queued')) {
                                    $dotColor = 'bg-amber-500';
                                    $dotLabel = 'In progress';
                                } else {
                                    $dotColor = 'bg-green-500';
                                    $dotLabel = 'Completed';
                                }
                            @endphp
                            <a href="{{ route('webhooks.show', $webhook) }}" wire:navigate class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors duration-150">
                                <span class="h-2 w-2 rounded-full {{ $dotColor }} shrink-0" aria-label="{{ $dotLabel }}"></span>
                                <span class="font-mono text-sm truncate">{{ $webhook->event_type }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400 truncate">{{ $webhook->repo }}</span>
                                <span class="ml-auto text-xs text-zinc-400 whitespace-nowrap">{{ $webhook->created_at?->diffForHumans() }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Recent Agent Runs --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <div class="flex items-center justify-between mb-3">
                    <flux:heading size="lg">{{ __('Recent Agent Runs') }}</flux:heading>
                </div>

                @php $recentRuns = $this->getRecentRuns(); @endphp
                @if ($recentRuns->isEmpty())
                    <flux:text variant="subtle" class="text-sm">{{ __('No agent runs yet. Add rules to your dispatch.yml.') }}</flux:text>
                @else
                    <div class="space-y-2">
                        @foreach ($recentRuns as $run)
                            @php
                                $badgeColor = match ($run->status) {
                                    'success' => 'green',
                                    'running' => 'amber',
                                    'failed' => 'red',
                                    'queued' => 'indigo',
                                    default => 'zinc',
                                };
                                $duration = $run->duration_ms ? round($run->duration_ms / 1000, 1) . 's' : null;
                                $cost = $run->cost ? '$' . number_format((float) $run->cost, 4) : null;
                            @endphp
                            <a href="{{ route('agent-runs.show', $run) }}" wire:navigate class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors duration-150">
                                <flux:badge size="sm" color="{{ $badgeColor }}">{{ $run->status }}</flux:badge>
                                <span class="text-sm truncate">{{ $run->rule_id }}</span>
                                <span class="ml-auto text-xs font-mono text-zinc-400 whitespace-nowrap" style="font-variant-numeric: tabular-nums">
                                    @if ($duration && $cost)
                                        {{ $duration }} / {{ $cost }}
                                    @elseif ($duration)
                                        {{ $duration }}
                                    @elseif ($cost)
                                        {{ $cost }}
                                    @endif
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Budget Health + Quick Actions --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {{-- Budget Health --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <div class="flex items-center justify-between mb-3">
                    <flux:heading size="lg">{{ __('Budget Health') }}</flux:heading>
                    <a href="{{ route('cost.index') }}" wire:navigate class="text-xs text-indigo-500 hover:text-indigo-400">{{ __('Details') }} &rarr;</a>
                </div>

                @php $budgetProjects = $this->getBudgetProjects(); @endphp
                @if ($budgetProjects->isEmpty())
                    <flux:text variant="subtle" class="text-sm">{{ __('No budgets configured.') }}</flux:text>
                @else
                    <div class="space-y-3">
                        @foreach ($budgetProjects as $bp)
                            @php
                                $barColor = match (true) {
                                    $bp['pct'] >= 90 => 'bg-red-500',
                                    $bp['pct'] >= 50 => 'bg-amber-500',
                                    default => 'bg-green-500',
                                };
                                $barWidth = min($bp['pct'], 100);
                            @endphp
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-mono text-sm truncate">{{ $bp['repo'] }}</span>
                                    <span class="text-xs font-mono text-zinc-400" style="font-variant-numeric: tabular-nums">
                                        ${{ number_format($bp['spend'], 2) }} / ${{ number_format($bp['budget'], 2) }}
                                    </span>
                                </div>
                                <div
                                    class="h-2 w-full rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden"
                                    role="img"
                                    aria-label="{{ $bp['repo'] }}: {{ $bp['pct'] }}% of budget used"
                                >
                                    <div class="{{ $barColor }} h-full rounded-full transition-all duration-150" style="width: {{ $barWidth }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Quick Actions --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <flux:heading size="lg" class="mb-3">{{ __('Quick Actions') }}</flux:heading>

                <div class="space-y-2">
                    <a href="{{ route('projects.index') }}" wire:navigate class="flex items-center gap-2 text-sm text-indigo-500 hover:text-indigo-400 transition-colors duration-150">
                        <span>&rarr;</span>
                        <span>{{ __('Connect Repo') }} ({{ $this->getProjectCount() }} {{ __('connected') }})</span>
                    </a>
                    <a href="{{ $this->getEditRulesUrl() }}" wire:navigate class="flex items-center gap-2 text-sm text-indigo-500 hover:text-indigo-400 transition-colors duration-150">
                        <span>&rarr;</span>
                        <span>{{ __('Edit Rules') }}</span>
                    </a>
                    <a href="{{ route('templates.index') }}" wire:navigate class="flex items-center gap-2 text-sm text-indigo-500 hover:text-indigo-400 transition-colors duration-150">
                        <span>&rarr;</span>
                        <span>{{ __('Browse Templates') }} ({{ $this->getTemplateCount() }} {{ __('available') }})</span>
                    </a>
                </div>
            </div>
        </div>
    @endif
</section>
