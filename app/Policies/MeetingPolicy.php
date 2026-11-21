<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;

class MeetingPolicy
{
    /**
     * Melihat laporan meeting: owner room (semua peserta) atau peserta yang pernah hadir
     * (hanya datanya sendiri; pemfilteran dilakukan di MeetingReport).
     */
    public function viewReport(User $user, Meeting $meeting): bool
    {
        $room = $meeting->room;

        if ($room === null || ! $room->hasMember($user)) {
            return false;
        }

        return $room->isOwnedBy($user)
            || $meeting->attendances()->where('user_id', $user->id)->exists();
    }
}
