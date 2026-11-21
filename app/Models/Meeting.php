<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Meeting extends Model
{
    public const LIVE = 'LIVE';
    public const ENDED = 'ENDED';

    protected $fillable = [
        'room_id', 'started_by', 'jitsi_name', 'status',
        'started_at', 'ended_at', 'end_reason', 'peak_participants',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'peak_participants' => 'integer',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function starter()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function attendances()
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    public function focusLogs()
    {
        return $this->hasMany(MeetingFocusLog::class);
    }

    public function events()
    {
        return $this->hasMany(MeetingEvent::class);
    }

    public function isLive(): bool
    {
        return $this->status === self::LIVE;
    }

    /** Nama ruang Jitsi: acak, unik per meeting, hanya diberikan ke anggota room yang berwenang. */
    public static function makeJitsiName(): string
    {
        do {
            $name = 'synlora-'.Str::lower(Str::random(24));
        } while (static::where('jitsi_name', $name)->exists());

        return $name;
    }
}
