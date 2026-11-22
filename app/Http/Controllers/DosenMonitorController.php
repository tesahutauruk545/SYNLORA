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
