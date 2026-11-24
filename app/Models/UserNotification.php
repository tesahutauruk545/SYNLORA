<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'room_id', 'type', 'dedupe_key', 'title', 'body', 'data', 'action_url', 'read_at'];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime', 'created_at' => 'datetime'];

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data ?? [],
            'url' => $this->action_url,
            'read' => $this->read_at !== null,
            'at' => $this->created_at?->toIso8601String(),
            'at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
