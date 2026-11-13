<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Who is allowed to listen to what.
|
| These closures are the ONLY thing standing between a customer's private
| notification stream and anyone else who can authenticate - a websocket
| subscription is not covered by any route middleware, so "is signed in" has
| to become "is signed in AS THIS PERSON" here or not at all.
|
| They are registered for every driver and in every environment, including
| testing. They used to be loaded at the end of a Pusher-only branch, which
| meant an installation broadcasting through Reverb had no rules at all.
|
*/

/**
 * A customer's own notification stream.
 *
 * Laravel's Notifiable broadcasts to App.Models.User.{id} by default, so this
 * is the channel the bell feed, the web app and the phone all listen on.
 *
 * The id comparison is the whole authorisation. Returning true for any
 * authenticated user - which is what a closure that forgets the check does -
 * would let any account subscribe to any other account's notifications and
 * watch their money move in real time.
 */
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    // Guarded on the type as well as the id: the admin guard resolves an
    // Admin here, whose ids come from a different table and collide with
    // users' by coincidence rather than by meaning.
    return $user instanceof User && (int) $user->id === (int) $id;
});

/**
 * An administrator's own stream, on the admin guard.
 *
 * `guards` is load-bearing. Without it this channel is authorised against the
 * default guard, so a CUSTOMER whose id happened to match an administrator's
 * would be admitted to that administrator's notifications - two id sequences
 * from two tables that mean nothing to each other.
 */
Broadcast::channel('App.Models.Admin.{id}', function ($admin, $id) {
    return $admin !== null
        && ! ($admin instanceof User)
        && (int) $admin->id === (int) $id;
}, ['guards' => ['admin']]);
