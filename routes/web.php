<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DosenMonitorController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RoomMonitorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

// Pemeriksaan kesehatan layanan (tanpa login, tanpa data sensitif). 503 bila database tidak dapat dijangkau.
Route::get('/health', function () {
    $db = true;
    try {
        \Illuminate\Support\Facades\DB::select('select 1');
    } catch (\Throwable $e) {
        $db = false;
    }
    $storage = is_writable(storage_path('app')) && is_writable(storage_path('logs'));

    return response()->json(['status' => $db && $storage ? 'ok' : 'degraded', 'database' => $db ? 'ok' : 'down', 'storage' => $storage ? 'ok' : 'tidak dapat ditulis', 'time' => now()->toIso8601String()], $db && $storage ? 200 : 503)
        ->header('Cache-Control', 'no-store');
})->middleware('throttle:120,1')->name('health');

// Landing page: halaman pertama, dapat dibuka tanpa login.
Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ------------------------------------------------------------ Room
    Route::get('/rooms/create', [RoomController::class, 'create'])->name('rooms.create');
    Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
    Route::get('/rooms/join', [RoomController::class, 'join'])->name('rooms.join');
    Route::post('/rooms/join', [RoomController::class, 'joinRoom'])->middleware('throttle:10,1')->name('rooms.joinRoom');

    Route::scopeBindings()->group(function () {
        Route::get('/rooms/{room}', [RoomController::class, 'show'])->name('rooms.show');
        Route::patch('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
        Route::post('/rooms/{room}/regenerate-code', [RoomController::class, 'regenerateCode'])->middleware('throttle:6,1')->name('rooms.regenerate');
        Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');
        Route::post('/rooms/{room}/leave', [RoomController::class, 'leave'])->name('rooms.leave');
        Route::delete('/rooms/{room}/members/{member}', [RoomController::class, 'removeMember'])->whereNumber('member')->name('rooms.members.remove');
        Route::get('/rooms/{room}/activities', [RoomController::class, 'activities'])->middleware('throttle:60,1')->name('rooms.getActivities');
        Route::post('/rooms/{room}/sessions', [SessionController::class, 'storeInRoom'])->name('rooms.sessions.store');

        // Pemantauan per room (kamera/wajah/HP/tab), terpisah dari Pomodoro dan tugas
        Route::post('/rooms/{room}/monitor/events', [RoomMonitorController::class, 'event'])->middleware('throttle:240,1')->name('rooms.monitor.event');

        // Materi
        Route::post('/rooms/{room}/materials', [MaterialController::class, 'store'])->name('materials.store');
        Route::get('/rooms/{room}/materials/{material}/download', [MaterialController::class, 'download'])->name('materials.download');
        Route::delete('/rooms/{room}/materials/{material}', [MaterialController::class, 'destroy'])->name('materials.destroy');

        // Tugas & pengumpulan
        Route::post('/rooms/{room}/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
        Route::get('/rooms/{room}/assignments/{assignment}', [AssignmentController::class, 'show'])->name('assignments.show');
        Route::delete('/rooms/{room}/assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.destroy');
        Route::post('/rooms/{room}/assignments/{assignment}/submit', [AssignmentController::class, 'submit'])->name('assignments.submit');
        Route::post('/rooms/{room}/assignments/{assignment}/submissions/{submission}/grade', [AssignmentController::class, 'grade'])->name('assignments.grade');
        Route::get('/rooms/{room}/assignments/{assignment}/submissions/{submission}/download', [AssignmentController::class, 'downloadSubmission'])->name('assignments.submissions.download');
    });

    // ------------------------------------------------ Notifikasi
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('throttle:120,1')->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification')->name('notifications.read');

    // ------------------------------------------------ Target harian
    Route::patch('/target-harian', [DashboardController::class, 'updateGoal'])->name('goal.update');

    // ------------------------------------------- Monitoring (khusus dosen)
    Route::middleware('role:dosen')->prefix('dosen')->group(function () {
        Route::get('/', [DosenMonitorController::class, 'index'])->name('dosen.monitor');
        Route::get('/data', [DosenMonitorController::class, 'data'])->middleware('throttle:40,1')->name('dosen.data');
        Route::get('/mahasiswa/{student}/riwayat', [DosenMonitorController::class, 'history'])->whereNumber('student')->middleware('throttle:60,1')->name('dosen.history');
        Route::get('/peringatan', [DosenMonitorController::class, 'alerts'])->middleware('throttle:60,1')->name('dosen.alerts');
        Route::get('/ekspor', [DosenMonitorController::class, 'export'])->middleware('throttle:10,1')->name('dosen.export');
    });

    // -------------------------------------------- Pomodoro & sesi belajar
    Route::get('/pomodoro', [SessionController::class, 'solo'])->name('pomodoro.index');
    Route::post('/pomodoro/sessions', [SessionController::class, 'storeSolo'])->name('pomodoro.start');

    Route::get('/riwayat', [SessionController::class, 'index'])->name('sessions.index');
    Route::get('/sessions/{session}/recap', [SessionController::class, 'recap'])->name('sessions.recap');
    Route::get('/sessions/{session}/state', [SessionController::class, 'state'])->middleware('throttle:120,1')->name('sessions.state');
    Route::post('/sessions/{session}/pause', [SessionController::class, 'pause'])->name('sessions.pause');
    Route::post('/sessions/{session}/resume', [SessionController::class, 'resume'])->name('sessions.resume');
    Route::post('/sessions/{session}/finish', [SessionController::class, 'finish'])->name('sessions.finish');
    Route::post('/sessions/{session}/heartbeat', [SessionController::class, 'heartbeat'])->middleware('throttle:60,1')->name('sessions.heartbeat');
    Route::post('/sessions/{session}/events', [SessionController::class, 'events'])->middleware('throttle:240,1')->name('sessions.events');
});

require __DIR__.'/auth.php';
