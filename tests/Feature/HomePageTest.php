<?php

use App\Models\User;

test('home page loads for unauthenticated users', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('GitHub Actions, but the actions are AI agents');
});

test('home page shows dispatch.yml code block', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('dispatch.yml')
        ->assertSee('triage-issues');
});

test('home page shows how it works steps', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSeeInOrder(['01', '02', '03']);
});

test('home page shows log in link for guests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Log in');
});

test('home page shows dashboard link for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Dashboard');
});

test('home page shows footer tagline', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Self-hosted');
});
