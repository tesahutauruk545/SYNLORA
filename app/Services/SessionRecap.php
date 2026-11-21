<?php

namespace App\Services;

use App\Models\StudySession;

/**
 * Menghitung rekap satu sesi dari DATA NYATA (focus_logs & activity_logs).
 * Tidak ada angka bawaan: bila pemantauan tidak dipakai, hasilnya memang kosong.
 *
 * Catatan: status fokus hanyalah INDIKATOR (tab aktif, wajah terlihat),
 * bukan ukuran konsentrasi yang sebenarnya.
 */
class SessionRecap
{
    public static function build(StudySession $session): array
    {
        $session->loadMissing(['user:id,name,role', 'room:id,name,code,owner_id']);

        $end = $session->ended_at ?? now();
        $logs = $session->focusLogs()->orderBy('occurred_at')->orderBy('id')->get();

        $seconds = ['HIJAU' => 0, 'KUNING' => 0, 'MERAH' => 0, 'ABU' => 0, 'DISCONNECTED' => 0, 'UNKNOWN' => 0];

        foreach ($logs as $i => $log) {
            $to = $logs[$i + 1]->occurred_at ?? $end;
            $seconds[$log->status] = ($seconds[$log->status] ?? 0)
                + max(0, $to->getTimestamp() - $log->occurred_at->getTimestamp());
        }

        $monitored = $seconds['HIJAU'] + $seconds['KUNING'] + $seconds['MERAH'];

        $activities = $session->activityLogs()->orderBy('occurred_at')->orderBy('id')->get();

        $count = fn (string $type, ?string $details = null) => $activities
            ->where('event_type', $type)
            ->when($details !== null, fn ($c) => $c->where('details', $details))
            ->count();

        $total = max(0, $end->getTimestamp() - $session->started_at->getTimestamp());

        return [
            'session' => $session,
            'total_seconds' => $total,
            'effective_seconds' => $session->effectiveSecondsNow(),
            'completed_pomodoros' => $session->completed_pomodoros,
            'seconds' => $seconds,
            'monitored_seconds' => $monitored,
            'focus_percent' => $monitored > 0 ? (int) round($seconds['HIJAU'] / $monitored * 100) : null,
            'monitoring_used' => $logs->isNotEmpty() || $activities->contains('event_type', 'CAMERA_ON'),
            'camera_used' => $activities->contains('event_type', 'CAMERA_ON'),
            'counts' => [
                'tab_switch' => $count('TAB_SWITCH_OUT'),
                'heartbeat_lost' => $count('HEARTBEAT_LOST'),
                'face_lost_short' => $count('FACE_LOST_SHORT'),
                'face_lost_long' => $count('FACE_LOST_LONG'),
            ],
            'activities' => $activities,
        ];
    }

    public static function fmt(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        if ($h > 0) {
            return "{$h}j {$m}m";
        }

        return $m > 0 ? "{$m}m {$s}d" : "{$s}d";
    }
}
