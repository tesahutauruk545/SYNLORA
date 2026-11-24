<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Saluran pemantauan room: hanya dosen anggota room (RoomPolicy@monitor).
Broadcast::channel('room.{code}.monitor', function ($user, $code) {
    $room = \App\Models\Room::where('code', $code)->first();

    return $room && $room->isMonitoredBy($user);
});
