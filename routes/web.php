<?php

use App\Http\Controllers\GitHubAppController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('github/callback', [GitHubAppController::class, 'callback'])
    ->middleware(['auth'])
    ->name('github.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
    Route::livewire('projects/{project}/rules', 'pages::rules.index')->name('rules.index');
    Route::livewire('projects/{project}/config', 'pages::config.index')->name('config.index');
    Route::livewire('webhooks', 'pages::webhooks.index')->name('webhooks.index');
    Route::livewire('webhooks/{webhookLog}', 'pages::webhooks.show')->name('webhooks.show');
    Route::livewire('cost', 'pages::cost.index')->name('cost.index');
    Route::livewire('templates', 'pages::templates.index')->name('templates.index');
});

require __DIR__.'/settings.php';
