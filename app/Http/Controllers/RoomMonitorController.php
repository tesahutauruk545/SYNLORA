<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\RoomMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Menerima sinyal pemantauan mahasiswa per room (kamera/wajah/HP/tab). Tanpa gambar; hanya jenis event. */
class RoomMonitorController extends Controller
{
    public function event(Request $request, Room $room): JsonResponse
    {
        $this->authorize('view', $room);
        $user = $request->user();
        if (! $user->isMahasiswa()) {
            return response()->json(['ok' => true, 'ignored' => 'not_monitored']); // dosen tidak dipantau
        }

        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', RoomMonitor::EVENTS)],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'orientation' => ['nullable', 'string', 'in:vertical,horizontal'],
            'hidden' => ['nullable', 'boolean'],
        ]);

        $type = $data['type'];
        unset($data['type']);

        return response()->json(RoomMonitor::ingest($user, $room, $type, $data));
    }
}
