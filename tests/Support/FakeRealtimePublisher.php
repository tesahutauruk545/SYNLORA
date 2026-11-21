<?php

namespace Tests\Support;

use App\Realtime\RealtimePublisher;

/** Merekam semua pesan yang akan dikirim ke daemon WebSocket, untuk diperiksa di test. */
class FakeRealtimePublisher implements RealtimePublisher
{
    public array $sent = [];

    public function publish(int $roomId, array $to, string $event, array $data): bool
    {
        $this->sent[] = compact('roomId', 'to', 'event', 'data');

        return true;
    }

    public function of(string $event): array
    {
        return array_values(array_filter($this->sent, fn ($m) => $m['event'] === $event));
    }
}
