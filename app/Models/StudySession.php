<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Satu sesi belajar (Pomodoro). Waktu dihitung oleh SERVER, bukan oleh browser:
 * browser hanya menampilkan hitung mundur dari data server, sehingga timer tetap
 * benar walau halaman di-refresh, ditutup, atau dibuka dari perangkat lain.
 *
 * Status : ACTIVE (berjalan) | PAUSED (dijeda) | ENDED (selesai)
 * Fase   : FOCUS | BREAK  (bergantian otomatis selama sesi ACTIVE)
 */
class StudySession extends Model
{
    use HasFactory;

    public const ACTIVE = 'ACTIVE';
    public const PAUSED = 'PAUSED';
    public const ENDED = 'ENDED';

    public const FOCUS = 'FOCUS';
    public const BREAK = 'BREAK';

    /**
     * Jika browser tidak mengirim detak (heartbeat) selama ini, sesi dianggap
     * ditinggalkan dan ditutup pada waktu detak terakhir. Mencegah "waktu belajar"
     * bertambah terus ketika pengguna sudah menutup halaman.
     */
    public const HEARTBEAT_GRACE_SECONDS = 900;

    protected $fillable = [
        'user_id',
        'room_id',
        'focus_duration_minutes',
        'break_duration_minutes',
        'started_at',
        'ended_at',
        'total_effective_seconds',
        'completed_pomodoros',
        'status',
        'phase',
        'phase_started_at',
        'paused_at',
        'last_heartbeat_at',
        'end_reason',
        'live_status',
        'live_source',
        'live_reason',
        'live_event',
        'live_changed_at',
        'camera_on',
        'camera_state',
        'mode',
        'study_goal',
        'consented_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'phase_started_at' => 'datetime',
        'paused_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'live_changed_at' => 'datetime',
        'camera_on' => 'boolean',
        'consented_at' => 'datetime',
        'reflected_at' => 'datetime',
        'heartbeat_lost_count' => 'integer',
        'multi_device_count' => 'integer',
        'multi_tab_count' => 'integer',
        'camera_issue_count' => 'integer',
        'focus_duration_minutes' => 'integer',
        'break_duration_minutes' => 'integer',
        'total_effective_seconds' => 'integer',
        'completed_pomodoros' => 'integer',
    ];

    // ---------------------------------------------------------------- relasi

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function focusLogs()
    {
        return $this->hasMany(FocusLog::class, 'session_id');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class, 'session_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::ACTIVE, self::PAUSED]);
    }

    // --------------------------------------------------------------- helper

    public function isOpen(): bool
    {
        return in_array($this->status, [self::ACTIVE, self::PAUSED], true);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isPaused(): bool
    {
        return $this->status === self::PAUSED;
    }

    public function phaseSeconds(?string $phase = null): int
    {
        $phase ??= $this->phase;

        return ($phase === self::BREAK ? $this->break_duration_minutes : $this->focus_duration_minutes) * 60;
    }

    /** Detik yang sudah berlalu di fase saat ini pada waktu $at (jeda tidak dihitung). */
    public function phaseElapsed(Carbon $at): int
    {
        $start = $this->phase_started_at ?? $this->started_at;
        $until = ($this->isPaused() && $this->paused_at) ? $this->paused_at : $at;

        return max(0, $until->getTimestamp() - $start->getTimestamp());
    }

    public function remainingSeconds(?Carbon $at = null): int
    {
        $at ??= now();

        return max(0, $this->phaseSeconds() - $this->phaseElapsed($at));
    }

    /** Epoch (detik) kapan fase berakhir; null bila sedang dijeda / sudah selesai. */
    public function phaseEndsAt(): ?int
    {
        if (! $this->isActive()) {
            return null;
        }

        return ($this->phase_started_at ?? $this->started_at)->getTimestamp() + $this->phaseSeconds();
    }

    /** Total detik fokus efektif, termasuk fase fokus yang sedang berjalan. */
    public function effectiveSecondsNow(): int
    {
        $total = (int) $this->total_effective_seconds;

        if ($this->isOpen() && $this->phase === self::FOCUS) {
            $total += min($this->phaseElapsed(now()), $this->phaseSeconds());
        }

        return $total;
    }

    /**
     * Apakah sesi perlu disinkronkan (fase habis / detak berhenti)? Pemeriksaan murni tanpa menulis ke
     * database, supaya polling (presence, dashboard dosen) tidak melakukan UPDATE yang tidak perlu.
     */
    public function needsSync(?Carbon $at = null): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        $at ??= now();

        if ($this->last_heartbeat_at
            && $at->getTimestamp() - $this->last_heartbeat_at->getTimestamp() > self::HEARTBEAT_GRACE_SECONDS) {
            return true;
        }

        if (! $this->isActive()) {
            return false;
        }

        $start = $this->phase_started_at ?? $this->started_at;

        return $start->getTimestamp() + $this->phaseSeconds() <= $at->getTimestamp();
    }

    /**
     * Status fokus yang DITAMPILKAN (dashboard dosen / presence). Menggabungkan status terakhir yang
     * dilaporkan browser dengan keadaan sesi di server:
     *  - sesi selesai / dijeda / istirahat            -> ABU
     *  - browser berhenti mengirim detak (tab ditutup) -> ABU "terputus"
     *  - selain itu                                    -> status terakhir dari browser
     *
     * @return array{0:string,1:string,2:?string} [status, alasan, sumber]
     */
    public function displayFocus(?Carbon $at = null): array
    {
        $at ??= now();

        if (! $this->isOpen()) {
            return ['ABU', 'Sesi tidak aktif', 'system'];
        }
        if ($this->isPaused()) {
            return ['ABU', 'Sesi dijeda', 'system'];
        }
        if ($this->phase === self::BREAK) {
            return ['ABU', 'Sedang istirahat', 'system'];
        }

        $stale = (int) config('synlora.focus.stale_seconds', 60);
        if ($this->last_heartbeat_at && $at->getTimestamp() - $this->last_heartbeat_at->getTimestamp() > $stale) {
            return ['DISCONNECTED', 'Koneksi terputus (browser tidak mengirim data)', 'server'];
        }

        if (! $this->live_status) {
            return ['UNKNOWN', 'Pemantauan belum dimulai di browser', 'server'];
        }

        // Eskalasi dihitung SERVER: tab yang ditinggalkan melewati batas menjadi MERAH walau klien tidak melapor.
        if ($this->live_event === 'TAB_SWITCH_OUT' && $this->live_changed_at) {
            $away = $at->getTimestamp() - $this->live_changed_at->getTimestamp();
            if ($away >= (int) config('synlora.focus.hidden_grace_seconds', 10)) {
                return ['MERAH', "Tab/aplikasi SYNLORA ditinggalkan {$away} detik", 'browser'];
            }
        }

        return [$this->live_status, $this->live_reason ?? '', $this->live_source];
    }

    /** Keadaan kamera untuk ditampilkan: CAMERA_ON atau CAMERA_OFF (+ alasan). */
    public function cameraInfo(): array
    {
        $state = $this->camera_state ?: 'off';

        return [
            'camera' => $state === 'on' ? 'CAMERA_ON' : 'CAMERA_OFF',
            'camera_state' => $state,
            'camera_label' => match ($state) {
                'on' => 'Kamera aktif',
                'denied' => 'Kamera: izin ditolak',
                'unavailable' => 'Kamera: tidak tersedia',
                'error' => 'Kamera: rusak/dipakai aplikasi lain',
                'model_failed' => 'Kamera: model wajah gagal dimuat',
                'interrupted' => 'Kamera: terputus di tengah sesi',
                default => 'Kamera tidak dipakai',
            },
        ];
    }

    // ------------------------------------------------------ logika Pomodoro

    /**
     * Membuat sesi baru. Satu pengguna hanya boleh punya satu sesi terbuka, jadi
     * sesi lama (di room mana pun / solo) ditutup lebih dulu.
     */
    public static function begin(User $user, ?Room $room, int $focusMinutes, int $breakMinutes, array $extra = []): self
    {
        // Dikunci per PENGGUNA (baris users, kunci primer: tanpa gap lock) agar dua permintaan "mulai" dari
        // pengguna yang sama tidak membuat dua sesi, tanpa saling deadlock dengan pengguna lain.
        // Bila MySQL tetap melaporkan deadlock, transaksi diulang otomatis hingga 5 kali.
        return DB::transaction(function () use ($user, $room, $focusMinutes, $breakMinutes, $extra) {
            $now = now();

            DB::table('users')->where('id', $user->id)->lockForUpdate()->value('id');

            static::where('user_id', $user->id)->open()->get()
                ->each(fn (self $old) => $old->sync()->isOpen() ? $old->finalize($now, 'replaced') : null);

            $session = static::create([
                'user_id' => $user->id,
                'room_id' => $room?->id,
                'focus_duration_minutes' => $focusMinutes,
                'break_duration_minutes' => $breakMinutes,
                'started_at' => $now,
                'phase_started_at' => $now,
                'last_heartbeat_at' => $now,
                'phase' => self::FOCUS,
                'status' => self::ACTIVE,
                'total_effective_seconds' => 0,
                'completed_pomodoros' => 0,
                // Mode ditentukan SERVER dari kebijakan room, bukan dari klien.
                'mode' => $room && $room->strict_mode ? 'strict' : 'standard',
                'study_goal' => isset($extra['study_goal']) ? mb_substr(trim((string) $extra['study_goal']), 0, 160) ?: null : null,
                'consented_at' => $extra['consented_at'] ?? null,
            ]);

            $session->logEvent('SESSION_START', $room
                ? "Sesi belajar dimulai ({$focusMinutes} menit fokus / {$breakMinutes} menit istirahat)"
                : "Sesi Pomodoro solo dimulai ({$focusMinutes}/{$breakMinutes} menit)", $now);

            return $session;
        }, 5);
    }

    /**
     * Menyamakan keadaan sesi dengan waktu server: pindah fase fokus <-> istirahat
     * bila waktunya habis, atau menutup sesi yang ditinggalkan. Aman dipanggil berulang.
     */
    public function sync(): self
    {
        if (! $this->isOpen()) {
            return $this;
        }

        $now = now();

        if ($this->last_heartbeat_at
            && $now->getTimestamp() - $this->last_heartbeat_at->getTimestamp() > self::HEARTBEAT_GRACE_SECONDS) {
            $cut = $this->last_heartbeat_at;
            $this->advanceTo($cut);
            $this->finalize($cut, 'abandoned');

            return $this;
        }

        $this->advanceTo($now);

        return $this;
    }

    protected function advanceTo(Carbon $at): void
    {
        if (! $this->isActive()) {
            return;
        }

        $changed = false;

        for ($i = 0; $i < 1000; $i++) {
            $start = $this->phase_started_at ?? $this->started_at;
            $end = $start->copy()->addSeconds($this->phaseSeconds());

            if ($end->gt($at)) {
                break;
            }

            if ($this->phase === self::FOCUS) {
                $this->total_effective_seconds += $this->phaseSeconds();
                $this->completed_pomodoros++;
                $this->phase = self::BREAK;
                $this->phase_started_at = $end;
                $this->logEvent('PHASE_BREAK', 'Fokus selesai, waktunya istirahat', $end);
                $this->logFocus('ABU', 'Istirahat', $end, 'system');
            } else {
                $this->phase = self::FOCUS;
                $this->phase_started_at = $end;
                $this->logEvent('PHASE_FOCUS', 'Istirahat selesai, kembali fokus', $end);
            }

            $changed = true;
        }

        if ($changed) {
            $this->save();
        }
    }

    public function pause(): self
    {
        $this->sync();

        if (! $this->isActive()) {
            return $this;
        }

        $now = now();
        $this->status = self::PAUSED;
        $this->paused_at = $now;
        $this->last_heartbeat_at = $now;
        $this->save();

        $this->logEvent('SESSION_PAUSE', 'Sesi dijeda', $now);
        $this->logFocus('ABU', 'Sesi dijeda', $now, 'system');

        return $this;
    }

    public function resume(): self
    {
        $this->sync();

        if (! $this->isPaused()) {
            return $this;
        }

        $now = now();
        $pausedFor = max(0, $now->getTimestamp() - ($this->paused_at ?? $now)->getTimestamp());

        // Geser awal fase sebesar lama jeda, sehingga jeda tidak memakan waktu fase.
        $this->phase_started_at = ($this->phase_started_at ?? $this->started_at)->copy()->addSeconds($pausedFor);
        $this->paused_at = null;
        $this->status = self::ACTIVE;
        $this->last_heartbeat_at = $now;
        $this->save();

        $this->logEvent('SESSION_RESUME', 'Sesi dilanjutkan', $now);

        return $this;
    }

    public function finish(string $reason = 'manual'): self
    {
        $this->sync();

        if ($this->isOpen()) {
            $this->finalize(now(), $reason);
        }

        return $this;
    }

    public function heartbeat(): void
    {
        if ($this->isOpen()) {
            $this->forceFill(['last_heartbeat_at' => now()])->saveQuietly();
        }
    }

    /** Menutup sesi pada waktu $at dan mengkreditkan sisa waktu fokus yang berjalan. */
    protected function finalize(Carbon $at, string $reason): void
    {
        if ($this->phase === self::FOCUS) {
            $elapsed = $this->phaseElapsed($at);
            $this->total_effective_seconds += max(0, min($elapsed, $this->phaseSeconds()));
        }

        $this->status = self::ENDED;
        $this->ended_at = $at->lt($this->started_at) ? $this->started_at : $at;
        $this->paused_at = null;
        $this->end_reason = $reason;
        $this->save();

        $label = match ($reason) {
            'abandoned' => 'Sesi ditutup otomatis (halaman tidak aktif)',
            'replaced' => 'Sesi diakhiri karena sesi baru dimulai',
            'left_room' => 'Sesi diakhiri karena keluar dari room',
            default => 'Sesi belajar diakhiri',
        };

        $this->logEvent('SESSION_END', $label, $this->ended_at);
        $this->logFocus('ABU', $label, $this->ended_at, 'system');
    }

    /** Dipanggil saat pengguna meninggalkan room / dikeluarkan. */
    public function closeBecauseLeftRoom(): void
    {
        $this->sync();
        if ($this->isOpen()) {
            $this->finalize(now(), 'left_room');
        }
    }

    // ------------------------------------------------------------- pencatatan

    public function logEvent(string $type, ?string $details = null, ?Carbon $at = null, ?int $duration = null): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $this->user_id,
            'room_id' => $this->room_id,
            'session_id' => $this->id,
            'event_type' => $type,
            'details' => $details,
            'duration_seconds' => $duration,
            'occurred_at' => $at ?? now(),
        ]);
    }

    /**
     * Mencatat perubahan status fokus. Tidak menulis ulang jika status sama dengan
     * catatan terakhir, sehingga riwayat hanya berisi perubahan yang nyata.
     */
    public function logFocus(string $status, string $reason, ?Carbon $at = null, ?string $source = null, ?string $event = null): ?FocusLog
    {
        $at ??= now();

        $last = $this->focusLogs()->orderByDesc('occurred_at')->orderByDesc('id')->first();
        if ($last && $last->status === $status && ($source === null || $last->source === $source)) {
            // Status sama: hanya perbarui penanda event (mis. KUNING -> KUNING karena sebab berbeda).
            if ($event !== null && $this->live_event !== $event) {
                DB::table('study_sessions')->where('id', $this->id)->update(['live_event' => $event, 'live_changed_at' => $at]);
                $this->forceFill(['live_event' => $event, 'live_changed_at' => $at])->syncOriginal();
            }

            return null;
        }
        if (! $last && $status === 'ABU') {
            return null; // belum ada pemantauan; tidak perlu mencatat "tidak aktif"
        }

        $log = $this->focusLogs()->create([
            'status' => $status,
            'source' => $source,
            'reason' => mb_substr($reason, 0, 255),
            'occurred_at' => $at,
        ]);

        // Salinan status terakhir untuk dashboard (tanpa query focus_logs per mahasiswa).
        DB::table('study_sessions')->where('id', $this->id)->update([
            'live_status' => $status,
            'live_source' => $source,
            'live_reason' => mb_substr($reason, 0, 255),
            'live_event' => $event,
            'live_changed_at' => $at,
        ]);
        $this->forceFill(['live_status' => $status, 'live_source' => $source, 'live_reason' => mb_substr($reason, 0, 255), 'live_event' => $event, 'live_changed_at' => $at])->syncOriginal();

        if ($this->room_id) {
            DB::table('room_members')
                ->where('room_id', $this->room_id)
                ->where('user_id', $this->user_id)
                ->update(['focus_status' => $status, 'last_active' => $at, 'updated_at' => now()]);
        }

        return $log;
    }
}
