<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Database tidak dapat dijangkau / koneksi diputus / terlalu banyak koneksi: tampilkan 503 yang ramah,
        // bukan halaman galat atau jejak kode. Klien JavaScript menerima JSON dan mencoba lagi sendiri.
        $this->renderable(function (\Illuminate\Database\QueryException|\PDOException $e, $request) {
            if (! preg_match('/\[(2002|2006|2013|1040|1045|1049)\]|Too many connections|server has gone away|Connection refused/i', $e->getMessage())) {
                return null;
            }
            report($e);

            if ($request->expectsJson() || $request->is('sessions/*', 'dosen/data', 'rooms/*/activities')) {
                return response()->json(['ok' => false, 'code' => 'db_unavailable', 'message' => 'Database sedang tidak dapat dijangkau. Coba lagi sebentar lagi.'], 503)->header('Retry-After', '10');
            }

            return response()->view('errors.db-unavailable', [], 503)->header('Retry-After', '10');
        });
    }
}
