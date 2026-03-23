<?php

use App\Http\Middleware\LaunchLockdown;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Http\Request;

beforeEach(function () {
    config(['lockdown.header_name' => 'X-Launch-Token', 'lockdown.header_value' => 'secret123']);
});

// ── LaunchLockdown::isActive() ──

it('reports active when header is missing', function () {
    $request = Request::create('/');
    expect(LaunchLockdown::isActive($request))->toBeTrue();
});

it('reports active when header value is wrong', function () {
    $request = Request::create('/');
    $request->headers->set('X-Launch-Token', 'wrong');
    expect(LaunchLockdown::isActive($request))->toBeTrue();
});

it('reports inactive when header matches', function () {
    $request = Request::create('/');
    $request->headers->set('X-Launch-Token', 'secret123');
    expect(LaunchLockdown::isActive($request))->toBeFalse();
});

it('reports inactive when config is empty', function () {
    config(['lockdown.header_name' => null, 'lockdown.header_value' => null]);
    $request = Request::create('/');
    expect(LaunchLockdown::isActive($request))->toBeFalse();
});

it('reports inactive when only header name is empty', function () {
    config(['lockdown.header_name' => null]);
    $request = Request::create('/');
    expect(LaunchLockdown::isActive($request))->toBeFalse();
});

// ── Middleware redirects ──

it('redirects locked-out users to home from auth routes', function () {
    $this->get(route('login'))
        ->assertRedirect(route('home'));
});

it('allows access with correct header', function () {
    $this->withHeader('X-Launch-Token', 'secret123')
        ->get(route('login'))
        ->assertOk();
});

it('allows home page without header', function () {
    $this->get(route('home'))
        ->assertOk();
});

it('allows webhook API without header', function () {
    $this->postJson('/api/webhook', ['test' => true])
        ->assertStatus(400); // Bad request, not redirect — proves no lockdown
});

it('allows health check without header', function () {
    $this->get('/up')
        ->assertOk();
});

it('redirects dashboard when locked', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('home'));
});

it('allows dashboard with correct header', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->withHeader('X-Launch-Token', 'secret123')
        ->get(route('dashboard'))
        ->assertOk();
});

it('redirects settings when locked', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertRedirect(route('home'));
});

// ── Lockdown disabled ──

it('allows all routes when lockdown is disabled', function () {
    config(['lockdown.header_name' => null, 'lockdown.header_value' => null]);
    $this->get(route('login'))->assertOk();
});

// ── Subscribe route ──

it('stores a new subscriber email', function () {
    $this->post(route('subscribe'), ['email' => 'test@example.com'])
        ->assertRedirect()
        ->assertSessionHas('subscribed', true);

    $this->assertDatabaseHas('subscribers', ['email' => 'test@example.com']);
});

it('handles duplicate emails gracefully', function () {
    Subscriber::factory()->create(['email' => 'test@example.com']);

    $this->post(route('subscribe'), ['email' => 'test@example.com'])
        ->assertRedirect()
        ->assertSessionHas('subscribed', true);

    expect(Subscriber::where('email', 'test@example.com')->count())->toBe(1);
});

it('validates email format', function () {
    $this->post(route('subscribe'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');
});

it('validates email is required', function () {
    $this->post(route('subscribe'), ['email' => ''])
        ->assertSessionHasErrors('email');
});

// ── Home page conditionals ──

it('hides login link when locked down', function () {
    $this->get(route('home'))
        ->assertDontSee('Log in', escape: false)
        ->assertDontSee('Get Started');
});

it('shows login link when not locked down', function () {
    config(['lockdown.header_name' => null, 'lockdown.header_value' => null]);
    $this->get(route('home'))
        ->assertSee('Log in', escape: false);
});
