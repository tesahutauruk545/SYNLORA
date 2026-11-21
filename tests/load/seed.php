<?php
// Mengisi database dengan 1 dosen + N mahasiswa + 1 room untuk simulasi beban.
// Jalankan: php artisan tinker --execute="require 'tests/load/seed.php';"
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$n = (int) (getenv('LOAD_STUDENTS') ?: 40);
$pw = Hash::make('password123');

$dosen = User::firstOrNew(['email' => 'dosen.load@example.com']);
$dosen->forceFill(['name' => 'Dosen Uji Beban', 'password' => $pw, 'role' => 'dosen'])->save();

$room = Room::where('owner_id', $dosen->id)->first();
if (! $room) {
    $room = Room::create(['name' => 'Kelas Uji Beban', 'owner_id' => $dosen->id, 'code' => 'LOAD01', 'target_minutes' => 25]);
    $room->members()->syncWithoutDetaching([$dosen->id => ['role' => 'owner', 'focus_status' => 'ABU']]);
}

for ($i = 1; $i <= $n; $i++) {
    $u = User::firstOrNew(['email' => sprintf('mhs%02d.load@example.com', $i)]);
    $u->forceFill(['name' => sprintf('Mahasiswa Uji %02d', $i), 'password' => $pw, 'role' => 'mahasiswa',
        'nim' => sprintf('2203%06d', $i), 'kelas' => $i % 2 ? 'A' : 'B'])->save();
    $room->members()->syncWithoutDetaching([$u->id => ['role' => 'member', 'focus_status' => 'ABU']]);
}
echo "Seed selesai: 1 dosen, $n mahasiswa, room {$room->code} (id {$room->id})\n";
