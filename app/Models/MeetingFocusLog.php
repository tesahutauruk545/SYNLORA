<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingFocusLog extends Model
{
    public const STATUSES = ['HIJAU', 'MERAH', 'ABU'];

    protected $fillable = ['meeting_id', 'attendance_id', 'user_id', 'status', 'reason', 'occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function attendance()
    {
        return $this->belongsTo(MeetingAttendance::class, 'attendance_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
