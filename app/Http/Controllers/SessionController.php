<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\FocusLog;
use App\Services\ClientTracker;
use App\Services\FocusRules;
use App\Services\LecturerMonitor;
use Illuminate\Support\Facades\Cache;
use App\Models\Room;
use App\Models\StudySession;
use App\Services\SessionRecap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pomodoro + pencatatan fokus. Semua endpoint di sini memakai waktu server dan
 * hanya dapat dikendalikan oleh pemilik sesi (StudySessionPolicy@control).
 */
class SessionController extends Controller
{
    // ------------------------------------------------------------ halaman

    public function solo(Request $request)
    {
        $open = StudySession::where('user_id', $request->user()->id)->open()->with('room:id,name,code')->latest()->first();
        $open?->sync();
        if ($open && ! $open->isOpen()) {
            $open = null;
        }

        $activeSession = $open && $open->room_id === null ? $open : null;
        $otherSession = $open && ! $activeSession ? $open : null;

        return view('pomodoro.solo', compact('activeSession', 'otherSession'));
    }

    public function index(Request $request)
    {
        $user = $request->user();

        StudySession::where('user_id', $user->id)->open()->get()->each->sync();

        $sessions = StudySession::where('user_id', $user->id)
            ->with('room:id,name,code')
            ->latest('started_at')
            ->paginate(15);

        return view('sessions.index', compact('sessions'));
    }

    public function recap(Request $request, StudySession $session)
    {
        $this->authorize('viewRecap', $session);

        $session->sync();

        $recap = SessionRecap::build($session);
        $isMine = (int) $session->user_id === (int) $request->user()->id;

        return view('sessions.recap', $recap + ['isMine' => $isMine]);
    }

    // ------------------------------------------------------- API (JSON)

    public function storeInRoom(Request $request, Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        $data = $request->validate([
            'break_minutes' => 'nullable|integer|min:1|max:60',
            'study_goal' => 'nullable|string|max:160',
            'consent' => $room->strict_mode ? 'accepted' : 'nullable|boolean',
        ], [
            'consent.accepted' => 'Room ini memakai Mode Ketat. Centang persetujuan pemantauan sebelum memulai sesi.',
        ]);

        $session = StudySession::begin($request->user(), $room, $room->target_minutes, $data['break_minutes'] ?? 5, [
            'study_goal' => $data['study_goal'] ?? null,
            'consented_at' => $room->strict_mode ? now() : null,
        ]);

        return response()->json($this->payload($session), 201);
    }

    public function storeSolo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'focus_minutes' => 'required|integer|min:1|max:180',
            'break_minutes' => 'required|integer|min:1|max:60',
            'study_goal' => 'nullable|string|max:160',
        ]);

        $session = StudySession::begin($request->user(), null, $data['focus_minutes'], $data['break_minutes'], ['study_goal' => $data['study_goal'] ?? null]);

        return response()->json($this->payload($session), 201);
    }

    public function state(StudySession $session): JsonResponse
    {
        $this->authorize('control', $session);

        $session->sync()->heartbeat();

        return response()->json($this->payload($session->fresh()));
    }

    /**
     * Detak ringan agar sesi tetap dianggap terhubung walau tab tersembunyi (±15 dtk).
     * Opsional membawa `hidden` (tab tersembunyi?) sebagai pembanding silang bagi event yang hilang
     * (mis. event gagal terkirim saat koneksi putus). Server menghitung status dari ini, bukan dari klien.
     */
    public function heartbeat(Request $request, StudySession $session): JsonResponse
    {
        $this->authorize('control', $session);

        $data = $request->validate([
            'hidden' => ['nullable', 'boolean'],
            'device_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'tab_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $prev = $session->last_heartbeat_at;

        if ($session->needsSync()) {
            $session->sync();
        }

        if ($session->isActive() && $session->phase === StudySession::FOCUS) {
            $now = now();
            $stale = (int) config('synlora.focus.stale_seconds', 60);

            // Koneksi sempat putus: catat periode DISCONNECTED apa adanya (bukan dianggap belajar fokus).
            if ($prev && $now->getTimestamp() - $prev->getTimestamp() > $stale && $session->live_status) {
                $from = $prev->copy()->addSeconds($stale);
                $session->logEvent('HEARTBEAT_LOST', 'Koneksi terputus ±'.($now->getTimestamp() - $prev->getTimestamp()).' detik', $from, $now->getTimestamp() - $prev->getTimestamp());
                DB::table('study_sessions')->where('id', $session->id)->increment('heartbeat_lost_count');
                $session->logFocus('DISCONNECTED', 'Koneksi terputus', $from, 'server', 'HEARTBEAT_LOST');
                $session->logFocus(($data['hidden'] ?? false) ? 'KUNING' : 'HIJAU', 'Koneksi pulih', $now, 'browser', ($data['hidden'] ?? false) ? 'TAB_SWITCH_OUT' : 'TAB_SWITCH_IN');
            } elseif (array_key_exists('hidden', $data) && $session->live_status) {
                $away = in_array($session->live_event, ['TAB_SWITCH_OUT', 'TAB_AWAY_LONG'], true);
                if ($data['hidden'] && ! $away) {
                    $session->logEvent('TAB_SWITCH_OUT', 'Terdeteksi dari detak (event tidak sampai)', $now);
                    $session->logFocus('KUNING', 'Tab ditinggalkan (dari detak)', $now, 'browser', 'TAB_SWITCH_OUT');
                } elseif (! $data['hidden'] && $away) {
                    $session->logEvent('TAB_SWITCH_IN', 'Terdeteksi dari detak (event tidak sampai)', $now);
                    $session->logFocus('HIJAU', 'Kembali ke tab (dari detak)', $now, 'browser', 'TAB_SWITCH_IN');
                }
            }
        }

        if ($session->isActive() && $session->phase === StudySession::FOCUS) {
            ClientTracker::observe($session, $data['device_id'] ?? null, $data['tab_id'] ?? null);
        }

        $session->heartbeat();

        return response()->json(['ok' => true, 'open' => $session->isOpen(), 'server_time' => now()->getTimestamp()]);
    }

    public function pause(StudySession $session): JsonResponse
    {
        $this->authorize('control', $session);

        DB::transaction(fn () => StudySession::whereKey($session->id)->lockForUpdate()->first()->pause());

        return response()->json($this->payload($session->fresh()));
    }

    public function resume(StudySession $session): JsonResponse
    {
        $this->authorize('control', $session);

        DB::transaction(fn () => StudySession::whereKey($session->id)->lockForUpdate()->first()->resume());

        return response()->json($this->payload($session->fresh()));
    }

    public function finish(StudySession $session): JsonResponse
    {
        $this->authorize('control', $session);

        DB::transaction(fn () => StudySession::whereKey($session->id)->lockForUpdate()->first()->finish('manual'));

        return response()->json($this->payload($session->fresh()));
    }

    /**
     * Menerima event dari pemantauan fokus di browser. Hanya diterima untuk sesi
     * yang sedang berjalan milik pengguna. Waktu event ditentukan server.
     * Yang dikirim hanya jenis event & status; TIDAK ada gambar/video.
     */
    public function events(Request $request, StudySession $session): JsonResponse
    {
        $this->authorize('control', $session);

        $data = $request->validate([
            'event_type' => ['required', 'string', 'in:'.implode(',', ActivityLog::CLIENT_EVENTS)],
            'status' => ['nullable', 'string', 'in:'.implode(',', FocusLog::STATUSES)],
            'source' => ['nullable', 'string', 'in:'.implode(',', FocusLog::SOURCES)],
            'details' => ['nullable', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'client_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'delay_seconds' => ['nullable', 'integer', 'min:0', 'max:600'],
        ]);

        // Idempoten: klien mengulang kirim saat koneksi putus; event yang sama tidak boleh tercatat dua kali.
        if (! empty($data['client_id']) && ! Cache::add("evt:{$session->id}:{$data['client_id']}", 1, 900)) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $session->sync();

        if (! $session->isActive() || $session->phase !== StudySession::FOCUS) {
            return response()->json(['ok' => false, 'reason' => 'not_in_focus'], 409);
        }

        // Event yang tertunda (koneksi putus) dicatat pada waktu kejadiannya, tetapi:
        //  - mundur paling jauh sejauh celah NYATA sejak server terakhir mendengar klien (allowedDelay), sehingga
        //    klien tidak bisa memundurkan waktu untuk menghapus durasi "tab ditinggalkan";
        //  - tidak boleh mendahului sesi/fase ini maupun perubahan status terakhir.
        $now = now()->subSeconds($this->allowedDelay($session, (int) ($data['delay_seconds'] ?? 0)));
        $floor = collect([$session->phase_started_at, $session->started_at, $session->live_changed_at])->filter()->max();
        if ($floor && $now->lt($floor)) {
            $now = $floor->copy();
        }
        $type = $data['event_type'];
        $since = $session->live_changed_at ? $now->getTimestamp() - $session->live_changed_at->getTimestamp() : null;

        // Keterangan = teks MILIK SERVER. Catatan klien hanya dipakai untuk kode galat teknis (mis. NotAllowedError).
        $note = isset($data['details']) && preg_match('/^[A-Za-z0-9 _.:\-]{1,60}$/', $data['details']) && in_array($type, FocusRules::CAMERA_ISSUES, true)
            ? ' ['.$data['details'].']' : '';
        $label = FocusRules::reason($type);
        $session->logEvent($type, $label.$note, $now, $data['duration_seconds'] ?? null);

        if (in_array($type, FocusRules::CAMERA_ISSUES, true)) {
            DB::table('study_sessions')->where('id', $session->id)->increment('camera_issue_count');
        }

        // Status ditentukan SERVER dari jenis event; status/sumber kiriman klien tidak dipercaya.
        // Keadaan "tab ditinggalkan" bersifat lengket: hanya sinyal KEMBALI (TAB_SWITCH_IN) atau detak
        // dengan hidden=false yang mengakhirinya. Event lain (blur, wajah, dst.) tidak boleh mereset jam
        // eskalasi MERAH, sehingga klien tidak bisa menunda MERAH dengan mengirim event berulang.
        $away = in_array($session->live_event, ['TAB_SWITCH_OUT', 'TAB_AWAY_LONG'], true);
        $derived = $away && in_array($type, ['WINDOW_BLUR', 'WINDOW_FOCUS', 'FACE_LOST_SHORT', 'FACE_DETECTED', 'TAB_SWITCH_OUT', 'MONITOR_START'], true)
            ? null
            : FocusRules::derive($type, $data['status'] ?? null, $session->live_event, $since);
        if ($derived) {
            $session->logFocus($derived[0], $label, $now, $derived[1], $type);
        }

        if (isset(FocusRules::CAMERA_STATES[$type])) {
            $state = FocusRules::CAMERA_STATES[$type];
            $session->forceFill(['camera_state' => $state, 'camera_on' => $state === 'on'])->saveQuietly();
        }

        $session->heartbeat();
        LecturerMonitor::forgetFor($session);

        return response()->json(['ok' => true]);
    }

    // ----------------------------------------------------------- helper

    /**
     * Batas mundur timestamp untuk event tertunda. Klien boleh mengaku tertunda sekian detik (antrean saat offline),
     * tetapi server hanya mengakui sejauh celah nyata sejak terakhir ia mendengar klien itu. Dengan detak tiap ~15 dtk,
     * pengakuan tertunda 10 menit dari klien yang terus mengirim detak otomatis dipangkas menjadi hitungan detik.
     * Celah nyata dipatok sekali (30 dtk) agar satu antrean event setelah offline tetap berurutan benar.
     */
    private function allowedDelay(StudySession $session, int $claimed): int
    {
        if ($claimed <= 0) {
            return 0;
        }

        $nowTs = now()->getTimestamp();
        $last = $session->last_heartbeat_at?->getTimestamp();
        $key = "evtgap:{$session->id}";

        if ($last !== null && $nowTs - $last > (int) config('synlora.integrity.gap_seconds', 20)) {
            Cache::put($key, $last, 30);
        }

        $base = Cache::get($key) ?? $last ?? $nowTs;

        return min($claimed, max(0, $nowTs - (int) $base));
    }

    private function payload(StudySession $s): array
    {
        $s->loadMissing('room:id,name,code');

        return [
            'session' => [
                'id' => $s->id,
                'status' => $s->status,
                'phase' => $s->phase,
                'phase_total_seconds' => $s->phaseSeconds(),
                'remaining_seconds' => $s->isOpen() ? $s->remainingSeconds() : 0,
                'ends_at' => $s->phaseEndsAt(),
                'completed_pomodoros' => $s->completed_pomodoros,
                'effective_seconds' => $s->effectiveSecondsNow(),
                'focus_minutes' => $s->focus_duration_minutes,
                'break_minutes' => $s->break_duration_minutes,
                'room_code' => $s->room?->code,
                'end_reason' => $s->end_reason,
            ],
            'server_time' => now()->getTimestamp(),
            'urls' => [
                'state' => route('sessions.state', $s),
                'pause' => route('sessions.pause', $s),
                'resume' => route('sessions.resume', $s),
                'finish' => route('sessions.finish', $s),
                'events' => route('sessions.events', $s),
                'heartbeat' => route('sessions.heartbeat', $s),
                'recap' => route('sessions.recap', $s),
            ],
        ];
    }

    /** Dipakai view untuk menyuntikkan keadaan sesi aktif saat halaman dimuat. */
    public static function payloadFor(StudySession $s): array
    {
        return (new static)->payload($s);
    }
}
