<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Room;
use App\Models\Submission;
use App\Services\NotificationCenter;
use App\Services\RoomMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AssignmentController extends Controller
{
    /** Buat tugas: hanya owner room. */
    public function store(Request $request, Room $room)
    {
        $this->authorize('manage', $room);

        $data = $request->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:5000',
            'due_at' => 'nullable|date|after:now',
            'allow_late' => 'nullable|boolean',
        ], [
            'due_at.after' => 'Batas waktu harus setelah waktu sekarang.',
        ]);

        $assignment = $room->assignments()->create([
            'user_id' => $request->user()->id,
            'title' => trim($data['title']),
            'description' => $data['description'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'allow_late' => $request->boolean('allow_late', true),
        ]);

        // Notifikasi tugas baru ke mahasiswa room ini. Kegagalan notifikasi tidak boleh membatalkan pembuatan tugas.
        try {
            NotificationCenter::assignmentPublished($assignment, $room, $request->user());
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('status', 'Tugas dibuat.');
    }

    /** Detail tugas. Owner melihat semua pengumpulan; anggota hanya melihat pengumpulannya sendiri. */
    public function show(Request $request, Room $room, Assignment $assignment)
    {
        $this->authorize('view', $room);

        $user = $request->user();
        $isOwner = $room->isOwnedBy($user);

        $mine = $assignment->submissions()->where('user_id', $user->id)->first();

        // Aktivitas tugas dicatat server di keadaan pemantauan (tidak menyentuh kamera maupun Pomodoro).
        if (! $isOwner || $user->isMahasiswa()) {
            RoomMonitor::setTask($user, $room, $assignment->id, $mine ? 'submitted' : 'opened');
        }

        $submissions = $isOwner
            ? $assignment->submissions()->with('user:id,name')->orderBy('submitted_at')->get()
            : collect();

        $notSubmitted = $isOwner
            ? $room->members()->where('users.id', '!=', $room->owner_id)
                ->whereNotIn('users.id', $submissions->pluck('user_id'))->orderBy('users.name')->get(['users.id', 'users.name'])
            : collect();

        return view('assignments.show', compact('room', 'assignment', 'isOwner', 'mine', 'submissions', 'notSubmitted'));
    }

    public function destroy(Room $room, Assignment $assignment)
    {
        $this->authorize('manage', $room);

        Storage::disk('local')->deleteDirectory("rooms/{$room->id}/submissions/{$assignment->id}");
        $assignment->delete();

        return redirect()->route('rooms.show', $room)->with('status', 'Tugas dihapus.');
    }

    /** Kumpulkan / ganti pengumpulan: anggota room selain owner. */
    public function submit(Request $request, Room $room, Assignment $assignment)
    {
        $this->authorize('view', $room);
        abort_if($room->isOwnedBy($request->user()), 403, 'Owner room tidak mengumpulkan tugas.');

        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
            'file' => 'nullable|file|max:10240|mimes:'.MaterialController::ALLOWED,
        ], [
            'file.max' => 'Ukuran file maksimal 10 MB.',
            'file.mimes' => 'Format file harus salah satu dari: '.str_replace(',', ', ', MaterialController::ALLOWED).'.',
        ]);

        if ($assignment->isPastDue() && ! $assignment->allow_late) {
            return back()->withErrors(['file' => 'Batas waktu sudah lewat dan pengumpulan telah ditutup oleh dosen.'])->withInput();
        }

        $newPath = null;

        try {
            $result = DB::transaction(function () use ($request, $room, $assignment, $data, &$newPath) {
                // Kunci baris pengumpulan agar penilaian & pengumpulan ulang tidak saling menimpa.
                $existing = Submission::where('assignment_id', $assignment->id)->where('user_id', $request->user()->id)->lockForUpdate()->first();

                if (! $request->hasFile('file') && empty($data['notes']) && ! $existing?->file_path) {
                    return ['error' => 'Lampirkan file atau tulis catatan jawaban.'];
                }
                if ($existing && $existing->score !== null) {
                    return ['error' => 'Tugas sudah dinilai dan tidak dapat diubah lagi.'];
                }

                $attrs = [
                    'notes' => $data['notes'] ?? null,
                    'submitted_at' => now(),
                    'is_late' => $assignment->isPastDue(),
                ];
                $oldPath = null;

                if ($request->hasFile('file')) {
                    $file = $request->file('file');
                    $incoming = $file->getSize() - (int) ($existing?->size_bytes ?? 0);
                    if (MaterialController::roomBytes($room) + $incoming > config('synlora.room_storage_mb', 500) * 1048576) {
                        return ['error' => 'Penyimpanan room penuh. Hubungi dosen/owner room.'];
                    }
                    $newPath = $file->store("rooms/{$room->id}/submissions/{$assignment->id}", 'local');
                    $oldPath = $existing?->file_path;
                    $attrs += [
                        'file_path' => $newPath,
                        'original_name' => MaterialController::safeName($file->getClientOriginalName()),
                        'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                        'size_bytes' => $file->getSize(),
                    ];
                }

                Submission::updateOrCreate(['assignment_id' => $assignment->id, 'user_id' => $request->user()->id], $attrs);

                return ['late' => $attrs['is_late'], 'old' => $oldPath];
            });
        } catch (\Throwable $e) {
            // Simpan data gagal: buang file baru agar tidak menjadi berkas yatim, data lama tetap utuh.
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $e;
        }

        if (isset($result['error'])) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }

            return back()->withErrors(['file' => $result['error']])->withInput();
        }
        if (! empty($result['old'])) {
            Storage::disk('local')->delete($result['old']);
        }

        RoomMonitor::setTask($request->user(), $room, $assignment->id, 'submitted');

        return back()->with('status', $result['late'] ? 'Tugas terkumpul (terlambat).' : 'Tugas berhasil dikumpulkan.');
    }

    /** Beri nilai: hanya owner room. */
    public function grade(Request $request, Room $room, Assignment $assignment, Submission $submission)
    {
        $this->authorize('manage', $room);

        $data = $request->validate([
            'score' => 'required|integer|min:0|max:100',
            'feedback' => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($submission, $data) {
            Submission::whereKey($submission->id)->lockForUpdate()->first()->update($data + ['graded_at' => now()]);
        });

        return back()->with('status', 'Nilai disimpan.');
    }

    /** Unduh file pengumpulan: pemilik pengumpulan atau owner room. */
    public function downloadSubmission(Request $request, Room $room, Assignment $assignment, Submission $submission)
    {
        $this->authorize('view', $room);

        $allowed = $room->isOwnedBy($request->user()) || (int) $submission->user_id === (int) $request->user()->id;
        abort_unless($allowed, 403);
        abort_unless($submission->file_path && Storage::disk('local')->exists($submission->file_path), 404, 'File tidak ditemukan.');

        return Storage::disk('local')->download($submission->file_path, $submission->original_name);
    }
}
