<?php

namespace App\Realtime;

interface RealtimePublisher
{
    /**
     * Mengirim event ke klien WebSocket pada satu room.
     *
     * @param  array{owners?:bool, all?:bool, users?:int[]}  $to  penerima
     */
    public function publish(int $roomId, array $to, string $event, array $data): bool;
}
