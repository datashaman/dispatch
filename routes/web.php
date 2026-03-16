<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/{project}/rules', 'pages::rules.index')->name('rules.index');
});

require __DIR__.'/settings.php';
