<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menjaga pesan hasil aksi tetap terlihat di halaman yang menyegarkan diri.
 *
 * Halaman seperti `/wajah` dan `/perangkat` memanggil alamatnya sendiri setiap
 * lima detik memakai sesi yang sama. Permintaan itu ikut menghabiskan pesan
 * sekali-tampil, sehingga kalimat seperti "2 foto wajah tersimpan" dan pesan
 * galat isian formulir bisa hilang sebelum pengguna sempat membacanya. Karena
 * permintaan penyegaran menandai dirinya lewat header `X-Live-Refresh`,
 * permintaan itu saja yang pesannya diperpanjang sekali.
 */
class KeepFlashDataOnLiveRefresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->headers->has('X-Live-Refresh')) {
            $request->session()->reflash();
        }

        return $response;
    }
}
