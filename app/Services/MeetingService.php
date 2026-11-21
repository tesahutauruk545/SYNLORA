<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingEvent;
use App\Models\MeetingFocusLog;
use App\Models\Room;
use App\Models\User;
use App\Realtime\RealtimePublisher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seluruh logika siklus hidup Virtual Meeting:
 *   masuk -> detak/status fokus -> keluar | terputus -> bergabung kembali -> berakhir.
 *
 * Meeting dimulai saat anggota pertama masuk dan berakhir ketika (a) tidak ada lagi
 * peserta yang hadir, (b) semua peserta terputus > abandon_seconds, atau (c) owner
 * mengakhirinya untuk semua. Monitoring fokus mengikuti kehadiran ini, bukan Pomodoro.
 */
class MeetingService
{
    public function __construct(protected RealtimePublisher $realtime)
    {
    }

    protected function staleSeconds(): int
    {
        return (int) config('synlora.meeting.stale_seconds');
    }

    protected function abandonSeconds(): int
    {
        return (int) config('synlora.meeting.abandon_seconds');
    }

    // ------------------------------------------------------------ query

    /** Meeting yang sedang berlangsung di room ini (setelah membersihkan peserta yang hilang). */
    public function live(Room $room): ?Meeting
    {
        $meeting = Meeting::where('room_id', $room->id)->where('status', Meeting::LIVE)->latest('id')->first();
        if (! $meeting) {
            return null;
        }

        $this->reap($meeting);

        return $meeting->isLive() ? $meeting : null;
    }

    /** Alasan berakhirnya meeting terakhir di room ini (owner_ended / all_left / abandoned), untuk pesan ke peserta. */
    public function lastEndReason(Room $room): ?string
    {
        return Meeting::where('room_id', $room->id)->where('status', Meeting::ENDED)->latest('id')->value('end_reason');
    }

    // ------------------------------------------------------- siklus hidup

    /**
     * Masuk ke meeting room. Membuat meeting baru bila belum ada yang berlangsung.
     *
     * @return array{0:Meeting,1:MeetingAttendance,2:bool,3:bool} [meeting, attendance, rejoined, started]
     */
    public function join(Room $room, User $user): array
    {
        $out = DB::transaction(function () use ($room, $user) {
            // Kunci baris room agar dua orang yang masuk bersamaan tidak membuat dua meeting.
            Room::whereKey($room->id)->lockForUpdate()->first();

            $meeting = $this->live($room);
            $started = false;

            if (! $meeting) {
                $now = now();
                $meeting = Meeting::create([
                    'room_id' => $room->id,
                    'started_by' => $user->id,
                    'jitsi_name' => Meeting::makeJitsiName(),
                    'status' => Meeting::LIVE,
                    'started_at' => $now,
                ]);
                $this->event($meeting, null, $user->id, 'MEETING_STARTED', 'Meeting dimulai', $now);
                $started = true;
            }

            // Sisa kehadiran lama pengguna ini (mis. refresh halaman) ditutup lebih dulu.
            $hadBefore = $meeting->attendances()->where('user_id', $user->id)->exists();
            $meeting->attendances()->where('user_id', $user->id)->whereNull('left_at')->lockForUpdate()->get()
                ->each(function (MeetingAttendance $old) use ($meeting) {
                    $stale = now()->getTimestamp() - $old->last_seen_at->getTimestamp() > $this->staleSeconds();
                    $this->closeAttendance($meeting, $old, $old->last_seen_at, $stale ? 'disconnected' : 'replaced');
                });

            $attendance = $this->openAttendance($meeting, $user->id, $hadBefore);

            return [$meeting, $attendance, $hadBefore, $started];
        });

        [$meeting, $attendance, $rejoined] = $out;
        $this->publishParticipant($room, $meeting, $user->id, $rejoined ? 'rejoined' : 'joined');

        return $out;
    }

    /**
     * Detak + status fokus dari browser. Menangani koneksi terputus: bila jeda sejak
     * detak terakhir melebihi stale_seconds, segmen lama ditutup pada detak terakhir
     * (celah tidak dihitung hadir) dan segmen baru dibuka sebagai "bergabung kembali".
     *
     * @return array{error?:string, attendance?:MeetingAttendance, meeting?:Meeting, rejoined?:bool}
     */
    public function signal(Room $room, User $user, string $status, ?string $reason, ?string $eventType = null, ?string $details = null): array
    {
        $result = DB::transaction(function () use ($room, $user, $status, $reason, $eventType, $details) {
            $meeting = $this->live($room);
            if (! $meeting) {
                return ['error' => 'meeting_ended'];
            }

            $now = now();
            $rejoined = false;

            $attendance = $meeting->attendances()->where('user_id', $user->id)->whereNull('left_at')->latest('id')->lockForUpdate()->first();

            if (! $attendance) {
                $attendance = $this->openAttendance($meeting, $user->id, true);
                $rejoined = true;
            } elseif ($now->getTimestamp() - $attendance->last_seen_at->getTimestamp() > $this->staleSeconds()) {
                $this->closeAttendance($meeting, $attendance, $attendance->last_seen_at, 'disconnected');
                $attendance = $this->openAttendance($meeting, $user->id, true);
                $rejoined = true;
            } else {
                $attendance->last_seen_at = $now;
            }

            if ($attendance->current_status !== $status) {
                $attendance->current_status = $status;
                $attendance->current_reason = $reason !== null ? mb_substr($reason, 0, 255) : null;
                $attendance->status_changed_at = $now;

                MeetingFocusLog::create([
                    'meeting_id' => $meeting->id,
                    'attendance_id' => $attendance->id,
                    'user_id' => $user->id,
                    'status' => $status,
                    'reason' => $attendance->current_reason,
                    'occurred_at' => $now,
                ]);
            } elseif ($reason !== null) {
                $attendance->current_reason = mb_substr($reason, 0, 255);
            }

            $attendance->save();

            if ($eventType) {
                $this->event($meeting, $attendance->id, $user->id, $eventType, $details ?? $reason, $now);
            }

            return ['meeting' => $meeting, 'attendance' => $attendance, 'rejoined' => $rejoined];
        });

        if (! isset($result['error'])) {
            $this->publishParticipant($room, $result['meeting'], $user->id, $result['rejoined'] ? 'rejoined' : 'status');
        }

        return $result;
    }

    /** Peserta keluar dengan sengaja. Meeting berakhir bila ia peserta terakhir. */
    public function leave(Room $room, User $user, string $reason = 'left', ?int $attendanceId = null): bool
    {
        $meeting = Meeting::where('room_id', $room->id)->where('status', Meeting::LIVE)->latest('id')->first();
        if (! $meeting) {
            return false;
        }

        DB::transaction(function () use ($meeting, $user, $reason, $attendanceId) {
            $meeting->attendances()->where('user_id', $user->id)->whereNull('left_at')
                ->when($attendanceId, fn ($q) => $q->where('id', $attendanceId))
                ->lockForUpdate()->get()
                ->each(fn (MeetingAttendance $a) => $this->closeAttendance($meeting, $a, now(), $reason));

            if ($meeting->attendances()->whereNull('left_at')->doesntExist()) {
                $this->finish($meeting, now(), 'all_left');
            }
        });

        $this->publishParticipant($room, $meeting, $user->id, 'left');

        if ($reason === 'removed') {
            $this->realtime->publish($room->id, ['users' => [$user->id]], 'meeting.removed', ['reason' => 'removed']);
        }

        return true;
    }

    /** Owner mengakhiri meeting untuk semua peserta. */
    public function end(Room $room, User $owner): bool
    {
        $meeting = Meeting::where('room_id', $room->id)->where('status', Meeting::LIVE)->latest('id')->first();
        if (! $meeting) {
            return false;
        }

        DB::transaction(function () use ($meeting, $owner) {
            $now = now();
            $meeting->attendances()->whereNull('left_at')->lockForUpdate()->get()
                ->each(fn (MeetingAttendance $a) => $this->closeAttendance($meeting, $a, $now, 'meeting_ended'));
            $this->event($meeting, null, $owner->id, 'MEETING_ENDED', 'Meeting diakhiri oleh owner untuk semua peserta', $now);
            $this->finish($meeting, $now, 'owner_ended', false);
        });

        $this->realtime->publish($room->id, ['all' => true], 'meeting.ended', ['reason' => 'owner_ended']);

        return true;
    }

    /**
     * Menutup kehadiran peserta yang tidak mengirim detak > abandon_seconds (pada waktu
     * detak terakhirnya), lalu mengakhiri meeting jika tidak ada lagi yang hadir.
     */
    public function reap(Meeting $meeting): void
    {
        if (! $meeting->isLive()) {
            return;
        }

        $cut = now()->subSeconds($this->abandonSeconds());
        $disconnected = false;

        $meeting->attendances()->whereNull('left_at')->get()->each(function (MeetingAttendance $a) use ($meeting, $cut, &$disconnected) {
            if ($a->last_seen_at->lt($cut)) {
                $this->closeAttendance($meeting, $a, $a->last_seen_at, 'disconnected');
                $disconnected = true;
            }
        });

        if ($meeting->attendances()->whereNull('left_at')->doesntExist()) {
            $last = $meeting->attendances()->max('left_at');
            $this->finish($meeting, $last ? Carbon::parse($last) : now(), $disconnected ? 'abandoned' : 'all_left');
        }
    }

    // ---------------------------------------------------------- internal

    protected function openAttendance(Meeting $meeting, int $userId, bool $rejoin): MeetingAttendance
    {
        $now = now();

        $attendance = MeetingAttendance::create([
            'meeting_id' => $meeting->id,
            'user_id' => $userId,
            'joined_at' => $now,
            'last_seen_at' => $now,
            'current_status' => 'ABU',
            'current_reason' => 'Menunggu pemantauan dimulai',
            'status_changed_at' => $now,
        ]);

        $this->event($meeting, $attendance->id, $userId, $rejoin ? 'REJOINED' : 'JOINED', $rejoin ? 'Bergabung kembali ke meeting' : 'Bergabung ke meeting', $now);

        $online = $meeting->attendances()->whereNull('left_at')->distinct()->count('user_id');
        if ($online > $meeting->peak_participants) {
            $meeting->update(['peak_participants' => $online]);
        }

        return $attendance;
    }

    protected function closeAttendance(Meeting $meeting, MeetingAttendance $a, Carbon $at, string $reason): void
    {
        if ($at->lt($a->joined_at)) {
            $at = $a->joined_at;
        }

        $a->forceFill(['left_at' => $at, 'leave_reason' => $reason])->save();

        $label = match ($reason) {
            'left' => ['LEFT', 'Keluar dari meeting'],
            'disconnected' => ['DISCONNECTED', 'Koneksi terputus'],
            'meeting_ended' => ['LEFT', 'Meeting diakhiri owner'],
            'removed' => ['LEFT', 'Dikeluarkan dari room'],
            default => null, // 'replaced' (refresh halaman) tidak dicatat sebagai keluar
        };

        if ($label) {
            $this->event($meeting, $a->id, $a->user_id, $label[0], $label[1], $at);
        }
    }

    protected function finish(Meeting $meeting, Carbon $at, string $reason, bool $logEvent = true): void
    {
        if (! $meeting->isLive()) {
            return;
        }

        $meeting->forceFill([
            'status' => Meeting::ENDED,
            'ended_at' => $at->lt($meeting->started_at) ? $meeting->started_at : $at,
            'end_reason' => $reason,
        ])->save();

        if ($logEvent) {
            $this->event($meeting, null, null, 'MEETING_ENDED', $reason === 'abandoned' ? 'Meeting berakhir (semua peserta terputus)' : 'Meeting berakhir (semua peserta keluar)', $meeting->ended_at);
        }
    }

    protected function event(Meeting $meeting, ?int $attendanceId, ?int $userId, string $type, ?string $details, ?Carbon $at = null): void
    {
        MeetingEvent::create([
            'meeting_id' => $meeting->id,
            'attendance_id' => $attendanceId,
            'user_id' => $userId,
            'event_type' => $type,
            'details' => $details !== null ? mb_substr($details, 0, 255) : null,
            'occurred_at' => $at ?? now(),
        ]);
    }

    // ----------------------------------------------------- snapshot / push

    /**
     * Baris peserta untuk UI. Owner mendapat semua peserta; anggota biasa hanya
     * dirinya sendiri (privasi diterapkan di sini, bukan di frontend).
     */
    public function participants(Meeting $meeting, Room $room, User $viewer): array
    {
        $isOwner = $room->isOwnedBy($viewer);

        return $this->rows($meeting, $room, $isOwner ? null : $viewer->id);
    }

    public function rows(Meeting $meeting, Room $room, ?int $onlyUser = null): array
    {
        $groups = $meeting->attendances()
            ->with('user:id,name')
            ->when($onlyUser, fn ($q) => $q->where('user_id', $onlyUser))
            ->orderBy('joined_at')->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $now = now()->getTimestamp();
        $rows = [];

        foreach ($groups as $userId => $segments) {
            /** @var \Illuminate\Support\Collection $segments */
            $latest = $segments->last();
            $open = $segments->first(fn (MeetingAttendance $a) => $a->isOpen());

            $connection = 'left';
            if ($open) {
                $connection = ($now - $open->last_seen_at->getTimestamp()) > $this->staleSeconds() ? 'disconnected' : 'online';
            }

            $status = 'ABU';
            $reason = $connection === 'disconnected' ? 'Koneksi terputus' : 'Sudah keluar dari meeting';
            if ($connection === 'online') {
                $status = $open->current_status;
                $reason = $open->current_reason;
            }

            $rows[] = [
                'user_id' => (int) $userId,
                'name' => $latest->user?->name ?? '—',
                'is_owner' => (int) $room->owner_id === (int) $userId,
                'status' => $status,
                'reason' => $reason,
                'connection' => $connection,
                'first_joined_at' => $segments->first()->joined_at->getTimestamp(),
                'attendance_seconds' => (int) $segments->sum(fn (MeetingAttendance $a) => $a->seconds()),
                'join_count' => $segments->count(),
                'last_seen' => ($open ?? $latest)->effectiveEnd()->getTimestamp(),
                'status_since' => optional($open?->status_changed_at)->getTimestamp(),
            ];
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    public function snapshot(Meeting $meeting, Room $room, User $viewer): array
    {
        return [
            'meeting' => [
                'id' => $meeting->id,
                'status' => $meeting->status,
                'started_at' => $meeting->started_at->getTimestamp(),
            ],
            'scope' => $room->isOwnedBy($viewer) ? 'room' : 'self',
            'participants' => $this->participants($meeting, $room, $viewer),
            'server_time' => now()->getTimestamp(),
        ];
    }

    protected function publishParticipant(Room $room, Meeting $meeting, int $userId, string $kind): void
    {
        $rows = $this->rows($meeting, $room, $userId);
        if (! $rows) {
            return;
        }

        // Owner menerima semua perubahan; peserta hanya menerima perubahan dirinya sendiri.
        $this->realtime->publish($room->id, ['owners' => true, 'users' => [$userId]], 'participant', [
            'kind' => $kind,
            'participant' => $rows[0],
            'server_time' => now()->getTimestamp(),
            'meeting_live' => $meeting->fresh()->isLive(),
        ]);
    }
}
