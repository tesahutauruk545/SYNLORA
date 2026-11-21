<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingEvent;
use App\Models\MeetingFocusLog;
use App\Models\Room;
use App\Realtime\RealtimeToken;
use App\Services\JitsiService;
use App\Services\MeetingReport;
use App\Services\MeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Virtual Meeting (Jitsi) per room. Semua endpoint wajib login dan hanya untuk
 * anggota room (RoomPolicy@view); mengakhiri meeting untuk semua hanya owner.
 */
class MeetingController extends Controller
{
    public function __construct(
        protected MeetingService $meetings,
        protected JitsiService $jitsi,
    ) {
    }

    /** Halaman meeting. Membuka halaman TIDAK memulai/menggabungkan meeting; itu terjadi saat tombol Gabung ditekan. */
    public function show(Request $request, Room $room)
    {
        $this->authorize('view', $room);

        $user = $request->user();
        $isOwner = $room->isOwnedBy($user);
        $live = $this->meetings->live($room);

        $config = [
            'csrf' => csrf_token(),
            'isOwner' => $isOwner,
            'myId' => $user->id,
            'roomName' => $room->name,
            'roomUrl' => route('rooms.show', $room),
            'urls' => [
                'join' => route('meeting.join', $room),
                'signal' => route('meeting.signal', $room),
                'leave' => route('meeting.leave', $room),
                'end' => route('meeting.end', $room),
                'state' => route('meeting.state', $room),
                'token' => route('meeting.token', $room),
                'report' => route('meeting.report', [$room, '__ID__']),
            ],
            'heartbeatSeconds' => (int) config('synlora.meeting.heartbeat_seconds'),
            'staleSeconds' => (int) config('synlora.meeting.stale_seconds'),
            'faceGraceMs' => (int) config('synlora.meeting.face_grace_ms'),
            'mediapipe' => [
                'wasmPath' => asset('vendor/mediapipe/wasm'),
                'modelPaths' => [
                    asset('vendor/mediapipe/models/blaze_face_short_range.tflite'),
                    'https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite',
                ],
            ],
            'live' => $live ? ['started_at' => $live->started_at->getTimestamp()] : null,
        ];

        return view('meetings.show', compact('room', 'isOwner', 'live', 'config'));
    }

    /** Masuk ke meeting: mencatat kehadiran dan mengembalikan konfigurasi Jitsi + token real-time. */
    public function join(Request $request, Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        $user = $request->user();
        $isOwner = $room->isOwnedBy($user);

        [$meeting, $attendance, $rejoined, $started] = $this->meetings->join($room, $user);

        return response()->json([
            'meeting' => ['id' => $meeting->id, 'started_at' => $meeting->started_at->getTimestamp(), 'started_now' => $started],
            'attendance_id' => $attendance->id,
            'rejoined' => $rejoined,
            'jitsi' => $this->jitsi->clientConfig($meeting, $user, $isOwner),
            'realtime' => $this->realtimeInfo($request, $room, $isOwner),
            'snapshot' => $this->meetings->snapshot($meeting, $room, $user),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Detak + status fokus (hijau/merah/abu) + event opsional. */
    public function signal(Request $request, Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', MeetingFocusLog::STATUSES)],
            'reason' => ['nullable', 'string', 'max:255'],
            'event_type' => ['nullable', 'string', 'in:'.implode(',', MeetingEvent::CLIENT_EVENTS)],
            'details' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->meetings->signal(
            $room,
            $request->user(),
            $data['status'],
            $data['reason'] ?? null,
            $data['event_type'] ?? null,
            $data['details'] ?? null
        );

        if (isset($result['error'])) {
            return response()->json(['ok' => false, 'error' => $result['error'], 'reason' => $this->meetings->lastEndReason($room)], 409);
        }

        return response()->json([
            'ok' => true,
            'rejoined' => $result['rejoined'],
            'attendance_id' => $result['attendance']->id,
        ]);
    }

    public function leave(Request $request, Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        $attendanceId = $request->filled('attendance_id') ? (int) $request->input('attendance_id') : null;
        $this->meetings->leave($room, $request->user(), 'left', $attendanceId);

        return response()->json(['ok' => true]);
    }

    /** Hanya owner room. */
    public function end(Request $request, Room $room): JsonResponse
    {
        $this->authorize('manage', $room);

        $ended = $this->meetings->end($room, $request->user());

        return response()->json(['ok' => true, 'ended' => $ended]);
    }

    /** Snapshot status (polling cadangan). Owner: semua peserta. Anggota: hanya dirinya. */
    public function state(Request $request, Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        $meeting = $this->meetings->live($room);

        return response()->json(
            $meeting
                ? $this->meetings->snapshot($meeting, $room, $request->user())
                : ['meeting' => null, 'ended_reason' => $this->meetings->lastEndReason($room), 'scope' => $room->isOwnedBy($request->user()) ? 'room' : 'self', 'participants' => [], 'server_time' => now()->getTimestamp()]
        )->header('Cache-Control', 'no-store, private');
    }

    public function token(Request $request, Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        return response()->json($this->realtimeInfo($request, $room, $room->isOwnedBy($request->user())))
            ->header('Cache-Control', 'no-store, private');
    }

    public function report(Request $request, Room $room, Meeting $meeting)
    {
        $this->authorize('view', $room);
        $this->authorize('viewReport', $meeting);

        $this->meetings->reap($meeting);

        return view('meetings.report', MeetingReport::build($meeting->fresh(), $room, $request->user()) + ['room' => $room]);
    }

    private function realtimeInfo(Request $request, Room $room, bool $isOwner): array
    {
        if (! config('synlora.realtime.enabled')) {
            return ['enabled' => false];
        }

        $url = config('synlora.realtime.public_url')
            ?: ($request->isSecure() ? 'wss' : 'ws').'://'.$request->getHost().':'.config('synlora.realtime.port');

        return [
            'enabled' => true,
            'url' => rtrim($url, '/').'/ws',
            'token' => RealtimeToken::issue($request->user(), $room, $isOwner),
            'expires_in' => (int) config('synlora.realtime.token_ttl'),
        ];
    }
}
