<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Room;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data untuk dashboard monitoring dosen.
 *
 * Aturan akses: dosen hanya melihat mahasiswa yang menjadi anggota room yang SAMA dengannya, baik room
 * miliknya (owner) maupun room yang ia ikuti lewat kode. Hanya data di room-room tersebut yang tampil;
 * sesi solo dan data di room lain milik mahasiswa tidak pernah terlihat.
 *
 * Efisien untuk 40+ mahasiswa: jumlah query tetap (tidak bergantung jumlah mahasiswa) dan hasilnya
 * di-cache beberapa detik, sehingga banyak dosen/refresh tidak membebani database.
 */
class LecturerMonitor
{
    /** Room yang diikuti dosen (sebagai owner atau anggota) = cakupan monitoringnya. */
    public static function monitoredRooms(User $dosen): Collection
    {
        if (! $dosen->isDosen()) {
            return collect();
        }

        return Room::whereIn('id', DB::table('room_members')->where('user_id', $dosen->id)->select('room_id'))
            ->orderBy('name')->get(['id', 'code', 'name', 'target_minutes']);
    }

    /** @return array{rooms:array, summary:array, students:array, generated_at:int} */
    public static function snapshot(User $dosen, ?string $roomCode = null): array
    {
        $key = 'monitor:'.$dosen->id.':'.($roomCode ?: 'all');

        return Cache::remember($key, (int) config('synlora.monitor.cache_seconds', 3), fn () => self::build($dosen, $roomCode));
    }

    /** Buang cache dashboard semua dosen anggota room sebuah sesi agar perubahan status langsung terlihat. */
    public static function forgetFor(StudySession $session): void
    {
        if (! $session->room_id) {
            return;
        }
        $room = Room::find($session->room_id, ['id', 'code']);
        if ($room) {
            self::forgetRoom($room);
        }
    }

    /**
     * Buang cache dashboard semua dosen di room ini (+ $extraUserIds, mis. dosen yang baru keluar).
     * Wajib dipanggil saat keanggotaan room berubah agar hak akses tidak bertahan sampai cache kedaluwarsa.
     */
    public static function forgetRoom(Room $room, array $extraUserIds = []): void
    {
        $dosenIds = DB::table('room_members')
            ->join('users', 'users.id', '=', 'room_members.user_id')
            ->where('room_members.room_id', $room->id)->where('users.role', 'dosen')
            ->pluck('users.id')->merge($extraUserIds)->unique();

        foreach ($dosenIds as $id) {
            Cache::forget('monitor:'.$id.':all');
            Cache::forget('monitor:'.$id.':'.$room->code);
        }
    }

    private static function build(User $dosen, ?string $roomCode): array
    {
        $rooms = self::monitoredRooms($dosen);
        $scope = $roomCode ? $rooms->where('code', $roomCode) : $rooms;
        $roomIds = $scope->pluck('id');
        $roomById = $rooms->keyBy('id');

        $now = now();
        $dayStart = $now->copy()->startOfDay();

        // 1) Anggota (selain owner) dari room yang dipilih.
        $members = DB::table('room_members')
            ->join('users', 'users.id', '=', 'room_members.user_id')
            ->whereIn('room_members.room_id', $roomIds)
            ->where('users.id', '!=', $dosen->id)
            ->where('users.role', 'mahasiswa')
            ->get(['users.id', 'users.name', 'users.nim', 'users.kelas', 'users.daily_goal_minutes', 'room_members.room_id']);

        $userIds = $members->pluck('id')->unique()->values();

        // 2) Sesi terbuka di room dosen ini.
        $open = StudySession::whereIn('user_id', $userIds)->whereIn('room_id', $roomIds)->open()->get();
        $open->each(function (StudySession $s) {
            if ($s->needsSync()) {
                $s->sync();
            }
        });
        $open = $open->filter->isOpen()->keyBy('user_id');

        // 2b) Presence ONLINE website (ping/heartbeat server-side). Hanya untuk mahasiswa dalam cakupan room dosen ini.
        $seenOnline = OnlinePresence::lastSeen($userIds);
        $nowTs = $now->getTimestamp();

        // 3) Rekap hari ini (hanya sesi di room dosen ini).
        $today = StudySession::whereIn('user_id', $userIds)
            ->whereIn('room_id', $roomIds)
            ->where('started_at', '>=', $dayStart)
            ->selectRaw('user_id, SUM(total_effective_seconds) AS secs, SUM(completed_pomodoros) AS poms, COUNT(*) AS sessions, MAX(last_heartbeat_at) AS last_seen')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // 4) Terakhir terlihat (seluruh waktu, hanya di room dosen ini).
        $lastSeenAll = StudySession::whereIn('user_id', $userIds)
            ->whereIn('room_id', $roomIds)
            ->selectRaw('user_id, MAX(COALESCE(last_heartbeat_at, ended_at, started_at)) AS last_seen')
            ->groupBy('user_id')
            ->pluck('last_seen', 'user_id');

        $students = [];
        foreach ($members->groupBy('id') as $uid => $rows) {
            $m = $rows->first();
            /** @var StudySession|null $s */
            $s = $open->get($uid);
            $t = $today->get($uid);

            $secs = (int) ($t->secs ?? 0);
            if ($s) {
                $secs += $s->effectiveSecondsNow() - (int) $s->total_effective_seconds;
            }

            // ONLINE (membuka website) ditentukan oleh ping/heartbeat, BUKAN oleh ada-tidaknya sesi Pomodoro.
            $online = OnlinePresence::isOnline($seenOnline[$uid] ?? null, $s, $nowTs);
            $presence = OnlinePresence::state($online, $s);

            [$status, $reason, $source] = match (true) {
                $s !== null => $s->displayFocus($now),
                $online => ['ABU', 'Online, belum memulai sesi', 'system'],
                default => ['ABU', 'Offline', 'system'],
            };

            $seen = $lastSeenAll[$uid] ?? null;
            $seenTs = $seen ? Carbon::parse($seen)->getTimestamp() : null;
            if (isset($seenOnline[$uid])) {
                $seenTs = max($seenTs ?? 0, $seenOnline[$uid]);
            }

            $students[] = [
                'id' => (int) $uid,
                'name' => $m->name,
                'nim' => $m->nim,
                'kelas' => $m->kelas,
                'room' => $roomById[$s?->room_id ?? $m->room_id]->name ?? '',
                'presence' => $presence,
                'status' => $status,
                'source' => $source,
                'reason' => $reason,
                'online' => $online,
                'camera_on' => (bool) ($s?->camera_on),
                'camera' => $s ? $s->cameraInfo()['camera'] : 'CAMERA_OFF',
                'camera_label' => $s ? $s->cameraInfo()['camera_label'] : 'Kamera tidak dipakai',
                'phase' => $s?->phase,
                'remaining_seconds' => $s && $s->isOpen() ? $s->remainingSeconds($now) : null,
                'session_pomodoros' => $s?->completed_pomodoros ?? 0,
                'today_seconds' => $secs,
                'today_pomodoros' => (int) ($t->poms ?? 0) + ($s ? 0 : 0),
                'today_sessions' => (int) ($t->sessions ?? 0),
                'goal_minutes' => (int) ($m->daily_goal_minutes ?: 120),
                'present_today' => $s !== null || (int) ($t->sessions ?? 0) > 0,
                'last_seen_ts' => $seenTs,
                'last_seen' => $seenTs ? Carbon::createFromTimestamp($seenTs)->diffForHumans() : null,
            ];
        }

        usort($students, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $count = fn (string $st) => count(array_filter($students, fn ($x) => $x['status'] === $st));
        $studying = count(array_filter($students, fn ($x) => $x['presence'] === 'belajar'));

        return [
            'rooms' => $rooms->map(fn ($r) => ['code' => $r->code, 'name' => $r->name])->values()->all(),
            'kelas' => collect($students)->pluck('kelas')->filter()->unique()->sort()->values()->all(),
            'summary' => [
                'total' => count($students),
                'studying' => $studying,
                'online' => count(array_filter($students, fn ($x) => $x['online'])),
                'HIJAU' => $count('HIJAU'),
                'KUNING' => $count('KUNING'),
                'MERAH' => $count('MERAH'),
                'ABU' => $count('ABU'),
                'DISCONNECTED' => $count('DISCONNECTED'),
                'UNKNOWN' => $count('UNKNOWN'),
                'camera_off' => count(array_filter($students, fn ($x) => $x['presence'] === 'belajar' && $x['camera'] === 'CAMERA_OFF')),
                'present_today' => count(array_filter($students, fn ($x) => $x['present_today'])),
                'absent_today' => count(array_filter($students, fn ($x) => ! $x['present_today'])),
                'goal_met_today' => count(array_filter($students, fn ($x) => $x['today_seconds'] >= $x['goal_minutes'] * 60)),
                'avg_today_minutes' => $students ? (int) round(array_sum(array_column($students, 'today_seconds')) / count($students) / 60) : 0,
            ],
            'students' => $students,
            'generated_at' => $now->getTimestamp(),
        ];
    }

    /** ID room yang diikuti BERSAMA oleh $dosen dan $student (batas data yang boleh dilihat dosen). */
    private static function sharedRoomIds(User $dosen, User $student): Collection
    {
        if (! $dosen->isDosen() || ! $student->isMahasiswa()) {
            return collect();
        }

        return DB::table('room_members as d')
            ->join('room_members as s', 's.room_id', '=', 'd.room_id')
            ->where('d.user_id', $dosen->id)
            ->where('s.user_id', $student->id)
            ->pluck('d.room_id');
    }

    /** Apakah $student mahasiswa yang berada di room yang sama dengan $dosen? (dasar otorisasi riwayat) */
    public static function mayView(User $dosen, User $student): bool
    {
        return self::sharedRoomIds($dosen, $student)->isNotEmpty();
    }

    /** Riwayat satu mahasiswa, dibatasi pada room yang diikuti bersama dosen. */
    public static function history(User $dosen, User $student): array
    {
        $roomIds = self::sharedRoomIds($dosen, $student);

        $sessions = StudySession::where('user_id', $student->id)->whereIn('room_id', $roomIds)
            ->with('room:id,name')->latest('started_at')->limit(8)->get();
        $sessions->each(fn (StudySession $s) => $s->needsSync() ? $s->sync() : null);

        $activities = ActivityLog::where('user_id', $student->id)->whereIn('room_id', $roomIds)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(40)->get();

        $days = [];
        $from = now()->subDays(6)->startOfDay();
        $byDay = StudySession::where('user_id', $student->id)->whereIn('room_id', $roomIds)
            ->where('started_at', '>=', $from)->get(['started_at', 'total_effective_seconds', 'status', 'phase', 'phase_started_at', 'paused_at', 'focus_duration_minutes', 'break_duration_minutes', 'started_at']);
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i)->startOfDay();
            $secs = $byDay->filter(fn ($s) => $s->started_at->isSameDay($d))->sum(fn ($s) => $s->effectiveSecondsNow());
            $days[] = ['label' => $d->translatedFormat('D d/m'), 'minutes' => (int) floor($secs / 60)];
        }

        return [
            'student' => ['id' => $student->id, 'name' => $student->name, 'nim' => $student->nim, 'kelas' => $student->kelas],
            'days' => $days,
            'sessions' => $sessions->map(function (StudySession $s) {
                $recap = SessionRecap::build($s);

                return [
                    'id' => $s->id,
                    'started_at' => $s->started_at->translatedFormat('d M, H:i'),
                    'room' => $s->room?->name,
                    'status' => $s->status,
                    'effective' => SessionRecap::fmt($s->effectiveSecondsNow()),
                    'pomodoros' => $s->completed_pomodoros,
                    'focus_percent' => $recap['focus_percent'],
                    'recap_url' => route('sessions.recap', $s),
                ];
            })->values()->all(),
            'activities' => $activities->map(fn ($a) => [
                'time' => $a->occurred_at?->translatedFormat('d M H:i:s'),
                'type' => $a->event_type,
                'details' => $a->details,
            ])->values()->all(),
        ];
    }
}
