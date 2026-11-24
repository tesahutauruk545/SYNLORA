<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LecturerMonitor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Monitoring belajar untuk dosen (mahasiswa di room yang sama). Seluruh route dilindungi middleware role:dosen. */
class DosenMonitorController extends Controller
{
    public function index(Request $request)
    {
        $rooms = LecturerMonitor::monitoredRooms($request->user());

        return view('dosen.monitor', [
            'rooms' => $rooms,
            'config' => [
                'dataUrl' => route('dosen.data'),
                'alertsUrl' => route('dosen.alerts'),
                'historyUrl' => url('/dosen/mahasiswa/__ID__/riwayat'),
                'pollSeconds' => (int) config('synlora.monitor.poll_seconds', 5),
            ],
        ]);
    }

    public function data(Request $request)
    {
        $request->validate(['room' => ['nullable', 'string', 'regex:/^[A-Z0-9]{6}$/']]);

        return response()
            ->json(LecturerMonitor::snapshot($request->user(), $request->query('room') ?: null))
            ->header('Cache-Control', 'no-store, private');
    }

    /** Riwayat peringatan monitoring, dibatasi pada room yang diikuti dosen ini. */
    public function alerts(Request $request)
    {
        $request->validate([
            'room' => ['nullable', 'string', 'regex:/^[A-Z0-9]{6}$/'],
            'student' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'in:'.implode(',', array_keys(\App\Services\RoomMonitor::ALERTS))],
        ]);

        $rooms = LecturerMonitor::monitoredRooms($request->user());
        $ids = $request->query('room') ? $rooms->where('code', $request->query('room'))->pluck('id') : $rooms->pluck('id');

        $rows = \App\Models\RoomMonitorAlert::with(['user:id,name', 'room:id,name'])->whereIn('room_id', $ids)
            ->when($request->query('student'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get();

        return response()->json(['alerts' => $rows->map(fn ($a) => [
            'id' => $a->id,
            'student' => $a->user?->name,
            'student_id' => $a->user_id,
            'room' => $a->room?->name,
            'type' => $a->type,
            'label' => \App\Services\RoomMonitor::ALERTS[$a->type][0] ?? $a->type,
            'orientation' => $a->orientation,
            'confidence' => $a->confidence,
            'status' => $a->status_snapshot,
            'duration_seconds' => $a->duration_seconds,
            'state' => $a->resolved_at ? 'selesai' : 'berlangsung',
            'at' => $a->occurred_at?->translatedFormat('d M H:i:s'),
        ])->values()])->header('Cache-Control', 'no-store, private');
    }

    public function history(Request $request, User $student)
    {
        abort_unless(LecturerMonitor::mayView($request->user(), $student), 403, 'Mahasiswa ini tidak berada di room yang sama dengan Anda.');

        return response()
            ->json(LecturerMonitor::history($request->user(), $student))
            ->header('Cache-Control', 'no-store, private');
    }

    /** Rekap hari ini dalam CSV (aman dari CSV/formula injection). */
    public function export(Request $request): StreamedResponse
    {
        $snap = LecturerMonitor::snapshot($request->user(), $request->query('room') ?: null);
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

        return response()->streamDownload(function () use ($snap, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['NIM', 'Nama', 'Kelas', 'Room', 'Status saat ini', 'Waktu belajar hari ini (menit)', 'Pomodoro hari ini', 'Sesi hari ini']);
            foreach ($snap['students'] as $s) {
                fputcsv($out, [$safe($s['nim']), $safe($s['name']), $safe($s['kelas']), $safe($s['room']), $s['status'], (int) floor($s['today_seconds'] / 60), $s['today_pomodoros'], $s['today_sessions']]);
            }
            fclose($out);
        }, 'rekap-belajar-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
