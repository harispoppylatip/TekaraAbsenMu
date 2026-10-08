<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Ganti kata sandi. Dipakai dua keadaan: akun yang masih memakai kata sandi
 * bawaan (wajib diganti sebelum halaman lain terbuka) dan anggota yang memang
 * ingin menggantinya sendiri dari halaman Akun.
 *
 * Kata sandi disimpan lewat cast `hashed` pada model User, jadi teks aslinya
 * tidak pernah masuk ke basis data.
 */
class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.password', [
            'isForced' => ! $request->user()->hasChangedPassword(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8), 'different:current_password'],
        ], [
            'current_password.required' => 'Kata sandi sekarang wajib diisi.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.confirmed' => 'Ulangi kata sandi baru dengan isi yang sama.',
            'password.different' => 'Kata sandi baru harus berbeda dari kata sandi sekarang.',
        ]);

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Kata sandi sekarang tidak sesuai.',
            ]);
        }

        $user->update([
            'password' => $validated['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        return to_route($user->homeRoute())
            ->with('success', 'Kata sandi berhasil diganti. Akun Anda sekarang memakai kata sandi sendiri.');
    }
}
