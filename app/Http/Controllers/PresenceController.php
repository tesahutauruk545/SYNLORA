<?php

namespace App\Http\Controllers;

use App\Services\OnlinePresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ping ringan "saya sedang membuka SYNLORA". Hanya mencatat waktu terakhir terlihat milik pengguna sendiri. */
class PresenceController extends Controller
{
    public function ping(Request $request): JsonResponse
    {
        OnlinePresence::touch((int) $request->user()->id);

        return response()->json(['ok' => true, 'server_time' => now()->getTimestamp()])
            ->header('Cache-Control', 'no-store, private');
    }
}
