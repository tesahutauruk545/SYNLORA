<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingEvent extends Model
{
    /** Event yang boleh dikirim browser. */
    public const CLIENT_EVENTS = [
        'TAB_HIDDEN', 'TAB_VISIBLE', 'FACE_LOST', 'FACE_FOUND',
        'CAMERA_GRANTED', 'CAMERA_DENIED', 'CAMERA_ERROR',
        'MONITOR_START', 'MONITOR_STOP', 'JITSI_JOINED',
    ];

    protected $fillable = ['meeting_id', 'attendance_id', 'user_id', 'event_type', 'details', 'occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
