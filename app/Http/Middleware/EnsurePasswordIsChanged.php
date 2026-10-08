<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun baru masuk memakai kata sandi bawaan (guru dan admin `tekara123`, siswa
 * nomor induknya), jadi halaman diganti dulu sebelum halaman lain bisa dipakai.
 *
 * Halaman ganti kata sandi sendiri tidak memakai middleware ini supaya tidak
 * terjadi putaran pengalihan tanpa ujung.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasChangedPassword()) {
            return redirect()
                ->route('password.edit')
                ->withErrors(['password' => 'Kata sandi Anda masih yang bawaan. Ganti dulu supaya akun ini aman.']);
        }

        return $next($request);
    }
}
