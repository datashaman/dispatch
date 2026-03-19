<?php

use App\Models\Project;
use App\Services\CostService;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cost')] class extends Component {
    public ?string $editingBudgetRepo = null;
    public string $budgetValue = '';

    public function getCostService(): CostService
    {
        return app(CostService::class);
    }

    public function getMonthToDateSpend(): string
    {
        return $this->getCostService()->monthToDateSpend();
    }

    public function getMonthToDateTokens(): int
    {
        return $this->getCostService()->monthToDateTokens();
    }

    public function getMonthToDateRuns(): int
    {
        return $this->getCostService()->monthToDateRuns();
    }

    public function getDailySpend(): \Illuminate\Support\Collection
    {
        return $this->getCostService()->dailySpend();
    }

    public function getProjectBreakdown(): \Illuminate\Support\Collection
    {
        return $this->getCostService()->projectBreakdown();
    }

    public function getBudgetAlerts(): \Illuminate\Support\Collection
    {
        return $this->getCostService()->budgetAlerts();
    }

    public function startEditBudget(string $repo, ?string $currentBudget): void
    {
        $this->editingBudgetRepo = $repo;
        $this->budgetValue = $currentBudget ?? '';
    }

    public function saveBudget(): void
    {
        if (! $this->editingBudgetRepo) {
            return;
        }

        $project = Project::where('repo', $this->editingBudgetRepo)->first();
        if ($project) {
            $budget = $this->budgetValue !== '' ? (float) $this->budgetValue : null;
            $project->update(['monthly_budget' => $budget]);
        }

        $this->editingBudgetRepo = null;
        $this->budgetValue = '';
    }

    public function cancelEditBudget(): void
    {
        $this->editingBudgetRepo = null;
        $this->budgetValue = '';
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ __('Cost') }}</flux:heading>
            <flux:text class="mt-1">{{ now()->format('F Y') }}</flux:text>
        </div>
    </div>

    {{-- Stat Grid --}}
    @php
        $spend = $this->getMonthToDateSpend();
        $tokens = $this->getMonthToDateTokens();
        $runs = $this->getMonthToDateRuns();
    @endphp
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Month-to-Date Spend') }}</flux:text>
            <flux:heading size="xl" class="mt-1 font-mono" style="font-variant-numeric: tabular-nums">
                ${{ number_format((float) $spend, 2) }}
            </flux:heading>
        </div>
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Tokens Used') }}</flux:text>
            <flux:heading size="xl" class="mt-1 font-mono" style="font-variant-numeric: tabular-nums">
                {{ number_format($tokens) }}
            </flux:heading>
        </div>
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Agent Runs') }}</flux:text>
            <flux:heading size="xl" class="mt-1 font-mono" style="font-variant-numeric: tabular-nums">
                {{ number_format($runs) }}
            </flux:heading>
        </div>
    </div>

    {{-- Daily Spend Chart --}}
    @php
        $dailySpend = $this->getDailySpend();
        $maxDailyCost = $dailySpend->max('cost') ?: 1;
        $daysInMonth = now()->daysInMonth;
    @endphp
    <div class="mb-6">
        <flux:heading size="lg" class="mb-3">{{ __('Daily Spend') }}</flux:heading>
        @if ($dailySpend->isEmpty())
            <flux:text variant="subtle">{{ __('No spend recorded this month.') }}</flux:text>
        @else
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <div class="flex items-end gap-1" style="height: 120px" x-data>
                    @foreach ($dailySpend as $day)
                        @php
                            $pct = ((float) $day->cost / (float) $maxDailyCost) * 100;
                            $barHeight = max($pct, 2);
                        @endphp
                        <div
                            class="flex-1 bg-indigo-500 rounded-t-sm transition-all duration-150 hover:bg-indigo-400 relative group"
                            style="height: {{ $barHeight }}%"
                        >
                            <div class="absolute bottom-full mb-1 left-1/2 -translate-x-1/2 hidden group-hover:block bg-zinc-800 dark:bg-zinc-700 text-white text-xs rounded px-2 py-1 whitespace-nowrap z-10">
                                {{ \Carbon\Carbon::parse($day->date)->format('M j') }}: ${{ number_format((float) $day->cost, 2) }}
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-between mt-2">
                    <flux:text class="text-xs text-zinc-500">{{ $dailySpend->first()?->date ? \Carbon\Carbon::parse($dailySpend->first()->date)->format('M j') : '' }}</flux:text>
                    <flux:text class="text-xs text-zinc-500">{{ $dailySpend->last()?->date ? \Carbon\Carbon::parse($dailySpend->last()->date)->format('M j') : '' }}</flux:text>
                </div>
            </div>
        @endif
    </div>

    {{-- Budget Alerts --}}
    @php $alerts = $this->getBudgetAlerts(); @endphp
    @if ($alerts->isNotEmpty())
        <div class="mb-6">
            <flux:heading size="lg" class="mb-3">{{ __('Budget Alerts') }}</flux:heading>
            <div class="space-y-2">
                @foreach ($alerts as $alert)
                    @php
                        $alertColor = $alert['budget_pct'] >= 90
                            ? 'border-red-500 bg-red-500/10'
                            : 'border-amber-500 bg-amber-500/10';
                        $textColor = $alert['budget_pct'] >= 90
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-amber-600 dark:text-amber-400';
                    @endphp
                    <div class="rounded-lg border-l-4 {{ $alertColor }} p-3 flex items-center justify-between">
                        <div>
                            <flux:text class="font-medium font-mono text-sm">{{ $alert['repo'] }}</flux:text>
                            <flux:text class="text-xs {{ $textColor }}">
                                ${{ number_format((float) $alert['cost'], 2) }} / ${{ number_format((float) $alert['budget'], 2) }}
                                ({{ $alert['budget_pct'] }}%)
                            </flux:text>
                        </div>
                        <span class="text-xs font-medium {{ $textColor }}">
                            {{ $alert['budget_pct'] >= 100 ? __('Over budget') : __('Approaching budget') }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Per-Project Breakdown --}}
    <div class="mb-6">
        <flux:heading size="lg" class="mb-3">{{ __('Per-Project Breakdown') }}</flux:heading>
        @php $projects = $this->getProjectBreakdown(); @endphp
        @if ($projects->isEmpty())
            <flux:text variant="subtle">{{ __('No agent runs recorded this month.') }}</flux:text>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Project') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Runs') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Tokens') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Cost') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Budget') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($projects as $project)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-4 py-3 font-mono text-sm">{{ $project['repo'] }}</td>
                                <td class="px-4 py-3 text-right font-mono text-sm" style="font-variant-numeric: tabular-nums">{{ number_format($project['runs']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-sm" style="font-variant-numeric: tabular-nums">{{ number_format($project['tokens']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-sm" style="font-variant-numeric: tabular-nums">${{ number_format((float) $project['cost'], 4) }}</td>
                                <td class="px-4 py-3 text-sm">
                                    @if ($editingBudgetRepo === $project['repo'])
                                        <form wire:submit="saveBudget" class="flex items-center gap-2">
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                wire:model="budgetValue"
                                                class="w-24 rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-2 py-1 text-sm font-mono"
                                                placeholder="$/mo"
                                                autofocus
                                            >
                                            <flux:button type="submit" variant="ghost" size="sm">{{ __('Save') }}</flux:button>
                                            <flux:button type="button" variant="ghost" size="sm" wire:click="cancelEditBudget">{{ __('Cancel') }}</flux:button>
                                        </form>
                                    @else
                                        <div class="flex items-center gap-2">
                                            @if ($project['budget'])
                                                @php
                                                    $budgetColor = match(true) {
                                                        $project['budget_pct'] >= 90 => 'bg-red-500',
                                                        $project['budget_pct'] >= 50 => 'bg-amber-500',
                                                        default => 'bg-green-500',
                                                    };
                                                    $barWidth = min($project['budget_pct'], 100);
                                                @endphp
                                                <div class="w-20 h-2 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                                                    <div class="{{ $budgetColor }} h-full rounded-full" style="width: {{ $barWidth }}%"></div>
                                                </div>
                                                <span class="font-mono text-xs" style="font-variant-numeric: tabular-nums">
                                                    ${{ number_format((float) $project['budget'], 0) }}
                                                </span>
                                            @endif
                                            @php $budgetArg = $project['budget'] ? "'".$project['budget']."'" : 'null'; @endphp
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="pencil"
                                                wire:click="startEditBudget('{{ $project['repo'] }}', {{ $budgetArg }})"
                                            />
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
