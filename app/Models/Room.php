<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    /** Karakter kode room: tanpa 0/O/1/I agar mudah dibaca dan diketik. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'code',
        'name',
        'owner_id',
        'target_minutes',
        'max_members',
        'strict_mode',
        'ai_policy',
    ];

    protected $casts = [
        'target_minutes' => 'integer',
        'strict_mode' => 'boolean',
    ];

    public function isFull(): bool
    {
        return $this->members()->count() >= (int) ($this->max_members ?: config('synlora.room_max_members', 60));
    }

    /** Route model binding memakai kode room, bukan id. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'room_members')
                    ->withPivot('role', 'focus_status', 'last_active')
                    ->withTimestamps();
    }

    public function sessions()
    {
        return $this->hasMany(StudySession::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function materials()
    {
        return $this->hasMany(Material::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $this->owner_id === (int) $user->id;
    }

    /**
     * Hak monitoring: hanya DOSEN yang menjadi anggota room ini (sebagai owner atau karena bergabung).
     * Mahasiswa, termasuk owner room, tidak pernah mendapat hak ini.
     */
    public function isMonitoredBy(?User $user): bool
    {
        return $user !== null && $user->isDosen() && $this->hasMember($user);
    }

    public function hasMember(?User $user): bool
    {
        return $user !== null && $this->members()->where('users.id', $user->id)->exists();
    }

    public static function generateUniqueCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $max)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }

    protected static function booted()
    {
        static::creating(function ($room) {
            if (empty($room->code)) {
                $room->code = static::generateUniqueCode();
            }
        });
    }
}
