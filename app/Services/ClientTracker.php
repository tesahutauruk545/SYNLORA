<?php

namespace App\Services;

use App\Models\StudySession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mendeteksi lebih dari satu "klien" yang mengirim detak untuk SATU sesi yang sama.
 *
 *  - device_id : acak, disimpan di localStorage -> sama untuk semua tab pada satu browser/profil.
 *  - tab_id    : acak, disimpan di sessionStorage -> berbeda untuk tiap tab.
 *
 * Dua device_id berbeda dalam jendela waktu  => MULTI_DEVICE_DETECTED (browser/perangkat lain memakai akun yang sama).
 * Satu device_id, beberapa tab_id            => MULTI_TAB_DETECTED (hanya informasi; dua tab SYNLORA dibolehkan).
 *
 * Ini SINYAL, bukan bukti: pengguna yang mengutak-atik klien dapat berbagi device_id. Sesi tidak diblokir
 * (melanjutkan dari perangkat lain itu sah); kejadiannya dicatat dan ditampilkan sebagai "perlu ditinjau".
 */
class ClientTracker
{
    public static function observe(StudySession $session, ?string $deviceId, ?string $tabId): ?string
    {
        if (! $deviceId && ! $tabId) {
            return null;
        }

        $window = max(10, (int) config('synlora.integrity.multi_client_window_seconds', 45));
        $now = now()->getTimestamp();
        $key = "clients:{$session->id}";

        $seen = array_filter((array) Cache::get($key, []), fn ($r) => $now - $r[2] <= $window);
        $id = ($deviceId ?: '-').'|'.($tabId ?: '-');
        $isNew = ! isset($seen[$id]);
        $seen[$id] = [$deviceId, $tabId, $now];
        Cache::put($key, $seen, $window * 2);

        if (! $isNew || count($seen) < 2) {
            return null;
        }

        $devices = array_unique(array_filter(array_column($seen, 0)));
        $kind = count($devices) > 1 ? 'device' : 'tab';

        // Satu catatan per jenis per 5 menit: episode yang sama tidak membanjiri log.
        if (! Cache::add("clientsflag:{$session->id}:{$kind}", 1, 300)) {
            return null;
        }

        if ($kind === 'device') {
            $session->logEvent('MULTI_DEVICE_DETECTED', 'Lebih dari satu browser/perangkat mengirim data untuk sesi ini');
            DB::table('study_sessions')->where('id', $session->id)->increment('multi_device_count');
        } else {
            $session->logEvent('MULTI_TAB_DETECTED', 'Lebih dari satu tab SYNLORA membuka sesi ini');
            DB::table('study_sessions')->where('id', $session->id)->increment('multi_tab_count');
        }

        return $kind;
    }
}
