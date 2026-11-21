<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Pemakaian: ->middleware('role:dosen'). Peran dibaca dari database, bukan dari input pengguna. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            abort(403, 'Halaman ini hanya untuk peran: '.implode(', ', $roles).'.');
        }

        return $next($request);
    }
}
