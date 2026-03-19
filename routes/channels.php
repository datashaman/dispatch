<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Agent run streaming — private channel, only authenticated users can listen
Broadcast::channel('agent-run.{id}', function ($user, $id) {
    return $user !== null;
});
