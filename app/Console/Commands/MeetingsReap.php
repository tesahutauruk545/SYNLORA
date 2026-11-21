<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Services\MeetingService;
use Illuminate\Console\Command;

class MeetingsReap extends Command
{
    protected $signature = 'synlora:meetings:reap';

    protected $description = 'Menutup kehadiran peserta yang terputus dan mengakhiri meeting yang sudah kosong';

    public function handle(MeetingService $meetings): int
    {
        $count = 0;
        Meeting::where('status', Meeting::LIVE)->get()->each(function (Meeting $m) use ($meetings, &$count) {
            $meetings->reap($m);
            if (! $m->fresh()->isLive()) {
                $count++;
            }
        });

        $this->info("Meeting diakhiri: {$count}");

        return self::SUCCESS;
    }
}
