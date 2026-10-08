<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Masuk dan keluar. Semua peran memakai satu pintu yang sama, lalu diarahkan
 * ke halaman sesuai perannya: admin ke dasbor, guru ke halaman mengajar, siswa
 * ke halaman jadwalnya.
 */
class LoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // Yang sudah masuk tidak perlu melihat form masuk lagi; langsung
        // diantar ke halaman perannya.
        if ($user !== null) {
            return redirect()->route($user->homeRoute());
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email belum benar.',
            'password.required' => 'Kata sandi wajib diisi.',
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        // Pesan untuk email dan kata sandi disamakan supaya tidak memberi tahu
        // mana yang salah kepada orang yang mencoba menebak.
        if ($user === null || ! Hash::check($credentials['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak sesuai.',
            ]);
        }

        if ($user->status !== User::StatusActive) {
            throw ValidationException::withMessages([
                'email' => 'Akun ini sedang tidak aktif. Hubungi admin sekolah.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route($user->homeRoute()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Anda sudah keluar dari aplikasi.');
    }
}
