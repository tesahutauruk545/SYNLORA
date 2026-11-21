<?php

namespace App\Policies;

use App\Models\StudySession;
use App\Models\User;

class StudySessionPolicy
{
    /** Mengendalikan sesi (jeda/lanjut/selesai/kirim event): hanya pemilik sesi. */
    public function control(User $user, StudySession $session): bool
    {
        return (int) $session->user_id === (int) $user->id;
    }

    /**
     * Melihat rekap fokus: pemilik sesi, atau DOSEN yang menjadi anggota room tempat sesi berlangsung
     * (selama pemilik sesi masih anggota room itu). Mahasiswa tidak bisa melihat rekap sesi mahasiswa lain,
     * walau ia owner room.
     */
    public function viewRecap(User $user, StudySession $session): bool
    {
        if ((int) $session->user_id === (int) $user->id) {
            return true;
        }

        $room = $session->room_id !== null ? $session->room : null;
        if (! $room || ! $room->isMonitoredBy($user)) {
            return false;
        }

        return $session->user !== null
            && $session->user->isMahasiswa()
            && $room->hasMember($session->user);
    }
}
