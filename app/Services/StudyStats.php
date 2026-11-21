<?php

namespace App\Services;

use App\Models\FocusLog;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statistik dashboard, dihitung dari tabel study_sessions / focus_logs milik pengguna.
 * Tidak ada angka hardcode. Sebuah sesi dihitung pada HARI MULAINYA (started_at).
 */
class StudyStats
{
    public static function forUser(User $user): array
    {
        // Tutup sesi yang ditinggalkan & majukan fase supaya angka akurat.
        $open = StudySession::where('user_id', $user->id)->open()->get();
        $open->each(fn (StudySession $s) => $s->needsSync() ? $s->sync() : null);

        $base = StudySession::where('user_id', $user->id);

        $stored = (int) (clone $base)->sum('total_effective_seconds');
        $liveExtra = $open->filter->isOpen()->sum(fn (StudySession $s) => $s->effectiveSecondsNow() - (int) $s->total_effective_seconds);
        $totalSeconds = $stored + $liveExtra;

        $countable = fn ($q) => $q->where('total_effective_seconds', '>', 0)->orWhereIn('status', [StudySession::ACTIVE, StudySession::PAUSED]);

        $sessionCount = (clone $base)->where($countable)->count();
        $pomodoros = (int) (clone $base)->sum('completed_pomodoros');

        $recent = (clone $base)
            ->where('started_at', '>=', now()->subDays(400)->startOfDay())
            ->where($countable)
            ->with('room:id,name')
            ->get(['id', 'room_id', 'started_at', 'total_effective_seconds', 'completed_pomodoros', 'status', 'phase', 'phase_started_at', 'paused_at', 'focus_duration_minutes', 'break_duration_minutes']);

        // Detik efektif per hari (Y-m-d)
        $perDay = [];
        $pomPerDay = [];
        foreach ($recent as $s) {
            $d = $s->started_at->format('Y-m-d');
            $perDay[$d] = ($perDay[$d] ?? 0) + $s->effectiveSecondsNow();
            $pomPerDay[$d] = ($pomPerDay[$d] ?? 0) + $s->completed_pomodoros;
        }

        $today = now()->format('Y-m-d');
        $todaySeconds = $perDay[$today] ?? 0;

        // Streak berjalan: belum putus selama hari ini belum berakhir.
        $streak = 0;
        $cursor = now()->startOfDay();
        if (! isset($perDay[$cursor->format('Y-m-d')])) {
            $cursor->subDay();
        }
        while (isset($perDay[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor->subDay();
        }

        // Streak terpanjang dalam data 400 hari terakhir.
        $longest = 0;
        $run = 0;
        $prev = null;
        $days = array_keys($perDay);
        sort($days);
        foreach ($days as $d) {
            $c = Carbon::createFromFormat('Y-m-d', $d)->startOfDay();
            $run = ($prev && $prev->copy()->addDay()->isSameDay($c)) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $prev = $c;
        }

        // 7 hari
        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i)->startOfDay();
            $secs = $perDay[$d->format('Y-m-d')] ?? 0;
            $week[] = ['label' => $d->translatedFormat('D'), 'date' => $d->format('d M'), 'minutes' => (int) floor($secs / 60), 'is_today' => $i === 0];
        }

        // 30 hari (kalender ringkas)
        $month = [];
        $goal = max(1, (int) ($user->daily_goal_minutes ?: 120));
        for ($i = 29; $i >= 0; $i--) {
            $d = now()->subDays($i)->startOfDay();
            $min = (int) floor(($perDay[$d->format('Y-m-d')] ?? 0) / 60);
            $month[] = [
                'date' => $d->translatedFormat('D, d M'),
                'minutes' => $min,
                'level' => $min === 0 ? 0 : ($min >= $goal ? 3 : ($min >= $goal / 2 ? 2 : 1)),
                'hit' => $min >= $goal,
            ];
        }

        $last30 = array_slice($month, -30);
        $activeDays30 = count(array_filter($last30, fn ($m) => $m['minutes'] > 0));
        $total30 = array_sum(array_column($last30, 'minutes'));
        $goalDays30 = count(array_filter($last30, fn ($m) => $m['hit']));
        $best = collect($last30)->sortByDesc('minutes')->first();

        // Rekap per room (30 hari)
        $since = now()->subDays(29)->startOfDay();
        $byRoom = $recent->filter(fn ($s) => $s->started_at->gte($since))
            ->groupBy(fn ($s) => $s->room?->name ?? 'Pomodoro solo')
            ->map(fn ($g, $name) => ['name' => $name, 'minutes' => (int) floor($g->sum(fn ($s) => $s->effectiveSecondsNow()) / 60), 'sessions' => $g->count()])
            ->sortByDesc('minutes')->values()->take(5)->all();

        return [
            'total_seconds' => $totalSeconds,
            'total_hours' => intdiv($totalSeconds, 3600),
            'total_minutes' => intdiv($totalSeconds % 3600, 60),
            'session_count' => $sessionCount,
            'pomodoros' => $pomodoros,
            'streak' => $streak,
            'longest_streak' => max($longest, $streak),
            'week' => $week,
            'month' => $month,
            'today_minutes' => (int) floor($todaySeconds / 60),
            'today_pomodoros' => $pomPerDay[$today] ?? 0,
            'goal_minutes' => $goal,
            'goal_percent' => (int) min(100, round($todaySeconds / 60 / $goal * 100)),
            'goal_days_30' => $goalDays30,
            'active_days_30' => $activeDays30,
            'total_30_minutes' => $total30,
            'avg_active_day_minutes' => $activeDays30 ? (int) round($total30 / $activeDays30) : 0,
            'best_day' => $best && $best['minutes'] > 0 ? $best : null,
            'by_room' => $byRoom,
            'focus_percent_30' => self::focusPercent($user, $since),
        ];
    }

    /** Persentase waktu HIJAU dari seluruh waktu yang dipantau (hijau+kuning+merah) dalam 30 hari; null bila belum ada data. */
    private static function focusPercent(User $user, Carbon $since): ?int
    {
        $sessionIds = StudySession::where('user_id', $user->id)->where('started_at', '>=', $since)->pluck('id');
        if ($sessionIds->isEmpty()) {
            return null;
        }

        $logs = FocusLog::whereIn('session_id', $sessionIds)->orderBy('session_id')->orderBy('occurred_at')->orderBy('id')
            ->get(['session_id', 'status', 'occurred_at']);
        $ends = StudySession::whereIn('id', $sessionIds)->pluck('ended_at', 'id');

        $green = 0;
        $monitored = 0;
        foreach ($logs->groupBy('session_id') as $sid => $rows) {
            $end = $ends[$sid] ? Carbon::parse($ends[$sid]) : now();
            foreach ($rows->values() as $i => $log) {
                $to = $rows[$i + 1]->occurred_at ?? $end;
                $sec = max(0, $to->getTimestamp() - $log->occurred_at->getTimestamp());
                if (in_array($log->status, ['HIJAU', 'KUNING', 'MERAH'], true)) {
                    $monitored += $sec;
                    if ($log->status === 'HIJAU') {
                        $green += $sec;
                    }
                }
            }
        }

        return $monitored >= 60 ? (int) round($green / $monitored * 100) : null;
    }
}
