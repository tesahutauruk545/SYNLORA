<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomMonitorState extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tab_left_at' => 'datetime',
        'last_event_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'phone_confidence' => 'float',
        'tab_away_count' => 'integer',
        'tab_away_seconds' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
