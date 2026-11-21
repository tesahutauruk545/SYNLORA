<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    /** Jenis event yang boleh dicatat. Dipakai juga untuk validasi di endpoint. */
    public const CLIENT_EVENTS = [
        'FACE_DETECTED',
        'CAMERA_DENIED',
        'CAMERA_UNAVAILABLE',
        'CAMERA_ERROR',
        'MODEL_LOAD_FAILED',
        'FACE_LOST_SHORT',
        'FACE_LOST_LONG',
        'TAB_SWITCH_OUT',
        'TAB_SWITCH_IN',
        'WINDOW_BLUR',
        'TAB_AWAY_LONG',
        'WINDOW_FOCUS',
        'ORIENTATION_CHANGE',
        'CAMERA_ON',
        'CAMERA_OFF',
        'MONITOR_ERROR',
        'MONITOR_START',
        'CAMERA_INTERRUPTED',
        'CAMERA_LOW_QUALITY',
        'CAMERA_QUALITY_OK',
        'POSE_AWAY',
        'POSE_FORWARD',
    ];

    public const SERVER_EVENTS = [
        'SESSION_START',
        'SESSION_PAUSE',
        'SESSION_RESUME',
        'SESSION_END',
        'PHASE_BREAK',
        'PHASE_FOCUS',
        'HEARTBEAT_LOST',
        'MULTI_TAB_DETECTED',
        'MULTI_DEVICE_DETECTED',
    ];

    protected $fillable = [
        'user_id',
        'room_id',
        'session_id',
        'event_type',
        'details',
        'duration_seconds',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function session()
    {
        return $this->belongsTo(StudySession::class);
    }
}
