<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Kotak masuk notifikasi milik pengguna yang login (tidak ada akses ke notifikasi orang lain). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
        $items = UserNotification::where('user_id', $uid)->orderByDesc('id')->limit(30)->get();

        return response()->json([
            'unread' => UserNotification::where('user_id', $uid)->whereNull('read_at')->count(),
            'items' => $items->map->toPayload()->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 404);
        $notification->read_at ??= now();
        $notification->save();

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
