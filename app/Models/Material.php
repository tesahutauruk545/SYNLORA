<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $fillable = [
        'room_id', 'user_id', 'title', 'description',
        'file_path', 'original_name', 'mime_type', 'size_bytes',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getHumanSizeAttribute(): string
    {
        $b = (int) $this->size_bytes;
        if ($b >= 1048576) {
            return number_format($b / 1048576, 1, ',', '.').' MB';
        }

        return max(1, (int) round($b / 1024)).' KB';
    }
}
