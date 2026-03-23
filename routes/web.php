<?php

use App\Http\Controllers\GitHubAppController;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('subscribe', function (Request $request) {
    $request->validate(['email' => 'required|email|max:255']);
    Subscriber::firstOrCreate(['email' => $request->email]);

    return back()->with('subscribed', true);
})->middleware('throttle:5,1')->name('subscribe');

Route::get('github/callback', [GitHubAppController::class, 'callback'])
    ->middleware(['lockdown', 'auth'])
    ->name('github.callback');

Route::middleware(['lockdown', 'auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard.index')->name('dashboard');
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
    Route::livewire('projects/{project}/config', 'pages::config.index')->name('config.index');
    Route::livewire('webhooks', 'pages::webhooks.index')->name('webhooks.index');
    Route::livewire('webhooks/{webhookLog}', 'pages::webhooks.show')->name('webhooks.show');
    Route::livewire('agent-runs/{agentRun}', 'pages::agent-runs.show')->name('agent-runs.show');
    Route::livewire('cost', 'pages::cost.index')->name('cost.index');
    Route::livewire('templates', 'pages::templates.index')->name('templates.index');
});

require __DIR__.'/settings.php';
