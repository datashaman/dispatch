<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Agent run streaming — public channel so the webhook detail page can listen
// without requiring user authentication (agent runs are triggered by webhooks, not users).
