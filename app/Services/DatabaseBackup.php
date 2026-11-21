<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Backup & pemulihan database tanpa bergantung pada mysqldump (aman di Windows/Laragon).
 *
 * - Hasil: backups/synlora-YYYYmmdd-HHMMSS.sql.gz + .json (manifest: jumlah baris per tabel + sha256).
 * - Pemulihan: memverifikasi checksum & header, membuat backup pengaman otomatis, memulihkan,
 *   lalu memverifikasi jumlah baris. Bila gagal, kondisi sebelumnya dipulihkan otomatis.
 */
class DatabaseBackup
{
    public const MAGIC = '-- SYNLORA BACKUP v1';

    public static function dir(?string $override = null): string
    {
        $dir = $override ?: storage_path('app/backups');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Folder backup tidak dapat dibuat: {$dir}");
        }

        return $dir;
    }

    /** @return string path file .sql.gz */
    public static function create(?string $dir = null, string $prefix = 'synlora'): string
    {
        $dir = self::dir($dir);
        $pdo = DB::connection()->getPdo();
        $name = $prefix.'-'.date('Ymd-His').'-'.substr(bin2hex(random_bytes(2)), 0, 4);
        $path = $dir.DIRECTORY_SEPARATOR.$name.'.sql.gz';
        $tmp = $path.'.part';

        $gz = gzopen($tmp, 'wb6');
        if (! $gz) {
            throw new RuntimeException('Tidak dapat menulis file backup (periksa izin folder/ruang disk).');
        }

        $counts = [];
        try {
            $write = function (string $s) use ($gz, $tmp) {
                if (gzwrite($gz, $s) === false) {
                    throw new RuntimeException('Gagal menulis backup (ruang disk penuh?).');
                }
            };
            $write(self::MAGIC."\n-- Dibuat: ".date('c')."\n-- Database: ".DB::connection()->getDatabaseName()."\n");
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");

            // Snapshot konsisten (InnoDB) selama backup berjalan.
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            foreach (self::tables() as $table) {
                $create = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(\PDO::FETCH_NUM)[1];
                $write("DROP TABLE IF EXISTS `{$table}`;\n{$create};\n");
                $counts[$table] = self::dumpRows($pdo, $table, $write);
            }
            $pdo->exec('COMMIT');
            $write("SET FOREIGN_KEY_CHECKS=1;\n-- END\n");
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            gzclose($gz);
            @unlink($tmp);
            throw $e;
        }
        gzclose($gz);
        rename($tmp, $path);

        file_put_contents($dir.DIRECTORY_SEPARATOR.$name.'.json', json_encode([
            'created_at' => date('c'),
            'database' => DB::connection()->getDatabaseName(),
            'tables' => $counts,
            'sha256' => hash_file('sha256', $path),
            'bytes' => filesize($path),
        ], JSON_PRETTY_PRINT));

        return $path;
    }

    /** Hapus backup lama, simpan $keep terbaru. */
    public static function prune(int $keep, ?string $dir = null): int
    {
        $files = glob(self::dir($dir).DIRECTORY_SEPARATOR.'synlora-*.sql.gz') ?: [];
        rsort($files);
        $removed = 0;
        foreach (array_slice($files, max(1, $keep)) as $f) {
            @unlink($f);
            @unlink(substr($f, 0, -7).'.json');
            $removed++;
        }

        return $removed;
    }

    /** Verifikasi file backup tanpa menyentuh database. @return array manifest */
    public static function verify(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("File backup tidak ditemukan: {$path}");
        }
        $manifestPath = substr($path, 0, -7).'.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('Manifest (.json) backup tidak ada. File tidak dapat diverifikasi.');
        }
        $m = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($m) || empty($m['sha256']) || ! isset($m['tables'])) {
            throw new RuntimeException('Manifest backup rusak.');
        }
        if (! hash_equals($m['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Checksum backup tidak cocok: file rusak atau diubah.');
        }
        $gz = gzopen($path, 'rb');
        $head = $gz ? gzgets($gz) : false;
        $gz && gzclose($gz);
        if (trim((string) $head) !== self::MAGIC) {
            throw new RuntimeException('Bukan file backup SYNLORA yang valid.');
        }

        return $m;
    }

    /**
     * Pulihkan database dari backup. @return array{safety:string, tables:int, rows:int}
     */
    public static function restore(string $path, ?string $dir = null): array
    {
        $manifest = self::verify($path);

        // Backup pengaman sebelum menimpa apa pun.
        $safety = self::create($dir, 'synlora-prerestore');

        try {
            self::run($path);
            self::assertCounts($manifest['tables']);
        } catch (\Throwable $e) {
            // Pulihkan keadaan sebelumnya.
            self::run($safety);
            throw new RuntimeException('Pemulihan gagal ('.$e->getMessage().'). Database dikembalikan ke keadaan sebelum pemulihan.', 0, $e);
        }

        return ['safety' => $safety, 'tables' => count($manifest['tables']), 'rows' => array_sum($manifest['tables'])];
    }

    // ------------------------------------------------------------------ internal

    /** @return string[] */
    private static function tables(): array
    {
        return array_map(
            fn ($r) => array_values((array) $r)[0],
            DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
        );
    }

    private static function dumpRows(\PDO $pdo, string $table, callable $write): int
    {
        $cols = array_map(fn ($r) => $r->Field, DB::select('SHOW COLUMNS FROM `'.$table.'`'));
        $colList = '`'.implode('`,`', $cols).'`';
        $hasId = in_array('id', $cols, true);
        $total = 0;
        $last = 0;
        $offset = 0;

        while (true) {
            $sql = $hasId
                ? "SELECT {$colList} FROM `{$table}` WHERE id > {$last} ORDER BY id LIMIT 500"
                : "SELECT {$colList} FROM `{$table}` LIMIT 500 OFFSET {$offset}";
            $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_NUM);
            if (! $rows) {
                break;
            }
            $values = [];
            foreach ($rows as $row) {
                $values[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).')';
            }
            $write("INSERT INTO `{$table}` ({$colList}) VALUES\n".implode(",\n", $values).";\n");
            $total += count($rows);
            $offset += 500;
            if ($hasId) {
                $last = (int) end($rows)[array_search('id', $cols, true)];
            }
        }

        return $total;
    }

    /** Jalankan file backup statement per statement (dipisah ";\n"; nilai string tidak memuat baris baru mentah). */
    private static function run(string $path): void
    {
        $pdo = DB::connection()->getPdo();
        $gz = gzopen($path, 'rb');
        if (! $gz) {
            throw new RuntimeException('File backup tidak dapat dibaca.');
        }
        $buffer = '';
        try {
            while (! gzeof($gz)) {
                $line = gzgets($gz);
                if ($line === false) {
                    break;
                }
                if ($buffer === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                    continue;
                }
                $buffer .= $line;
                if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                    $pdo->exec($buffer);
                    $buffer = '';
                }
            }
            if (trim($buffer) !== '') {
                throw new RuntimeException('File backup terpotong (statement terakhir tidak lengkap).');
            }
        } finally {
            gzclose($gz);
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable) {}
        }
    }

    private static function assertCounts(array $expected): void
    {
        foreach ($expected as $table => $n) {
            $now = (int) DB::table($table)->count();
            if ($now !== (int) $n) {
                throw new RuntimeException("Jumlah baris tabel {$table} tidak cocok setelah pemulihan ({$now} dari {$n}).");
            }
        }
    }
}
