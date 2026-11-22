<?php

namespace App\Services;

use App\Models\Room;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Daftar anggota room dengan status kehadiran.
 *
 *  - Semua anggota melihat "presence" netral (Sedang belajar / Istirahat / Dijeda / Tidak sedang belajar).
 *  - Status fokus (hijau/kuning/merah/abu) dan waktu aktif terakhir HANYA terlihat oleh pemilik datanya
 *    sendiri dan oleh DOSEN anggota room (untuk anggota berperan mahasiswa). Mahasiswa lain, termasuk
 *    mahasiswa yang menjadi owner room, tidak melihatnya.
 *
 * Optimasi: hanya sesi yang benar-benar perlu disinkronkan yang ditulis ke database; sisanya dibaca saja.
 */
class RoomPresence
{
    public static function forRoom(Room $room, User $viewer): Collection
    {
        $viewerIsMonitor = $room->isMonitoredBy($viewer);

        // Bagian yang sama untuk semua penonton di-cache beberapa detik: puluhan anggota yang polling
        // bersamaan tidak memicu puluhan perhitungan identik. Penyaringan per penonton dilakukan setelahnya.
        $base = Cache::remember('presence:'.$room->id, 2, fn () => self::base($room));

        return collect($base)->map(function (array $row) use ($viewerIsMonitor, $viewer) {
            $isMe = (int) $row['id'] === (int) $viewer->id;
            $maySeeFocus = $isMe || ($viewerIsMonitor && $row['role'] === 'mahasiswa');

            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'is_owner' => $row['is_owner'],
                'is_me' => $isMe,
                'presence' => $row['presence'],
                'presence_label' => $row['presence_label'],
                'focus_status' => $maySeeFocus ? $row['focus_status'] : null,
                'focus_source' => $maySeeFocus ? $row['focus_source'] : null,
                'last_active' => $maySeeFocus ? $row['last_active'] : null,
            ];
        });
    }

    private static function base(Room $room): array
    {
        $open = $room->sessions()->open()->get()
            ->each(function (StudySession $s) {
                if ($s->needsSync()) {
                    $s->sync();
                }
            })
            ->filter(fn (StudySession $s) => $s->isOpen())
            ->keyBy('user_id');

        $members = $room->members()->get(['users.id', 'users.name', 'users.role'])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $seen = OnlinePresence::lastSeen($members->pluck('id'));
        $nowTs = now()->getTimestamp();

        return $members->map(function (User $member) use ($open, $room, $seen, $nowTs) {
            /** @var StudySession|null $s */
            $s = $open->get($member->id);

            // ONLINE (membuka website) dipisah dari status belajar: tanpa sesi Pomodoro tetap bisa online.
            $online = OnlinePresence::isOnline($seen[$member->id] ?? null, $s, $nowTs);
            $state = OnlinePresence::state($online, $s);
            $label = match ($state) {
                'offline' => 'Offline',
                'online' => 'Online',
                'jeda' => 'Sesi dijeda',
                'istirahat' => 'Istirahat',
                default => 'Sedang belajar',
            };

            $focus = $s && $online ? $s->displayFocus() : ['ABU', 'Tidak ada sesi', 'system'];

            return [
                'id' => $member->id,
                'name' => $member->name,
                'role' => $member->role,
                'is_owner' => $room->isOwnedBy($member),
                'presence' => $state,
                'presence_label' => $label,
                'focus_status' => $focus[0],
                'focus_source' => $focus[2],
                'last_active' => isset($seen[$member->id]) ? Carbon::createFromTimestamp($seen[$member->id])->diffForHumans() : ($s && $s->last_heartbeat_at ? $s->last_heartbeat_at->diffForHumans() : null),
            ];
        })->all();
    }
}
