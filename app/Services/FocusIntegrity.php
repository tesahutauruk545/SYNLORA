<?php

namespace App\Services;

use App\Models\StudySession;
use Illuminate\Support\Carbon;

/**
 * FOCUS INTEGRITY ENGINE (server). Memisahkan empat hal yang sebelumnya bercampur dalam satu warna:
 *
 *   A. ACTIVITY STATUS        GREEN | YELLOW | RED | GRAY
 *   B. MONITORING RELIABILITY HIGH | LIMITED | LOW           (seberapa jauh sinyal dapat dipercaya)
 *   C. CAMERA STATUS          ACTIVE | DISABLED | PERMISSION_DENIED | UNAVAILABLE | ERROR
 *   D. CONNECTION STATUS      CONNECTED | RECONNECTING | DISCONNECTED
 *   + SESSION INTEGRITY       NORMAL | ANOMALY_DETECTED | INTERRUPTED | INCOMPLETE
 *
 * Prinsip:
 *  - GREEN bukan bukti konsentrasi; RED hanya untuk aturan teknis yang jelas (tab/aplikasi ditinggalkan lama).
 *  - Keandalan RENDAH atau data tidak lengkap BUKAN tuduhan curang: bisa jadi internet, perangkat, atau kamera.
 *  - Tidak ada label "curang". Label yang dipakai: Aktivitas normal | Perlu ditinjau | Monitoring terbatas |
 *    Monitoring terputus | Data tidak lengkap.
 *  - Semuanya dihitung di SERVER dari data yang diterima server; status kiriman klien tidak dipercaya.
 *
 * live()   : murah, hanya dari kolom sesi (aman dipanggil per mahasiswa di dashboard).
 * report() : lengkap, membaca activity_logs/focus_logs untuk satu sesi (riwayat, rekap, laporan).
 */
class FocusIntegrity
{
    public const ACTIVITY = ['HIJAU' => 'GREEN', 'KUNING' => 'YELLOW', 'MERAH' => 'RED'];

    public const CAMERA = [
        'on' => 'ACTIVE', 'off' => 'DISABLED', 'denied' => 'PERMISSION_DENIED', 'unavailable' => 'UNAVAILABLE',
        'error' => 'ERROR', 'model_failed' => 'ERROR', 'interrupted' => 'ERROR',
    ];

    public const LABELS = [
        'NORMAL_HIGH' => 'Aktivitas normal',
        'ANOMALY_DETECTED' => 'Perlu ditinjau',
        'NORMAL_LIMITED' => 'Monitoring terbatas',
        'INTERRUPTED' => 'Monitoring terputus',
        'INCOMPLETE' => 'Data tidak lengkap',
    ];

    /** Ringkasan murah dari kolom sesi. */
    public static function live(StudySession $s, ?Carbon $at = null): array
    {
        $at ??= now();
        [$status] = $s->displayFocus($at);
        $camera = self::CAMERA[$s->camera_state ?: 'off'] ?? 'DISABLED';
        $connection = self::connection($s, $at, $status);

        $facts = [
            'connection' => $connection,
            'camera' => $camera,
            'strict' => $s->mode === 'strict',
            'never_started' => $s->isActive() && $s->phase === StudySession::FOCUS && ! $s->live_status
                && $at->getTimestamp() - ($s->phase_started_at ?? $s->started_at)->getTimestamp() >= 60,
            'heartbeat_lost' => (int) $s->heartbeat_lost_count,
            'camera_issues' => (int) $s->camera_issue_count,
            'multi_device' => (int) $s->multi_device_count,
            'multi_tab' => (int) $s->multi_tab_count,
            'abandoned' => $s->end_reason === 'abandoned',
            'unmonitored_percent' => 0,
            'red_episodes' => 0,
            'low_quality' => 0,
        ];

        return [
            'activity' => self::ACTIVITY[$status] ?? 'GRAY',
            'camera' => $camera,
            'connection' => $connection,
            'mode' => $s->mode ?: 'standard',
        ] + self::assess($facts);
    }

    /** Laporan lengkap satu sesi: kategori + metrik + linimasa episode. */
    public static function report(StudySession $s): array
    {
        $s->load('room:id,name,strict_mode,ai_policy');
        $end = $s->ended_at ?? now();
        $ongoing = ! $s->ended_at;
        $stale = (int) config('synlora.focus.stale_seconds', 60);
        $grace = (int) config('synlora.focus.hidden_grace_seconds', 10);

        $acts = $s->activityLogs()->orderBy('occurred_at')->orderBy('id')->get(['event_type', 'details', 'duration_seconds', 'occurred_at']);
        $logs = $s->focusLogs()->orderBy('occurred_at')->orderBy('id')->get(['status', 'source', 'occurred_at']);

        // --- detik per status (dari focus_logs, apa adanya)
        $secs = ['HIJAU' => 0, 'KUNING' => 0, 'MERAH' => 0, 'ABU' => 0, 'DISCONNECTED' => 0, 'UNKNOWN' => 0];
        foreach ($logs as $i => $log) {
            $to = $logs[$i + 1]->occurred_at ?? $end;
            $secs[$log->status] = ($secs[$log->status] ?? 0) + max(0, $to->getTimestamp() - $log->occurred_at->getTimestamp());
        }
        $monitored = $secs['HIJAU'] + $secs['KUNING'] + $secs['MERAH'];
        $effective = $s->effectiveSecondsNow();
        $focusTotal = max($effective, $monitored + $secs['DISCONNECTED'] + $secs['UNKNOWN']);
        $unmonitored = max(0, $focusTotal - $monitored);
        $unmonitoredPct = $focusTotal >= 60 ? (int) round($unmonitored / $focusTotal * 100) : 0;

        // --- episode (linimasa)
        $episodes = [];
        $open = [];
        $push = function (string $kind, string $label, Carbon $from, ?Carbon $to, bool $isOngoing = false, bool $red = false) use (&$episodes) {
            $episodes[] = [
                'kind' => $kind, 'label' => $label,
                'from' => $from->getTimestamp(), 'to' => $to?->getTimestamp(),
                'seconds' => $to ? max(0, $to->getTimestamp() - $from->getTimestamp()) : null,
                'ongoing' => $isOngoing, 'red' => $red,
            ];
        };
        $closeAll = function (Carbon $at) use (&$open, $push) {
            foreach ($open as $k => $o) {
                $push($o['kind'], $o['label'], $o['from'], $at, false, $o['red'] ?? false);
            }
            $open = [];
        };
        $start = function (string $kind, string $label, Carbon $t) use (&$open) {
            $open[$kind] ??= ['kind' => $kind, 'label' => $label, 'from' => $t, 'red' => false];
        };
        $stop = function (string $kind, Carbon $t) use (&$open, $push) {
            if (isset($open[$kind])) {
                $o = $open[$kind];
                $push($o['kind'], $o['label'], $o['from'], $t, false, $o['red']);
                unset($open[$kind]);
            }
        };

        foreach ($acts as $a) {
            $t = $a->occurred_at;
            switch ($a->event_type) {
                case 'TAB_SWITCH_OUT': $start('tab', 'Tab SYNLORA ditinggalkan', $t); break;
                case 'TAB_AWAY_LONG': if (isset($open['tab'])) { $open['tab']['red'] = true; } break;
                case 'TAB_SWITCH_IN': $stop('tab', $t); break;
                case 'WINDOW_BLUR': $start('window', 'Fokus jendela hilang (aplikasi lain aktif)', $t); break;
                case 'WINDOW_FOCUS': $stop('window', $t); break;
                case 'CAMERA_LOW_QUALITY': $start('quality', 'Gambar kamera gelap/tidak jelas', $t); break;
                case 'CAMERA_QUALITY_OK': $stop('quality', $t); break;
                case 'POSE_AWAY': $start('pose', 'Kepala menghadap samping/bawah (sinyal tambahan)', $t); break;
                case 'POSE_FORWARD': $stop('pose', $t); break;
                case 'PHASE_BREAK': case 'SESSION_PAUSE': case 'SESSION_END': $closeAll($t); break;
                case 'HEARTBEAT_LOST':
                    $dur = (int) ($a->duration_seconds ?? 0);
                    $from = $t->copy()->subSeconds($stale);
                    $push('disconnect', 'Koneksi/pemantauan terputus', $from, $from->copy()->addSeconds(max($dur, $stale)));
                    break;
                case 'CAMERA_DENIED': case 'CAMERA_UNAVAILABLE': case 'CAMERA_ERROR': case 'MODEL_LOAD_FAILED': case 'CAMERA_INTERRUPTED':
                    $push('camera', $a->details ?: FocusRules::reason($a->event_type), $t, $t);
                    break;
                case 'MULTI_DEVICE_DETECTED': $push('multi_device', 'Browser/perangkat lain mengirim data untuk sesi ini', $t, $t); break;
                case 'MULTI_TAB_DETECTED': $push('multi_tab', 'Beberapa tab SYNLORA membuka sesi ini', $t, $t); break;
            }
        }
        foreach ($open as $o) {
            $push($o['kind'], $o['label'], $o['from'], $ongoing ? null : $end, $ongoing, $o['red']);
        }
        usort($episodes, fn ($x, $y) => $x['from'] <=> $y['from']);

        $of = fn (string $kind) => array_values(array_filter($episodes, fn ($e) => $e['kind'] === $kind));
        $sum = fn (array $list) => array_sum(array_map(fn ($e) => $e['seconds'] ?? max(0, $end->getTimestamp() - $e['from']), $list));
        $tabs = $of('tab');
        $wins = $of('window');
        $loss = array_merge($tabs, $wins);
        $redEpisodes = count(array_filter($tabs, fn ($e) => $e['red'] || (($e['seconds'] ?? 0) >= $grace)));

        $camState = $s->camera_state ?: 'off';
        $camera = self::CAMERA[$camState] ?? 'DISABLED';
        $displayStatus = $s->displayFocus()[0];
        $connection = self::connection($s, now(), $displayStatus);

        $facts = [
            'connection' => $connection,
            'camera' => $camera,
            'strict' => $s->mode === 'strict',
            'never_started' => $logs->isEmpty() && $effective >= 60,
            'heartbeat_lost' => max((int) $s->heartbeat_lost_count, count($of('disconnect'))),
            'camera_issues' => max((int) $s->camera_issue_count, count($of('camera'))),
            'multi_device' => max((int) $s->multi_device_count, count($of('multi_device'))),
            'multi_tab' => max((int) $s->multi_tab_count, count($of('multi_tab'))),
            'abandoned' => $s->end_reason === 'abandoned',
            'unmonitored_percent' => $unmonitoredPct,
            'red_episodes' => $redEpisodes,
            'low_quality' => count($of('quality')),
        ];

        return [
            'activity' => self::ACTIVITY[$displayStatus] ?? 'GRAY',
            'camera' => $camera,
            'connection' => $connection,
            'mode' => $s->mode ?: 'standard',
            'metrics' => [
                'focus_loss_count' => count($loss),
                'focus_loss_seconds' => $sum($loss),
                'focus_loss_longest' => $loss ? max(array_map(fn ($e) => $e['seconds'] ?? 0, $loss)) : 0,
                'tab_departures' => count($tabs),
                'window_blurs' => count($wins),
                'red_episodes' => $redEpisodes,
                'disconnect_count' => $facts['heartbeat_lost'],
                'disconnect_seconds' => $sum($of('disconnect')),
                'camera_issue_count' => $facts['camera_issues'],
                'low_quality_count' => $facts['low_quality'],
                'pose_away_seconds' => $sum($of('pose')),
                'multi_device_count' => $facts['multi_device'],
                'multi_tab_count' => $facts['multi_tab'],
                'effective_seconds' => $effective,
                'monitored_seconds' => $monitored,
                'unmonitored_seconds' => $unmonitored,
                'unmonitored_percent' => $unmonitoredPct,
            ],
            'timeline' => $episodes,
            'learning' => [
                'study_goal' => $s->study_goal,
                'reflection_done' => $s->reflected_at !== null,
                'ai_usage' => $s->ai_usage,
                'ai_usage_note' => $s->ai_usage_note,
                'ai_policy' => $s->room?->ai_policy,
            ],
        ] + self::assess($facts);
    }

    private static function connection(StudySession $s, Carbon $at, string $displayStatus): ?string
    {
        if (! $s->isOpen()) {
            return null;
        }
        if ($displayStatus === 'DISCONNECTED') {
            return 'DISCONNECTED';
        }
        $gap = $s->last_heartbeat_at ? $at->getTimestamp() - $s->last_heartbeat_at->getTimestamp() : 0;

        // Detak datang tiap ~15 dtk: satu-dua detak terlewat = sedang mencoba tersambung kembali.
        return $s->isActive() && $gap > (int) config('synlora.integrity.gap_seconds', 20) ? 'RECONNECTING' : 'CONNECTED';
    }

    /**
     * Menurunkan keandalan, integritas, label, dan alasan yang dapat dibaca manusia.
     *
     * @return array{reliability:string,integrity:string,label:string,reasons:array<int,string>}
     */
    private static function assess(array $f): array
    {
        $incompleteAt = (int) config('synlora.integrity.incomplete_unmonitored_percent', 30);
        $reviewRed = (int) config('synlora.integrity.review_red_episodes', 3);
        $reasons = [];

        // ---- Keandalan: seberapa jauh sinyal dapat dipercaya (BUKAN tingkat kecurangan).
        $reliability = 'HIGH';
        $low = false;
        if ($f['connection'] === 'DISCONNECTED') {
            $low = true;
            $reasons[] = 'Browser tidak mengirim data (koneksi/tab tertutup/perangkat tidur).';
        }
        if ($f['never_started']) {
            $low = true;
            $reasons[] = 'Pemantauan di browser belum berjalan selama sesi fokus.';
        }
        if ($f['unmonitored_percent'] >= $incompleteAt) {
            $low = true;
            $reasons[] = "{$f['unmonitored_percent']}% waktu fokus tidak terpantau (koneksi putus/pemantauan belum jalan).";
        }
        $limited = false;
        if (! $low) {
            if ($f['heartbeat_lost'] > 0) {
                $limited = true;
                $reasons[] = "Koneksi sempat terputus {$f['heartbeat_lost']} kali.";
            }
            if ($f['camera'] !== 'ACTIVE' && $f['camera'] !== 'DISABLED') {
                $limited = true;
                $reasons[] = 'Kamera bermasalah (izin ditolak/tidak tersedia/terputus); hanya aktivitas browser yang dipantau.';
            } elseif ($f['camera'] === 'DISABLED' && $f['strict']) {
                $limited = true;
                $reasons[] = 'Mode ketat: kamera tidak aktif, sehingga sinyal kehadiran visual tidak tersedia.';
            }
            if ($f['camera_issues'] > 0 && $f['camera'] === 'ACTIVE') {
                $limited = true;
                $reasons[] = "Kamera sempat bermasalah {$f['camera_issues']} kali selama sesi.";
            }
            if ($f['low_quality'] > 0) {
                $limited = true;
                $reasons[] = 'Gambar kamera sempat terlalu gelap/tidak jelas.';
            }
            if ($f['multi_device'] > 0) {
                $limited = true;
            }
        }
        $reliability = $low ? 'LOW' : ($limited ? 'LIMITED' : 'HIGH');

        // ---- Integritas sesi.
        $integrity = 'NORMAL';
        if ($f['multi_device'] > 0 || $f['red_episodes'] >= $reviewRed) {
            $integrity = 'ANOMALY_DETECTED';
            if ($f['multi_device'] > 0) {
                $reasons[] = "Ada {$f['multi_device']} kejadian browser/perangkat lain mengirim data untuk sesi yang sama (bisa jadi sah, mis. berpindah perangkat).";
            }
            if ($f['red_episodes'] >= $reviewRed) {
                $reasons[] = "Tab/aplikasi SYNLORA ditinggalkan lama sebanyak {$f['red_episodes']} kali.";
            }
        } elseif ($f['heartbeat_lost'] > 0 || $f['abandoned'] || $f['connection'] === 'DISCONNECTED') {
            $integrity = 'INTERRUPTED';
            if ($f['abandoned']) {
                $reasons[] = 'Sesi ditutup otomatis karena halaman tidak aktif.';
            }
        } elseif ($low) {
            $integrity = 'INCOMPLETE';
        }

        $label = match (true) {
            $integrity === 'ANOMALY_DETECTED' => self::LABELS['ANOMALY_DETECTED'],
            $integrity === 'INTERRUPTED' => self::LABELS['INTERRUPTED'],
            $integrity === 'INCOMPLETE' => self::LABELS['INCOMPLETE'],
            $reliability === 'LIMITED' => self::LABELS['NORMAL_LIMITED'],
            default => self::LABELS['NORMAL_HIGH'],
        };

        return ['reliability' => $reliability, 'integrity' => $integrity, 'label' => $label, 'reasons' => array_values(array_unique($reasons))];
    }
}
