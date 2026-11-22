<?php

use App\Models\Room;
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

// Pemantauan fokus realtime satu room: hanya DOSEN yang menjadi anggota room (RoomPolicy@monitor).
Broadcast::channel('room.{code}.monitor', function ($user, $code) {
    $room = Room::where('code', $code)->first();

    return $room !== null && $user->can('monitor', $room);
});
