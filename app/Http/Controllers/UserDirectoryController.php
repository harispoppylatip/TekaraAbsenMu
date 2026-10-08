<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Halaman Data Pengguna lama sudah digabung ke halaman Anggota, yang punya
 * pencarian dan filter yang sama. Alamat `/data` tetap ada supaya tautan dan
 * penanda lama tidak rusak, lalu diteruskan ke Anggota dengan filter yang sama.
 */
class UserDirectoryController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $fingerprint = match ($request->query('fingerprint')) {
            'registered' => 'registered',
            'missing' => 'pending',
            default => null,
        };

        return to_route('members.index', array_filter([
            'q' => is_string($request->query('q')) ? trim($request->query('q')) : null,
            'role' => in_array($request->query('role'), User::Roles, true) ? $request->query('role') : null,
            'class_name' => is_string($request->query('class_name')) ? trim($request->query('class_name')) : null,
            'fingerprint' => $fingerprint,
        ]), 301);
    }
}
