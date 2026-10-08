<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Kata Sandi | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="auth-body">
    <main class="auth-page">
        <section class="auth-card">
            <div class="auth-theme">@include('partials.theme-toggle', ['compact' => true])</div>
            <div class="auth-brand">
                <span class="auth-mark">@include('partials.tekara-logo')</span>
                <span class="auth-brand-text">
                    <strong>{{ config('app.name', 'Presensi Sekolah') }}</strong>
                    <span>{{ auth()->user()?->roleLabel() }} &middot; {{ auth()->user()?->email }}</span>
                </span>
            </div>

            <h1>{{ $isForced ? 'Ganti kata sandi dulu' : 'Ubah kata sandi' }}</h1>
            @if ($isForced)
                <p class="auth-lead">Akun Anda masih memakai kata sandi awal yang dibuat sistem, jadi seluruh halaman
                    selain halaman ini masih terkunci. Tentukan kata sandi baru milik Anda sendiri, lalu semua menu
                    langsung bisa dipakai.</p>
            @else
                <p class="auth-lead">Masukkan kata sandi yang sedang dipakai, lalu kata sandi baru dua kali. Kata sandi
                    baru minimal delapan karakter dan tidak boleh sama dengan kata sandi sekarang.</p>
            @endif

            @if (session('success'))
                <div class="notice success">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="notice error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('password.update') }}" class="auth-form">
                @csrf
                @method('PUT')
                <label>Kata sandi sekarang
                    <input type="password" name="current_password" autocomplete="current-password" autofocus required>
                </label>
                <label>Kata sandi baru
                    <input type="password" name="password" autocomplete="new-password" required>
                </label>
                <label>Ulangi kata sandi baru
                    <input type="password" name="password_confirmation" autocomplete="new-password" required>
                </label>
                <button type="submit" class="auth-submit">Simpan Kata Sandi</button>
            </form>

            <p class="auth-help">
                @if ($isForced)
                    Kata sandi awal akun siswa adalah nomor induknya, sedangkan akun guru dan admin memakai kata sandi
                    awal yang diberikan admin sekolah. Setelah kata sandi baru tersimpan, Anda langsung diarahkan ke
                    halaman utama sesuai peran akun.
                @else
                    Butuh melihat data akun? Buka <a href="{{ route('account.index') }}">halaman akun</a>. Untuk keluar
                    dari perangkat ini, pakai tombol keluar di menu samping.
                @endif
            </p>
        </section>
    </main>
</body>

</html>
