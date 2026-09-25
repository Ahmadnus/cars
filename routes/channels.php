<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorization
|--------------------------------------------------------------------------
|
| This file is the gate on real-time. A channel name carries no privilege on
| its own: a client may only subscribe if the callback below returns true, so
| knowing a conversation's id is not enough to listen to it.
|
| Each callback mirrors the check its REST counterpart makes, because a socket
| is simply another way to read the same data and must not be the weaker door.
|
*/

/**
 * A conversation, for its two participants only.
 *
 * `includes()` is the same method ChatController uses, so the socket and the
 * endpoint can never disagree about who belongs here.
 */
Broadcast::channel('conversation.{uuid}', function (User $user, string $uuid) {
    $conversation = Conversation::where('uuid', $uuid)->first();

    return $conversation?->includes($user) ?? false;
});

/**
 * A user's own channel — their bookings, their decisions.
 *
 * The id in the name must be the subscriber's own; comparing as strings because
 * the channel segment arrives as text.
 */
Broadcast::channel('user.{id}', function (User $user, string $id) {
    return (string) $user->id === $id;
});

/**
 * The staff queue for join requests.
 *
 * A presence channel so reviewers can see who else is looking, which is what
 * stops two people working the same request. Only staff who may read the queue
 * are admitted, and the member payload carries a name — never an email or phone.
 */
Broadcast::channel('staff.registrations', function (User $user) {
    if (! $user->hasPermission('registrations.view')) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

/** The staff queue for booking requests. */
Broadcast::channel('staff.bookings', function (User $user) {
    if (! $user->hasPermission('appointments.view')) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

/*
 | There is deliberately no channel for an applicant following their own
 | request. With no account there is nothing to authenticate a subscription
 | against, and guarding a channel with the reference alone would be a weaker
 | door than the rest of the system uses. The public app polls its status
 | endpoint and receives a push notification once approval creates its login.
 */
