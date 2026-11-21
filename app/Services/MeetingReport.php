<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Room;
use App\Models\User;

/**
 * Laporan meeting yang dihitung dari data tersimpan: durasi kehadiran per peserta
 * dan riwayat fokus (hijau/merah/tidak terpantau). Tidak ada angka bawaan.
 */
class MeetingReport
{
    /** @return array<string,mixed> */
    public static function build(Meeting $meeting, Room $room, User $viewer): array
    {
        $isOwner = $room->isOwnedBy($viewer);

        $attendances = $meeting->attendances()
            ->with(['user:id,name', 'focusLogs' => fn ($q) => $q->orderBy('occurred_at')->orderBy('id')])
            ->when(! $isOwner, fn ($q) => $q->where('user_id', $viewer->id))
            ->orderBy('joined_at')->orderBy('id')
            ->get();

        $participants = [];

        foreach ($attendances->groupBy('user_id') as $userId => $segments) {
            $seconds = ['HIJAU' => 0, 'MERAH' => 0, 'ABU' => 0];
            $timeline = [];

            foreach ($segments as $a) {
                /** @var MeetingAttendance $a */
                $end = $a->effectiveEnd();
                $logs = $a->focusLogs->values();

                foreach ($logs as $i => $log) {
                    $to = $logs[$i + 1]->occurred_at ?? $end;
                    $dur = max(0, min($to->getTimestamp(), $end->getTimestamp()) - $log->occurred_at->getTimestamp());
                    $seconds[$log->status] = ($seconds[$log->status] ?? 0) + $dur;
                    $timeline[] = ['at' => $log->occurred_at, 'status' => $log->status, 'reason' => $log->reason];
                }
            }

            $monitored = $seconds['HIJAU'] + $seconds['MERAH'];

            $participants[] = [
                'user_id' => (int) $userId,
                'name' => $segments->first()->user?->name ?? '—',
                'is_owner' => (int) $room->owner_id === (int) $userId,
                'is_me' => (int) $userId === (int) $viewer->id,
                'segments' => $segments,
                'attendance_seconds' => (int) $segments->sum(fn (MeetingAttendance $a) => $a->seconds()),
                'join_count' => $segments->count(),
                'seconds' => $seconds,
                'monitored_seconds' => $monitored,
                'focus_percent' => $monitored > 0 ? (int) round($seconds['HIJAU'] / $monitored * 100) : null,
                'timeline' => $timeline,
            ];
        }

        usort($participants, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        $end = $meeting->ended_at ?? now();

        return [
            'meeting' => $meeting,
            'is_owner' => $isOwner,
            'duration_seconds' => max(0, $end->getTimestamp() - $meeting->started_at->getTimestamp()),
            'participants' => $participants,
            'events' => $meeting->events()
                ->when(! $isOwner, fn ($q) => $q->where('user_id', $viewer->id))
                ->with('user:id,name')->orderBy('occurred_at')->orderBy('id')->get(),
        ];
    }
}
