<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk | {{ config('app.name', 'Presensi Sekolah') }}</title>
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
                    <span>Presensi sidik jari per jam pelajaran</span>
                </span>
            </div>

            <h1>Masuk ke akun</h1>
            <p class="auth-lead">Guru memakai akunnya untuk melihat jadwal mengajar, membuka absen kelas, lalu
                menutupnya
                lagi setelah jam pelajaran selesai. Siswa memakai akunnya untuk melihat jadwal dan rekap absennya
                sendiri.</p>

            @if (session('success'))
                <div class="notice success">{{ session('success') }}</div>
            @endif
            @if (session('status'))
                <div class="notice">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="notice error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="auth-form">
                @csrf
                <label>Email
                    <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" autofocus
                        required>
                </label>
                <label>Kata sandi
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <label class="auth-check">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                    <span>Ingat saya di perangkat ini</span>
                </label>
                <button type="submit" class="auth-submit">Masuk</button>
            </form>

            <p class="auth-help">Belum punya kata sandi, atau kata sandinya terlewat? Hubungi admin sekolah. Admin bisa
                mengatur ulang kata sandi setiap akun dari halaman <strong>Anggota</strong>. Kata sandi awal yang dibuat
                sistem wajib diganti saat pertama masuk, dan halaman penggantian kata sandi muncul otomatis.</p>
        </section>
    </main>
</body>

</html>
