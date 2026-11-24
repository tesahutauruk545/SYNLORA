{{--
    Konfigurasi pemantauan per room (kamera/wajah/HP/tab). Dimuat di halaman room dan tugas supaya kamera tetap
    berjalan ketika mahasiswa berpindah antar-halaman room. Hanya untuk mahasiswa; dosen tidak dipantau.
    Variabel: $room
--}}
@if (auth()->user()?->isMahasiswa())
@php
    $rm = config('synlora.room_monitor');
    $path = fn (string $file, string $remote) => is_file(public_path('vendor/mediapipe/models/'.$file))
        ? [asset('vendor/mediapipe/models/'.$file)]
        : (config('synlora.mediapipe.allow_remote_model') ? [$remote] : []);
    $rmCfg = [
        'roomCode' => $room->code,
        'eventUrl' => route('rooms.monitor.event', $room),
        'csrf' => csrf_token(),
        'thresholds' => [
            'faceNotFoundMs' => $rm['face_not_found_ms'],
            'faceAwayMs' => $rm['face_away_ms'],
            'faceTurnMs' => $rm['face_turn_ms'],
            'phoneFrames' => $rm['phone_frames_needed'],
            'phoneMinConfidence' => $rm['phone_min_confidence'],
        ],
        'mediapipe' => [
            'wasmPath' => asset('vendor/mediapipe/wasm'),
            'faceModelPaths' => $path(config('synlora.mediapipe.model_file'), config('synlora.mediapipe.model_remote_url')),
            'objectModelPaths' => $path($rm['object_model_file'], $rm['object_model_remote_url']),
        ],
    ];
@endphp
<script type="application/json" id="room-monitor-config">@json($rmCfg)</script>
@endif
