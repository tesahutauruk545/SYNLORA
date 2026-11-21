<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Room;
use App\Models\StudySession;
use App\Services\LecturerMonitor;
use App\Services\RoomPresence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

class RoomController extends Controller
{
    public function create()
    {
        return view('rooms.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'target_minutes' => 'required|integer|min:5|max:120',
            'max_members' => 'nullable|integer|min:2|max:300',
        ]);

        $room = DB::transaction(function () use ($data) {
            $room = Room::create([
                'name' => trim($data['name']),
                'owner_id' => Auth::id(),
                'target_minutes' => $data['target_minutes'],
                'max_members' => $data['max_members'] ?? config('synlora.room_max_members', 60),
            ]);

            $room->members()->attach(Auth::id(), ['role' => 'owner', 'focus_status' => 'ABU', 'last_active' => now()]);

            return $room;
        });

        return redirect()->route('rooms.show', $room)->with('status', 'Room berhasil dibuat. Bagikan kode '.$room->code.' kepada teman belajar Anda.');
    }

    public function join()
    {
        return view('rooms.join');
    }

    public function joinRoom(Request $request)
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{6}$/'],
        ], [
            'code.regex' => 'Kode room harus 6 karakter huruf/angka.',
        ]);

        // Pencegah tebak-tebakan kode: 8 kode salah per 10 menit per pengguna (dan per IP).
        $keys = ['room-join-fail:u'.$request->user()->id, 'room-join-fail:ip'.$request->ip()];
        foreach ($keys as $k) {
            if (RateLimiter::tooManyAttempts($k, $k[15] === 'u' ? 8 : 40)) {
                $wait = ceil(RateLimiter::availableIn($k) / 60);

                return back()->withInput()->withErrors(['code' => "Terlalu banyak kode salah. Coba lagi sekitar {$wait} menit lagi."]);
            }
        }

        $room = Room::where('code', $request->code)->first();

        if (! $room) {
            foreach ($keys as $k) {
                RateLimiter::hit($k, 600);
            }

            return back()->withInput()->withErrors(['code' => 'Room dengan kode tersebut tidak ditemukan.']);
        }

        // Kunci baris room agar pendaftaran serentak tidak melewati kapasitas / membuat anggota ganda.
        $result = DB::transaction(function () use ($room, $request) {
            $locked = Room::whereKey($room->id)->lockForUpdate()->first();

            if ($locked->hasMember($request->user())) {
                return 'already';
            }
            if ($locked->isFull()) {
                return 'full';
            }
            $locked->members()->syncWithoutDetaching([
                $request->user()->id => ['role' => 'member', 'focus_status' => 'ABU', 'last_active' => now()],
            ]);

            return 'joined';
        });

        if ($result === 'joined') {
            LecturerMonitor::forgetRoom($room, [$request->user()->id]);
        }

        if ($result === 'full') {
            return back()->withInput()->withErrors(['code' => 'Room sudah penuh (kapasitas '.($room->max_members ?: config('synlora.room_max_members')).' anggota). Hubungi owner room.']);
        }

        return redirect()->route('rooms.show', $room);
    }

    /** Ubah nama, target, dan kapasitas room (owner). */
    public function update(Request $request, Room $room)
    {
        $this->authorize('manage', $room);

        $count = $room->members()->count();
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'target_minutes' => 'required|integer|min:5|max:120',
            'max_members' => ['required', 'integer', 'min:'.max(2, $count), 'max:300'],
        ], [
            'max_members.min' => "Kapasitas tidak boleh kurang dari jumlah anggota saat ini ({$count}).",
        ]);

        $update = ['name' => trim($data['name']), 'target_minutes' => $data['target_minutes'], 'max_members' => $data['max_members']];

        // Mode ketat dan kebijakan AI hanya bermakna untuk room yang dimiliki DOSEN (yang meninjau datanya).
        if ($request->user()->isDosen()) {
            $policy = $request->validate([
                'strict_mode' => 'nullable|boolean',
                'ai_policy' => 'nullable|in:forbidden,declare,allowed',
            ]);
            $update['strict_mode'] = (bool) ($policy['strict_mode'] ?? false);
            $update['ai_policy'] = $policy['ai_policy'] ?? $room->ai_policy ?? 'declare';
        }

        $room->update($update);

        return back()->with('status', 'Pengaturan room diperbarui.');
    }

    /** Ganti kode room (mis. kode bocor). Anggota yang sudah bergabung tidak terpengaruh. */
    public function regenerateCode(Room $room)
    {
        $this->authorize('manage', $room);

        $room->update(['code' => Room::generateUniqueCode()]);

        return redirect()->route('rooms.show', $room)->with('status', 'Kode room diganti menjadi '.$room->code.'.');
    }

    public function show(Request $request, Room $room)
    {
        $user = $request->user();

        if (! $room->hasMember($user)) {
            return redirect()->route('rooms.join')->withErrors(['code' => 'Anda bukan anggota room ini. Masukkan kode room untuk bergabung.']);
        }

        $isOwner = $room->isOwnedBy($user);
        $canMonitor = $user->can('monitor', $room);

        $members = RoomPresence::forRoom($room, $user);

        // Satu pengguna hanya boleh punya satu sesi terbuka.
        $openSession = StudySession::where('user_id', $user->id)->open()->with('room:id,name,code')->latest()->first();
        $openSession?->sync();
        if ($openSession && ! $openSession->isOpen()) {
            $openSession = null;
        }

        $activeSession = $openSession && (int) $openSession->room_id === (int) $room->id ? $openSession : null;
        $otherSession = $openSession && ! $activeSession ? $openSession : null;

        $materials = $room->materials()->with('uploader:id,name')->latest()->get();

        $assignments = $room->assignments()
            ->withCount('submissions')
            ->with(['submissions' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->get();

        // Rekap sesi anggota = data monitoring: hanya untuk dosen anggota room, hanya sesi milik mahasiswa.
        $recentSessions = $canMonitor
            ? $room->sessions()->with('user:id,name')
                ->whereHas('user', fn ($q) => $q->where('role', 'mahasiswa'))
                ->where('status', StudySession::ENDED)->latest('started_at')->limit(10)->get()
            : collect();

        return view('rooms.show', compact(
            'room', 'members', 'isOwner', 'canMonitor', 'activeSession', 'otherSession',
            'materials', 'assignments', 'recentSessions'
        ));
    }

    /** Hapus room (owner). Riwayat sesi pribadi anggota tetap tersimpan (room_id menjadi NULL). */
    public function destroy(Request $request, Room $room)
    {
        $this->authorize('delete', $room);

        // Penghapusan tidak dapat dibatalkan: pemilik harus mengetik ulang nama room.
        $typed = mb_strtolower(trim((string) $request->input('confirm_name')));
        if ($typed === '' || $typed !== mb_strtolower(trim($room->name))) {
            return back()->withErrors(['confirm_name' => 'Nama room yang diketik tidak cocok. Room tidak dihapus.']);
        }

        $room->sessions()->open()->get()->each->closeBecauseLeftRoom();
        LecturerMonitor::forgetRoom($room);

        Storage::disk('local')->deleteDirectory("rooms/{$room->id}");
        $room->delete();

        return redirect()->route('dashboard')->with('status', 'Room dihapus.');
    }

    public function leave(Request $request, Room $room)
    {
        $this->authorize('leave', $room);

        $room->sessions()->where('user_id', $request->user()->id)->open()->get()->each->closeBecauseLeftRoom();
        $room->members()->detach($request->user()->id);
        LecturerMonitor::forgetRoom($room, [$request->user()->id]);

        return redirect()->route('dashboard')->with('status', 'Anda keluar dari room '.$room->name.'.');
    }

    public function removeMember(Room $room, int $member)
    {
        $this->authorize('manage', $room);

        abort_if((int) $member === (int) $room->owner_id, 422, 'Owner tidak dapat dikeluarkan.');
        abort_unless($room->members()->where('users.id', $member)->exists(), 404);

        $room->sessions()->where('user_id', $member)->open()->get()->each->closeBecauseLeftRoom();
        $room->members()->detach($member);
        LecturerMonitor::forgetRoom($room, [$member]);

        return back()->with('status', 'Anggota dikeluarkan dari room.');
    }

    /**
     * Polling feed aktivitas + daftar anggota.
     *  - Dosen anggota room : melihat aktivitas mahasiswa di room ini (dan miliknya sendiri).
     *  - Selain itu (mahasiswa, termasuk owner) : hanya aktivitasnya sendiri; anggota lain hanya tampil sebagai presence netral.
     */
    public function activities(Request $request, Room $room)
    {
        $this->authorize('view', $room);

        $user = $request->user();
        $canMonitor = $user->can('monitor', $room);

        $query = ActivityLog::where('room_id', $room->id)->with('user:id,name');
        if ($canMonitor) {
            $query->where(fn ($q) => $q->where('user_id', $user->id)
                ->orWhereIn('user_id', $room->members()->where('users.role', 'mahasiswa')->select('users.id')));
        } else {
            $query->where('user_id', $user->id);
        }

        $activities = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get()->map(fn ($log) => [
            'id' => $log->id,
            'user_name' => $log->user?->name ?? '—',
            'is_me' => (int) $log->user_id === (int) $user->id,
            'event_type' => $log->event_type,
            'details' => $log->details,
            'occurred_at' => $log->occurred_at?->format('H:i:s'),
        ]);

        return response()->json([
            'scope' => $canMonitor ? 'room' : 'self',
            'activities' => $activities,
            'members' => RoomPresence::forRoom($room, $user)->values(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
