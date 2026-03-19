<?php

use App\Models\AgentRun;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\CostCalculator;
use App\Services\CostService;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('cost page renders with empty state', function () {
    Volt::test('pages::cost.index')
        ->assertSee('Cost')
        ->assertSee('Month-to-Date Spend')
        ->assertSee('$0.00')
        ->assertSee('No agent runs recorded this month');
});

test('cost service calculates month-to-date spend', function () {
    $webhookLog = WebhookLog::factory()->create(['repo' => 'owner/repo']);
    AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test',
        'status' => 'success',
        'cost' => '1.500000',
        'tokens_used' => 50000,
        'created_at' => now(),
    ]);
    AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test',
        'status' => 'success',
        'cost' => '0.750000',
        'tokens_used' => 25000,
        'created_at' => now(),
    ]);

    $service = app(CostService::class);
    expect((float) $service->monthToDateSpend())->toBe(2.25);
    expect($service->monthToDateTokens())->toBe(75000);
    expect($service->monthToDateRuns())->toBe(2);
});

test('cost service excludes failed runs from spend', function () {
    $webhookLog = WebhookLog::factory()->create(['repo' => 'owner/repo']);
    AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test',
        'status' => 'success',
        'cost' => '1.000000',
        'tokens_used' => 10000,
        'created_at' => now(),
    ]);
    AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test',
        'status' => 'failed',
        'cost' => '0.500000',
        'tokens_used' => 5000,
        'created_at' => now(),
    ]);

    $service = app(CostService::class);
    expect((float) $service->monthToDateSpend())->toBe(1.0);
    expect($service->monthToDateRuns())->toBe(1);
});

test('cost service provides per-project breakdown', function () {
    $log1 = WebhookLog::factory()->create(['repo' => 'owner/repo-a']);
    $log2 = WebhookLog::factory()->create(['repo' => 'owner/repo-b']);

    Project::factory()->create(['repo' => 'owner/repo-a', 'path' => '/tmp/a', 'monthly_budget' => 10.00]);
    Project::factory()->create(['repo' => 'owner/repo-b', 'path' => '/tmp/b']);

    AgentRun::factory()->create([
        'webhook_log_id' => $log1->id,
        'rule_id' => 'test',
        'status' => 'success',
        'cost' => '5.000000',
        'tokens_used' => 100000,
        'created_at' => now(),
    ]);
    AgentRun::factory()->create([
        'webhook_log_id' => $log2->id,
        'rule_id' => 'test',
        'status' => 'success',
        'cost' => '2.000000',
        'tokens_used' => 40000,
        'created_at' => now(),
    ]);

    $service = app(CostService::class);
    $breakdown = $service->projectBreakdown();

    expect($breakdown)->toHaveCount(2);

    $repoA = $breakdown->firstWhere('repo', 'owner/repo-a');
    expect((float) $repoA['cost'])->toBe(5.0);
    expect($repoA['budget'])->toBe('10.00');
    expect($repoA['budget_pct'])->toBe(50.0);

    $repoB = $breakdown->firstWhere('repo', 'owner/repo-b');
    expect((float) $repoB['cost'])->toBe(2.0);
    expect($repoB['budget'])->toBeNull();
    expect($repoB['budget_pct'])->toBeNull();
});

test('cost service returns budget alerts for projects over 50%', function () {
    $log = WebhookLog::factory()->create(['repo' => 'owner/repo']);
    Project::factory()->create(['repo' => 'owner/repo', 'path' => '/tmp/r', 'monthly_budget' => 10.00]);

    AgentRun::factory()->create([
        'webhook_log_id' => $log->id,
        'rule_id' => 'test',
        'status' => 'success',
        'cost' => '6.000000',
        'tokens_used' => 100000,
        'created_at' => now(),
    ]);

    $service = app(CostService::class);
    $alerts = $service->budgetAlerts();

    expect($alerts)->toHaveCount(1);
    expect($alerts->first()['budget_pct'])->toBe(60.0);
});

test('cost service excludes runs from other months', function () {
    $webhookLog = WebhookLog::factory()->create(['repo' => 'owner/repo']);
    AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'rule_id' => 'test',
        'status' => 'success',
        'cost' => '5.000000',
        'tokens_used' => 100000,
        'created_at' => now()->subMonth(),
    ]);

    $service = app(CostService::class);
    expect((float) $service->monthToDateSpend())->toBe(0.0);
});

test('cost page shows budget alerts', function () {
    $log = WebhookLog::factory()->create(['repo' => 'owner/alertable']);
    Project::factory()->create(['repo' => 'owner/alertable', 'path' => '/tmp/a', 'monthly_budget' => 10.00]);

    AgentRun::factory()->create([
        'webhook_log_id' => $log->id,
        'rule_id' => 'test',
        'status' => 'success',
        'cost' => '9.500000',
        'tokens_used' => 100000,
        'created_at' => now(),
    ]);

    Volt::test('pages::cost.index')
        ->assertSee('Budget Alerts')
        ->assertSee('owner/alertable')
        ->assertSee('Approaching budget');
});

test('cost page shows over budget alert', function () {
    $log = WebhookLog::factory()->create(['repo' => 'owner/overbudget']);
    Project::factory()->create(['repo' => 'owner/overbudget', 'path' => '/tmp/o', 'monthly_budget' => 5.00]);

    AgentRun::factory()->create([
        'webhook_log_id' => $log->id,
        'rule_id' => 'test',
        'status' => 'success',
        'cost' => '6.000000',
        'tokens_used' => 100000,
        'created_at' => now(),
    ]);

    Volt::test('pages::cost.index')
        ->assertSee('Over budget');
});

test('can edit project budget inline', function () {
    $log = WebhookLog::factory()->create(['repo' => 'owner/budgetable']);
    $project = Project::factory()->create(['repo' => 'owner/budgetable', 'path' => '/tmp/b']);

    AgentRun::factory()->create([
        'webhook_log_id' => $log->id,
        'rule_id' => 'test',
        'status' => 'success',
        'cost' => '1.000000',
        'tokens_used' => 10000,
        'created_at' => now(),
    ]);

    Volt::test('pages::cost.index')
        ->call('startEditBudget', 'owner/budgetable', null)
        ->assertSet('editingBudgetRepo', 'owner/budgetable')
        ->set('budgetValue', '25.00')
        ->call('saveBudget');

    expect($project->fresh()->monthly_budget)->toBe('25.00');
});

test('cost calculator returns correct cost for known models', function () {
    $calculator = new CostCalculator;

    // Claude Sonnet: $3/M input, $15/M output
    $cost = $calculator->calculate(1000000, 500000, 'claude-sonnet-4-5-20250514');
    expect((float) $cost)->toBe(10.5); // 3.0 + 7.5

    // GPT-4o-mini: $0.15/M input, $0.60/M output
    $cost = $calculator->calculate(1000000, 1000000, 'gpt-4o-mini');
    expect((float) $cost)->toBe(0.75); // 0.15 + 0.60
});

test('cost calculator returns null for unknown models', function () {
    $calculator = new CostCalculator;
    expect($calculator->calculate(1000, 1000, 'unknown-model'))->toBeNull();
    expect($calculator->calculate(1000, 1000, null))->toBeNull();
});

test('cost page is accessible via route', function () {
    $response = $this->get(route('cost.index'));
    $response->assertStatus(200);
});
