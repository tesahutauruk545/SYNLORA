<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MaterialController extends Controller
{
    public const ALLOWED = 'pdf,doc,docx,ppt,pptx,xls,xlsx,txt,csv,zip,png,jpg,jpeg';

    /** Unggah materi: hanya owner room. */
    public function store(Request $request, Room $room)
    {
        $this->authorize('manage', $room);

        $data = $request->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'file' => 'required|file|max:10240|mimes:'.self::ALLOWED,
        ], [
            'file.max' => 'Ukuran file maksimal 10 MB.',
            'file.mimes' => 'Format file harus salah satu dari: '.str_replace(',', ', ', self::ALLOWED).'.',
        ]);

        $file = $request->file('file');

        if (self::roomBytes($room) + $file->getSize() > config('synlora.room_storage_mb', 500) * 1048576) {
            return back()->withErrors(['file' => 'Penyimpanan room penuh (batas '.config('synlora.room_storage_mb', 500).' MB). Hapus materi lama terlebih dahulu.'])->withInput();
        }

        $path = $file->store("rooms/{$room->id}/materials", 'local');

        try {
            $room->materials()->create([
                'user_id' => $request->user()->id,
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'file_path' => $path,
                'original_name' => self::safeName($file->getClientOriginalName()),
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        } catch (\Throwable $e) {
            // Jangan meninggalkan file yatim bila penyimpanan data gagal.
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('status', 'Materi berhasil diunggah.');
    }

    /** Unduh materi: semua anggota room. File disimpan privat, tidak ada URL publik. */
    public function download(Room $room, Material $material)
    {
        $this->authorize('view', $room);

        abort_unless(Storage::disk('local')->exists($material->file_path), 404, 'File tidak ditemukan.');

        return Storage::disk('local')->download($material->file_path, $material->original_name);
    }

    public function destroy(Room $room, Material $material)
    {
        $this->authorize('manage', $room);

        Storage::disk('local')->delete($material->file_path);
        $material->delete();

        return back()->with('status', 'Materi dihapus.');
    }

    /** Nama file aman untuk ditampilkan/diunduh: tanpa path, karakter kontrol, atau titik di depan. */
    public static function safeName(?string $name): string
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F"<>|:*?]+/u', '', $name) ?? '';
        $name = ltrim(trim($name), '.');

        return mb_substr($name !== '' ? $name : 'berkas', 0, 150);
    }

    /** Total byte materi + pengumpulan tugas pada sebuah room. */
    public static function roomBytes(Room $room): int
    {
        $m = (int) $room->materials()->sum('size_bytes');
        $s = (int) \App\Models\Submission::whereIn('assignment_id', $room->assignments()->select('id'))->sum('size_bytes');

        return $m + $s;
    }
}
