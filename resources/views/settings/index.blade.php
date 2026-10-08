<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')
        <header class="page-heading">
            <p class="eyebrow">Pemeliharaan sistem</p>
            <h1>Pengaturan</h1>
            <p>Periksa zona waktu presensi dan hapus riwayat lama supaya data tetap ringan.</p>
        </header>

        @if (session('success'))
            <div class="notice success">{{ session('success') }}</div>
        @endif
        @if (session('warning'))
            <div class="notice warning">{{ session('warning') }}</div>
        @endif
        @if ($errors->any())
            <div class="notice error">{{ $errors->first() }}</div>
        @endif

        <main class="settings-layout">
            <section class="panel" data-live="settings-timezone">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Zona waktu</p>
                        <h3>Waktu presensi dan riwayat</h3>
                    </div>
                    <span class="panel-total">{{ $serverTime->format('P') }}</span>
                </div>
                <div class="settings-block">
                    <strong>Zona waktu aktif</strong>
                    <span class="timezone-value">{{ $timezone }}</span>
                    <p class="settings-note">Waktu server saat ini {{ $serverTime->translatedFormat('l, d F Y, H:i') }}.
                        Seluruh jam pada presensi, alat sensor, dan catatan teknis alat memakai zona waktu ini.</p>
                </div>
                <p class="settings-footnote">Ubah lewat variabel APP_TIMEZONE di file .env bila
                    perangkat dipindah ke wilayah lain. Setelah diubah jalankan php artisan config:clear.</p>
            </section>

            <section class="panel" data-live="settings-logs">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Bersihkan data</p>
                        <h3>Hapus riwayat</h3>
                    </div>
                    <span
                        class="panel-total">{{ number_format(array_sum(array_column($overview, 'count')), 0, ',', '.') }}
                        baris</span>
                </div>

                <form method="POST" action="{{ route('settings.logs.destroy') }}" class="purge-form"
                    onsubmit="return window.confirm('Riwayat yang dihapus tidak bisa dikembalikan. Lanjutkan?')">
                    @csrf
                    @method('DELETE')

                    <div class="option-list">
                        <p>Jenis riwayat</p>
                        @foreach ($labels as $target => $label)
                            @php($info = $overview[$target] ?? ['count' => 0, 'oldest' => null])
                            <label class="option-item">
                                <input type="checkbox" name="targets[]" value="{{ $target }}"
                                    @checked(in_array($target, old('targets', ['attendance', 'api']), true))>
                                {{ $label }}
                                <span>{{ number_format($info['count'], 0, ',', '.') }}
                                    baris{{ $info['oldest'] ? ', tertua ' . $info['oldest']->translatedFormat('d F Y, H:i') : '' }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="option-list">
                        <p>Tindakan</p>
                        <label class="option-item">
                            <input type="radio" name="mode" value="days" @checked(old('mode', 'days') === 'days')>
                            Hapus riwayat lebih lama dari jumlah hari tertentu
                        </label>
                        <label class="option-item">
                            <input type="radio" name="mode" value="date" @checked(old('mode') === 'date')>
                            Hapus riwayat pada tanggal tertentu
                        </label>
                        <label class="option-item">
                            <input type="radio" name="mode" value="all" @checked(old('mode') === 'all')>
                            Hapus semua riwayat
                        </label>
                    </div>

                    <div class="form-grid">
                        <label>Simpan riwayat selama (hari)<input type="number" name="days" min="1"
                                max="3650" value="{{ old('days', 30) }}"></label>
                        <label>Tanggal riwayat<input type="date" name="date" value="{{ old('date') }}"></label>
                        <label class="option-item option-item-wide">
                            <input type="checkbox" name="confirm" value="1" @checked(old('confirm'))>
                            Saya memahami riwayat yang dihapus tidak bisa dikembalikan
                        </label>
                        <button type="submit" class="button-danger">Hapus riwayat terpilih</button>
                    </div>
                </form>
                <p class="settings-footnote">Sesi pendaftaran sidik jari yang masih menunggu tap
                    atau siap disimpan tidak ikut dihapus, hanya sesi berstatus selesai.</p>
            </section>
        </main>
        <footer>Data sidik jari disimpan privat dan hanya dipakai untuk validasi presensi.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
