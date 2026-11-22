<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\LiveKitToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Token Live Meeting (LiveKit). Hanya anggota sah room yang dapat meminta token. */
class LiveMeetingController extends Controller
{
    public function token(Request $request, Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        if (! LiveKitToken::configured()) {
            return response()->json(['message' => 'Study Meet belum dikonfigurasi di server (LIVEKIT_URL, LIVEKIT_API_KEY, LIVEKIT_API_SECRET).'], 503)
                ->header('Cache-Control', 'no-store');
        }

        $user = $request->user();

        return response()->json([
            'url' => config('livekit.url'),
            'token' => LiveKitToken::make(
                'u'.$user->id,
                $user->name,
                LiveKitToken::roomName($room->id),
                ['role' => $user->isDosen() ? 'dosen' : 'mahasiswa']
            ),
        ])->header('Cache-Control', 'no-store, private');
    }
}
