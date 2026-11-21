<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FocusLog extends Model
{
    use HasFactory;

    public const STATUSES = ['HIJAU', 'KUNING', 'MERAH', 'ABU', 'DISCONNECTED', 'UNKNOWN'];

    public const SOURCES = ['browser', 'face', 'orientation', 'system', 'server'];

    protected $fillable = [
        'session_id',
        'status',
        'source',
        'reason',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(StudySession::class);
    }
}
