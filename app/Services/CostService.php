<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CostService
{
    /**
     * Get month-to-date total spend across all projects.
     */
    public function monthToDateSpend(?Carbon $date = null): string
    {
        $start = ($date ?? now())->copy()->startOfMonth();
        $end = ($date ?? now())->copy()->endOfMonth();

        return (string) AgentRun::query()
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end])
            ->sum('cost');
    }

    /**
     * Get month-to-date total tokens across all projects.
     */
    public function monthToDateTokens(?Carbon $date = null): int
    {
        $start = ($date ?? now())->copy()->startOfMonth();
        $end = ($date ?? now())->copy()->endOfMonth();

        return (int) AgentRun::query()
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end])
            ->sum('tokens_used');
    }

    /**
     * Get month-to-date run count.
     */
    public function monthToDateRuns(?Carbon $date = null): int
    {
        $start = ($date ?? now())->copy()->startOfMonth();
        $end = ($date ?? now())->copy()->endOfMonth();

        return AgentRun::query()
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /**
     * Get daily spend for a given month.
     *
     * @return Collection<int, array{date: string, cost: string}>
     */
    public function dailySpend(?Carbon $date = null): Collection
    {
        $start = ($date ?? now())->copy()->startOfMonth();
        $end = ($date ?? now())->copy()->endOfMonth();

        return AgentRun::query()
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end])
            ->select(
                DB::raw('date(created_at) as date'),
                DB::raw('sum(cost) as cost'),
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * Get per-project cost breakdown for the current month.
     *
     * @return Collection<int, array{repo: string, runs: int, tokens: int, cost: string, budget: string|null, budget_pct: float|null}>
     */
    public function projectBreakdown(?Carbon $date = null): Collection
    {
        $start = ($date ?? now())->copy()->startOfMonth();
        $end = ($date ?? now())->copy()->endOfMonth();

        $costs = AgentRun::query()
            ->join('webhook_logs', 'agent_runs.webhook_log_id', '=', 'webhook_logs.id')
            ->where('agent_runs.status', 'success')
            ->whereBetween('agent_runs.created_at', [$start, $end])
            ->select(
                'webhook_logs.repo',
                DB::raw('count(*) as runs'),
                DB::raw('sum(agent_runs.tokens_used) as tokens'),
                DB::raw('sum(agent_runs.cost) as cost'),
            )
            ->groupBy('webhook_logs.repo')
            ->orderByDesc('cost')
            ->get();

        $projects = Project::all()->keyBy('repo');

        return $costs->map(function ($row) use ($projects) {
            $project = $projects->get($row->repo);
            $budget = $project?->monthly_budget;
            $cost = (float) $row->cost;

            return [
                'repo' => $row->repo,
                'runs' => (int) $row->runs,
                'tokens' => (int) $row->tokens,
                'cost' => $row->cost,
                'budget' => $budget,
                'budget_pct' => $budget && (float) $budget > 0 ? round(($cost / (float) $budget) * 100, 1) : null,
            ];
        });
    }

    /**
     * Get projects that are approaching or over budget.
     *
     * @return Collection<int, array{repo: string, runs: int, tokens: int, cost: string, budget: string|null, budget_pct: float|null}>
     */
    public function budgetAlerts(?Carbon $date = null): Collection
    {
        return $this->projectBreakdown($date)
            ->filter(fn (array $row) => $row['budget_pct'] !== null && $row['budget_pct'] >= 50)
            ->values();
    }
}
