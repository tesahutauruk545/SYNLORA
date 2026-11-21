<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'nim',
        'kelas',
        'daily_goal_minutes',
        'password',
    ];

    // 'role' sengaja TIDAK mass-assignable: peran hanya ditetapkan eksplisit oleh kode server.

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'daily_goal_minutes' => 'integer',
    ];

    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'room_members')
                    ->withPivot('role', 'focus_status', 'last_active')
                    ->withTimestamps();
    }

    public function ownedRooms()
    {
        return $this->hasMany(Room::class, 'owner_id');
    }

    public function studySessions()
    {
        return $this->hasMany(StudySession::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function isDosen(): bool
    {
        return $this->role === 'dosen';
    }

    public function isMahasiswa(): bool
    {
        return $this->role === 'mahasiswa';
    }
}
