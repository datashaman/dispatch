<?php

use App\Http\Controllers\GitHubAppController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook', [WebhookController::class, 'handle']);
Route::post('/github/webhook', [GitHubAppController::class, 'webhook']);
