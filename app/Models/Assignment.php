<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    protected $fillable = ['room_id', 'user_id', 'title', 'description', 'due_at', 'allow_late'];

    protected $casts = [
        'due_at' => 'datetime',
        'allow_late' => 'boolean',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function isPastDue(): bool
    {
        return $this->due_at !== null && now()->gt($this->due_at);
    }
}
