<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomMonitorAlert extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime', 'resolved_at' => 'datetime', 'confidence' => 'float', 'notified' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
