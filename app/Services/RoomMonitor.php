<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomMonitorAlert;
use App\Models\RoomMonitorState;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pemantauan per room yang TERPISAH dari Pomodoro dan tugas. Klien hanya melaporkan JENIS EVENT; server
 * menurunkan keadaan, menghitung durasi (tab) dari jam server, menolak event wajah/HP bila kamera tidak aktif,
 * dan mencatat peringatan hanya saat keadaan BERUBAH (tanpa spam per frame) dengan cooldown notifikasi.
 *
 * Semua status hanyalah INDIKATOR; kegagalan teknis (kamera/model) dibedakan dari dugaan pelanggaran.
 */
class RoomMonitor
{
    public const EVENTS = [
        'CAMERA_ON', 'CAMERA_OFF', 'CAMERA_LOST', 'CAMERA_DENIED', 'CAMERA_ERROR',
        'FACE_FACING', 'FACE_NOT_FACING', 'FACE_TURN_LEFT', 'FACE_TURN_RIGHT', 'FACE_NOT_FOUND',
        'PHONE_DETECTED', 'PHONE_CLEARED',
        'TAB_LEFT', 'TAB_RETURNED', 'HEARTBEAT', 'MONITOR_STOP',
    ];

    /** jenis peringatan -> [judul untuk dosen, kelompok pemulihan] */
    public const ALERTS = [
        'not_facing' => ['tidak menghadap monitor', 'face'],
        'turn_left' => ['menoleh ke kiri', 'face'],
        'turn_right' => ['menoleh ke kanan', 'face'],
        'face_not_found' => ['wajah tidak terdeteksi', 'face'],
        'phone_vertical' => ['handphone terdeteksi (vertikal)', 'phone'],
        'phone_horizontal' => ['handphone terdeteksi (horizontal)', 'phone'],
        'tab_left' => ['meninggalkan tab SYNLORA', 'tab'],
        'camera_lost' => ['kamera terputus', 'camera'],
    ];

    public static function stateFor(int $userId, int $roomId): RoomMonitorState
    {
        return RoomMonitorState::firstOrCreate(['user_id' => $userId, 'room_id' => $roomId]);
    }

    /** @return array{ok:bool, ignored?:string} */
    public static function ingest(User $user, Room $room, string $type, array $d = []): array
    {
        $now = now();

        return DB::transaction(function () use ($user, $room, $type, $d, $now) {
            $st = RoomMonitorState::where(['user_id' => $user->id, 'room_id' => $room->id])->lockForUpdate()->first()
                ?? self::stateFor($user->id, $room->id);
            $st = RoomMonitorState::whereKey($st->id)->lockForUpdate()->first();

            $camOn = $st->camera_state === 'on';
            $isFaceOrPhone = str_starts_with($type, 'FACE_') || str_starts_with($type, 'PHONE_');
            if ($isFaceOrPhone && ! $camOn) {
                // Kamera tidak aktif: sinyal wajah/HP tidak sah (mencegah status palsu dari klien yang dimodifikasi).
                $st->forceFill(['last_seen_at' => $now])->save();

                return ['ok' => true, 'ignored' => 'camera_not_on'];
            }

            switch ($type) {
                case 'CAMERA_ON':
                    $st->camera_state = 'on';
                    $st->face_state = 'unknown';
                    self::resolve($st, 'camera', $now);
                    break;
                case 'CAMERA_OFF':
                case 'MONITOR_STOP':
                    $st->camera_state = 'off';
                    self::clearVision($st, $now);
                    if ($type === 'MONITOR_STOP' && $st->tab_state === 'away') {
                        $st->tab_state = 'visible';
                        $st->tab_left_at = null;
                    }
                    break;
                case 'CAMERA_DENIED':
                    $st->camera_state = 'denied';
                    self::clearVision($st, $now);
                    break;
                case 'CAMERA_ERROR':
                    $st->camera_state = 'error';
                    self::clearVision($st, $now);
                    break;
                case 'CAMERA_LOST':
                    if ($camOn) {
                        $st->camera_state = 'lost';
                        self::clearVision($st, $now);
                        self::alert($st, $room, $user, 'camera_lost', $now, $d);
                    }
                    break;
                case 'FACE_FACING':
                    $st->face_state = 'facing';
                    self::resolve($st, 'face', $now);
                    break;
                case 'FACE_NOT_FACING':
                    $st->face_state = 'not_facing';
                    self::alert($st, $room, $user, 'not_facing', $now, $d);
                    break;
                case 'FACE_TURN_LEFT':
                    $st->face_state = 'turned_left';
                    self::alert($st, $room, $user, 'turn_left', $now, $d);
                    break;
                case 'FACE_TURN_RIGHT':
                    $st->face_state = 'turned_right';
                    self::alert($st, $room, $user, 'turn_right', $now, $d);
                    break;
                case 'FACE_NOT_FOUND':
                    $st->face_state = 'not_found';
                    self::alert($st, $room, $user, 'face_not_found', $now, $d);
                    break;
                case 'PHONE_DETECTED':
                    $conf = isset($d['confidence']) ? max(0.0, min(1.0, (float) $d['confidence'])) : null;
                    if ($conf === null || $conf < (float) config('synlora.room_monitor.phone_min_confidence', 0.45)) {
                        return ['ok' => true, 'ignored' => 'below_threshold'];
                    }
                    $ori = in_array($d['orientation'] ?? null, ['vertical', 'horizontal'], true) ? $d['orientation'] : null;
                    $st->phone_state = 'detected';
                    $st->phone_orientation = $ori;
                    $st->phone_confidence = $conf;
                    self::alert($st, $room, $user, $ori === 'horizontal' ? 'phone_horizontal' : 'phone_vertical', $now, $d + ['confidence' => $conf, 'orientation' => $ori]);
                    break;
                case 'PHONE_CLEARED':
                    $st->phone_state = 'none';
                    $st->phone_orientation = null;
                    $st->phone_confidence = null;
                    self::resolve($st, 'phone', $now);
                    break;
                case 'TAB_LEFT':
                    if ($st->tab_state !== 'away') {
                        $st->tab_state = 'away';
                        $st->tab_left_at = $now;
                        $st->tab_away_count++;
                        self::alert($st, $room, $user, 'tab_left', $now, $d);
                    }
                    break;
                case 'TAB_RETURNED':
                    if ($st->tab_state === 'away') {
                        // Durasi dihitung dari jam SERVER, bukan angka kiriman klien.
                        $secs = $st->tab_left_at ? max(0, $now->getTimestamp() - $st->tab_left_at->getTimestamp()) : 0;
                        $st->tab_away_seconds += $secs;
                        $st->tab_state = 'visible';
                        $st->tab_left_at = null;
                        self::resolve($st, 'tab', $now);
                    }
                    break;
                case 'HEARTBEAT':
                    // Pembanding silang bila event TAB_* tidak sampai (koneksi putus).
                    if (array_key_exists('hidden', $d)) {
                        if ($d['hidden'] && $st->tab_state !== 'away') {
                            $st->tab_state = 'away';
                            $st->tab_left_at = $now;
                            $st->tab_away_count++;
                            self::alert($st, $room, $user, 'tab_left', $now, $d);
                        } elseif (! $d['hidden'] && $st->tab_state === 'away') {
                            $st->tab_away_seconds += $st->tab_left_at ? max(0, $now->getTimestamp() - $st->tab_left_at->getTimestamp()) : 0;
                            $st->tab_state = 'visible';
                            $st->tab_left_at = null;
                            self::resolve($st, 'tab', $now);
                        }
                    }
                    break;
            }

            if ($type !== 'HEARTBEAT') {
                $st->last_event_type = $type;
                $st->last_event_at = $now;
            }
            $st->last_seen_at = $now;
            $st->save();
            self::forget($room);

            return ['ok' => true];
        });
    }

    /** Tugas: diubah server saat mahasiswa membuka/mengumpulkan (tidak mempercayai klien). */
    public static function setTask(User $user, Room $room, ?int $assignmentId, string $state): void
    {
        if (! $user->isMahasiswa()) {
            return;
        }
        $st = self::stateFor($user->id, $room->id);
        $st->forceFill(['task_state' => $state, 'task_assignment_id' => $assignmentId, 'last_event_type' => 'TASK_'.strtoupper($state), 'last_event_at' => now()])->save();
        self::forget($room);
    }

    private static function clearVision(RoomMonitorState $st, $now): void
    {
        $st->face_state = 'unknown';
        $st->phone_state = 'none';
        $st->phone_orientation = null;
        $st->phone_confidence = null;
        self::resolve($st, 'face', $now);
        self::resolve($st, 'phone', $now);
    }

    /** Tutup peringatan terbuka satu kelompok (face|phone|tab|camera). */
    private static function resolve(RoomMonitorState $st, string $group, $now): void
    {
        $types = array_keys(array_filter(self::ALERTS, fn ($a) => $a[1] === $group));
        RoomMonitorAlert::where('room_id', $st->room_id)->where('user_id', $st->user_id)->whereIn('type', $types)->whereNull('resolved_at')->get()
            ->each(function (RoomMonitorAlert $a) use ($now) {
                $a->resolved_at = $now;
                $a->duration_seconds = max(0, $now->getTimestamp() - $a->occurred_at->getTimestamp());
                $a->save();
            });
    }

    private static function alert(RoomMonitorState $st, Room $room, User $user, string $type, $now, array $d): void
    {
        $group = self::ALERTS[$type][1];

        // Perubahan di dalam satu kelompok (mis. menoleh kiri -> kanan): tutup yang lama, buka yang baru.
        // Jenis yang SAMA sudah terbuka: tidak ada peringatan baru (dedup).
        $open = RoomMonitorAlert::where('room_id', $room->id)->where('user_id', $user->id)->whereNull('resolved_at')
            ->whereIn('type', array_keys(array_filter(self::ALERTS, fn ($a) => $a[1] === $group)))->get();
        if ($open->contains('type', $type)) {
            return;
        }
        self::resolve($st, $group, $now);

        $notify = Cache::add("rmalert:{$room->id}:{$user->id}:{$type}", 1, (int) config('synlora.room_monitor.alert_cooldown_seconds', 60));

        $alert = RoomMonitorAlert::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'type' => $type,
            'orientation' => $d['orientation'] ?? null,
            'confidence' => $d['confidence'] ?? null,
            'status_snapshot' => self::snapshotLabel($st),
            'notified' => $notify,
            'occurred_at' => $now,
        ]);

        if ($notify) {
            self::notifyMonitors($room, $user, $alert);
        }
    }

    private static function snapshotLabel(RoomMonitorState $st): string
    {
        return sprintf('kamera:%s wajah:%s hp:%s tab:%s tugas:%s', $st->camera_state, $st->face_state, $st->phone_state, $st->tab_state, $st->task_state);
    }

    /** Hanya DOSEN anggota room ini (hak monitoring RoomPolicy@monitor); mahasiswa/owner mahasiswa tidak pernah menerima. */
    private static function notifyMonitors(Room $room, User $student, RoomMonitorAlert $alert): void
    {
        $label = self::ALERTS[$alert->type][0];
        $ids = $room->members()->where('users.role', 'dosen')->pluck('users.id');
        $body = $student->name.' · '.$room->name.' · '.$alert->occurred_at->format('H:i:s').($alert->confidence ? ' · keyakinan '.round($alert->confidence * 100).'%' : '').' (indikator, bukan bukti)';

        foreach ($ids as $uid) {
            NotificationCenter::send((int) $uid, 'monitor', 'alert:'.$alert->id, $student->name.': '.$label, $body, [
                'student_id' => $student->id, 'student_name' => $student->name, 'room' => $room->name, 'room_code' => $room->code,
                'alert_type' => $alert->type, 'status' => $alert->status_snapshot, 'occurred_at' => $alert->occurred_at->toIso8601String(),
                'orientation' => $alert->orientation, 'confidence' => $alert->confidence,
            ], route('dosen.monitor'), $room->id);
        }
    }

    /** Kamera yang tadinya aktif tetapi klien lenyap lama => TERPUTUS (bukan pelanggaran, kegagalan teknis). */
    public static function sweepStale($roomIds): void
    {
        $limit = now()->subSeconds((int) config('synlora.room_monitor.camera_lost_seconds', 90));
        RoomMonitorState::whereIn('room_id', $roomIds)->where('camera_state', 'on')->where('last_seen_at', '<', $limit)->get()
            ->each(function (RoomMonitorState $st) {
                $room = Room::find($st->room_id);
                $user = User::find($st->user_id);
                if ($room && $user) {
                    self::ingest($user, $room, 'CAMERA_LOST', []);
                }
            });
    }

    /** Tingkat tampilan dashboard dari keadaan monitor yang masih segar; null bila pemantauan tidak aktif. */
    public static function describe(?RoomMonitorState $st, $now = null): ?array
    {
        $now ??= now();
        $stale = (int) config('synlora.room_monitor.stale_seconds', 45);
        $fresh = $st && $st->last_seen_at && $now->getTimestamp() - $st->last_seen_at->getTimestamp() <= $stale;
        if (! $st) {
            return null;
        }

        $level = 'HIJAU';
        $why = 'Menghadap monitor';
        if (! $fresh) {
            $level = null;
            $why = 'Pemantauan tidak aktif';
        } elseif ($st->camera_state !== 'on') {
            $level = $st->camera_state === 'lost' ? 'ABU' : null;
            $why = ['off' => 'Kamera tidak aktif', 'lost' => 'Kamera terputus', 'denied' => 'Izin kamera ditolak', 'error' => 'Kamera bermasalah'][$st->camera_state] ?? 'Kamera tidak aktif';
        } elseif ($st->phone_state === 'detected') {
            $level = 'ABU';
            $why = 'Handphone terdeteksi'.($st->phone_orientation ? ' ('.($st->phone_orientation === 'vertical' ? 'vertikal' : 'horizontal').')' : '');
        } elseif ($st->face_state === 'not_found') {
            $level = 'ABU';
            $why = 'Wajah tidak terdeteksi';
        } elseif (in_array($st->face_state, ['not_facing', 'turned_left', 'turned_right'], true)) {
            $level = 'KUNING';
            $why = ['not_facing' => 'Tidak menghadap monitor', 'turned_left' => 'Menoleh ke kiri', 'turned_right' => 'Menoleh ke kanan'][$st->face_state];
        } elseif ($st->face_state === 'unknown') {
            $why = 'Kamera aktif, menunggu deteksi';
        }

        $tabAway = $fresh && $st->tab_state === 'away';

        return [
            'active' => (bool) $fresh,
            'level' => $level,
            'reason' => $why,
            'camera' => $st->camera_state,
            'face' => $st->face_state,
            'phone' => $st->phone_state,
            'phone_orientation' => $st->phone_orientation,
            'phone_confidence' => $st->phone_confidence,
            'tab' => $tabAway ? 'away' : 'visible',
            'tab_away_count' => $st->tab_away_count,
            'tab_away_seconds' => $st->tab_away_seconds + ($tabAway && $st->tab_left_at ? max(0, $now->getTimestamp() - $st->tab_left_at->getTimestamp()) : 0),
            'task' => $st->task_state,
            'last_event' => $st->last_event_type,
            'last_event_at' => $st->last_event_at?->toIso8601String(),
            'last_event_human' => $st->last_event_at?->diffForHumans(),
        ];
    }

    public static function forget(Room $room): void
    {
        LecturerMonitor::forgetRoom($room);
    }
}
