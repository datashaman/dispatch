<?php

use App\Enums\FeedbackRating;
use App\Models\AgentRun;
use App\Models\AgentRunFeedback;
use App\Models\User;
use App\Models\WebhookLog;
use Livewire\Volt\Volt;

// --- Model tests ---

test('agent run feedback stores rating and comment', function () {
    $user = User::factory()->create();
    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'status' => 'success',
    ]);

    $feedback = AgentRunFeedback::create([
        'agent_run_id' => $run->id,
        'user_id' => $user->id,
        'rating' => FeedbackRating::Helpful,
        'comment' => 'Great analysis!',
    ]);

    $feedback->refresh();
    expect($feedback->rating)->toBe(FeedbackRating::Helpful);
    expect($feedback->comment)->toBe('Great analysis!');
    expect($feedback->agentRun->id)->toBe($run->id);
    expect($feedback->user->id)->toBe($user->id);
});

test('agent run has many feedback', function () {
    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'status' => 'success',
    ]);

    AgentRunFeedback::factory()->count(2)->create([
        'agent_run_id' => $run->id,
    ]);

    expect($run->feedback)->toHaveCount(2);
});

test('feedback is unique per user per agent run', function () {
    $user = User::factory()->create();
    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'status' => 'success',
    ]);

    AgentRunFeedback::create([
        'agent_run_id' => $run->id,
        'user_id' => $user->id,
        'rating' => FeedbackRating::Helpful,
    ]);

    // Updating via updateOrCreate should work
    AgentRunFeedback::updateOrCreate(
        ['agent_run_id' => $run->id, 'user_id' => $user->id],
        ['rating' => FeedbackRating::NotHelpful, 'comment' => 'Changed my mind'],
    );

    expect(AgentRunFeedback::where('agent_run_id', $run->id)->where('user_id', $user->id)->count())->toBe(1);
    expect(AgentRunFeedback::where('agent_run_id', $run->id)->where('user_id', $user->id)->first()->rating)->toBe(FeedbackRating::NotHelpful);
});

test('feedback is deleted when agent run is deleted', function () {
    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'status' => 'success',
    ]);

    AgentRunFeedback::factory()->create(['agent_run_id' => $run->id]);

    expect(AgentRunFeedback::where('agent_run_id', $run->id)->count())->toBe(1);

    $run->delete();

    expect(AgentRunFeedback::where('agent_run_id', $run->id)->count())->toBe(0);
});

// --- UI tests ---

test('webhook show page displays feedback buttons for completed run', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'status' => 'success',
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $webhookLog->id])
        ->call('viewAgentRun', $run->id)
        ->assertSee('Was this helpful?')
        ->assertSee('Helpful')
        ->assertSee('Not helpful');
});

test('webhook show page hides feedback for queued run', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'status' => 'queued',
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $webhookLog->id])
        ->call('viewAgentRun', $run->id)
        ->assertDontSee('Was this helpful?');
});

test('webhook show page submits helpful feedback', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'status' => 'success',
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $webhookLog->id])
        ->call('viewAgentRun', $run->id)
        ->call('submitFeedback', 'helpful')
        ->assertSee('Feedback saved.');

    $feedback = AgentRunFeedback::where('agent_run_id', $run->id)->first();
    expect($feedback)->not->toBeNull();
    expect($feedback->rating)->toBe(FeedbackRating::Helpful);
    expect($feedback->user_id)->toBe($user->id);
});

test('webhook show page submits feedback with comment', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'status' => 'failed',
    ]);

    Volt::test('pages::webhooks.show', ['webhookLog' => $webhookLog->id])
        ->call('viewAgentRun', $run->id)
        ->set('feedbackComment', 'Missed the main issue')
        ->call('submitFeedback', 'not_helpful')
        ->assertSee('Feedback saved.');

    $feedback = AgentRunFeedback::where('agent_run_id', $run->id)->first();
    expect($feedback->rating)->toBe(FeedbackRating::NotHelpful);
    expect($feedback->comment)->toBe('Missed the main issue');
});

test('webhook show page updates existing feedback', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $webhookLog = WebhookLog::factory()->create();
    $run = AgentRun::factory()->create([
        'webhook_log_id' => $webhookLog->id,
        'status' => 'success',
    ]);

    // Submit initial feedback
    AgentRunFeedback::create([
        'agent_run_id' => $run->id,
        'user_id' => $user->id,
        'rating' => FeedbackRating::Helpful,
    ]);

    // Change to not helpful
    Volt::test('pages::webhooks.show', ['webhookLog' => $webhookLog->id])
        ->call('viewAgentRun', $run->id)
        ->call('submitFeedback', 'not_helpful')
        ->assertSee('Feedback saved.');

    expect(AgentRunFeedback::where('agent_run_id', $run->id)->count())->toBe(1);
    expect(AgentRunFeedback::where('agent_run_id', $run->id)->first()->rating)->toBe(FeedbackRating::NotHelpful);
});
