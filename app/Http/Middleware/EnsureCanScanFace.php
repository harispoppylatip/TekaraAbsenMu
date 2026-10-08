<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi halaman absen wajah hanya untuk petugas yang ditunjuk.
 *
 * Absen wajah dipakai dari kamera HP petugas, jadi tidak semua guru boleh
 * membukanya. Admin mencentang penanda "petugas kamera wajah" pada guru yang
 * ditugaskan; perannya tetap guru. Bagi yang berhalangan diberi keterangan
 * singkat di halaman utamanya supaya tidak terlihat seperti halaman rusak.
 */
class EnsureCanScanFace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->isAdmin() || $user->canScanFace()) {
            return $next($request);
        }

        $message = 'Halaman kamera absen wajah hanya untuk petugas yang ditunjuk admin. Minta admin mencentang "Petugas kamera wajah" pada nama Anda.';

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'forbidden',
                'message' => $message,
                'display_message' => 'Bukan petugas',
                'display_detail' => 'Lapor operator',
                'blocked' => true,
            ], 403);
        }

        return redirect()
            ->route($user->homeRoute())
            ->withErrors(['face' => $message]);
    }
}
