<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    /** Melihat isi room, materi, tugas, dan anggota: hanya anggota (termasuk owner). */
    public function view(User $user, Room $room): bool
    {
        return $room->hasMember($user);
    }

    /** Mengelola room (unggah materi, buat tugas, menilai, mengeluarkan anggota): hanya owner, mahasiswa maupun dosen. */
    public function manage(User $user, Room $room): bool
    {
        return $room->isOwnedBy($user);
    }

    public function delete(User $user, Room $room): bool
    {
        return $room->isOwnedBy($user);
    }

    /**
     * Melihat data fokus & aktivitas MAHASISWA di room: hanya dosen yang menjadi anggota room tersebut.
     * Mahasiswa (juga bila owner) hanya melihat datanya sendiri.
     */
    public function monitor(User $user, Room $room): bool
    {
        return $room->isMonitoredBy($user);
    }

    public function leave(User $user, Room $room): bool
    {
        return $room->hasMember($user) && ! $room->isOwnedBy($user);
    }
}
