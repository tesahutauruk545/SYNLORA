<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu segmen kehadiran peserta (masuk -> keluar/terputus). Bergabung kembali = segmen baru. */
class MeetingAttendance extends Model
{
    protected $fillable = [
        'meeting_id', 'user_id', 'joined_at', 'left_at', 'last_seen_at',
        'leave_reason', 'current_status', 'current_reason', 'status_changed_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'status_changed_at' => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function focusLogs()
    {
        return $this->hasMany(MeetingFocusLog::class, 'attendance_id');
    }

    public function isOpen(): bool
    {
        return $this->left_at === null;
    }

    /** Waktu terakhir yang dapat dipertanggungjawabkan: saat keluar, atau detak terakhir bila masih terbuka. */
    public function effectiveEnd()
    {
        return $this->left_at ?? $this->last_seen_at;
    }

    public function seconds(): int
    {
        return max(0, $this->effectiveEnd()->getTimestamp() - $this->joined_at->getTimestamp());
    }
}
