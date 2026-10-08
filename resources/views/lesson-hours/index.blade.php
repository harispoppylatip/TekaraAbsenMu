<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jam Pelajaran | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">Pengaturan jadwal</p>
            <h1>Jam Pelajaran</h1>
            <p>Pembagian jam dalam sehari, misalnya jam ke-1 sampai jam ke-8. Jam ini dipakai saat menyusun
                <a href="{{ route('schedules.index') }}">Jadwal Pelajaran</a>.</p>
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

        <main class="attendance-layout">
            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Sesi harian</p>
                        <h3>Daftar Jam Pelajaran</h3>
                    </div>
                    <span class="panel-total">{{ $hours->count() }} jam &middot; {{ $usedInSchedules }} dipakai
                        jadwal</span>
                </div>
                <p class="panel-hint">Setiap jam punya nomor dan rentang waktunya sendiri. Rentang jam tidak boleh
                    bertabrakan supaya tidak ada dua jam yang mengaku berjalan pada waktu yang sama. Batas mulai
                    termasuk, batas selesai tidak termasuk, jadi jam 07:45 sudah masuk jam berikutnya.
                </p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nomor dan jam</th>
                                <th>Pukul</th>
                                <th>Dipakai</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($hours as $hour)
                                <tr>
                                    <td data-label="Nomor dan jam">
                                        <form method="POST" action="{{ route('lesson-hours.update', $hour) }}"
                                            class="window-form">
                                            @csrf
                                            @method('PUT')
                                            <input type="number" name="number" value="{{ $hour->number }}"
                                                min="1" max="{{ \App\Http\Controllers\LessonHourController::MaxNumber }}"
                                                aria-label="Nomor jam" required>
                                            <input type="time" name="starts_at" value="{{ $hour->starts_at }}"
                                                aria-label="Jam mulai" required>
                                            <span class="window-dash">sampai</span>
                                            <input type="time" name="ends_at" value="{{ $hour->ends_at }}"
                                                aria-label="Jam selesai" required>
                                            <button type="submit">Simpan</button>
                                        </form>
                                    </td>
                                    <td data-label="Pukul">
                                        <span class="chip chip-info">{{ $hour->range() }}</span>
                                        <small class="table-note">{{ $hour->label() }}</small>
                                    </td>
                                    <td data-label="Dipakai">
                                        @if ($hour->schedules_count > 0)
                                            <span class="chip">{{ $hour->schedules_count }} jadwal</span>
                                        @else
                                            <span class="table-note">Belum dipakai jadwal mana pun.</span>
                                        @endif
                                    </td>
                                    <td data-label="Aksi">
                                        <div class="table-actions">
                                            <form method="POST" action="{{ route('lesson-hours.destroy', $hour) }}"
                                                onsubmit="return window.confirm('Hapus {{ $hour->label() }} {{ $hour->range() }}? Jam yang masih dipakai jadwal pelajaran tidak bisa dihapus.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="button-danger">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="empty">Belum ada jam pelajaran. Isi dulu daftar jamnya,
                                        karena jadwal pelajaran dan absen per jam tidak bisa dibuat tanpa jam.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <form method="POST" action="{{ route('lesson-hours.store') }}" class="form-grid">
                    @csrf
                    <label>Jam ke-
                        <input type="number" name="number" value="{{ old('number', $nextNumber) }}" min="1"
                            max="{{ \App\Http\Controllers\LessonHourController::MaxNumber }}" required>
                    </label>
                    <label>Mulai
                        <input type="time" name="starts_at" value="{{ old('starts_at', '07:00') }}" required>
                    </label>
                    <label>Selesai
                        <input type="time" name="ends_at" value="{{ old('ends_at', '07:45') }}" required>
                    </label>
                    <div class="form-help">Contoh: jam ke-1 pukul 07:00 sampai 07:45, jam ke-2 pukul 07:45 sampai
                        08:30. Nomor jam boleh diubah kapan saja tanpa mengubah jadwal pelajaran yang memakainya.
                    </div>
                    <button type="submit">Tambah Jam</button>
                </form>
                <p class="panel-footnote">Jam mengikuti zona waktu {{ config('app.timezone') }}. Jam pelajaran yang
                    masih dipakai jadwal pelajaran tidak bisa dihapus, hapus atau pindahkan jadwalnya dulu di halaman
                    <a href="{{ route('schedules.index') }}">Jadwal Pelajaran</a>.</p>
            </section>

            @if ($hours->isEmpty())
                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Bantuan</p>
                            <h3>Isi Otomatis</h3>
                        </div>
                    </div>
                    <p class="panel-hint">Kalau sekolah memakai durasi jam yang sama sepanjang hari, isi daftarnya
                        sekaligus di sini. Jam pertama dimulai dari waktu yang dipilih, lalu jam berikutnya mengikuti
                        durasi yang sama. Hasilnya bisa disesuaikan satu per satu di tabel atas. Formulir ini hanya
                        tampil selama daftar jam masih kosong supaya pengaturan lama tidak tertimpa diam-diam.
                    </p>
                    <form method="POST" action="{{ route('lesson-hours.generate') }}" class="form-grid">
                        @csrf
                        <label>Jam pertama mulai
                            <input type="time" name="starts_at" value="{{ old('starts_at', '07:00') }}" required>
                        </label>
                        <label>Durasi satu jam (menit)
                            <input type="number" name="duration" value="{{ old('duration', 45) }}" min="20"
                                max="120" required>
                        </label>
                        <label>Jumlah jam
                            <input type="number" name="total" value="{{ old('total', 8) }}" min="1"
                                max="{{ \App\Http\Controllers\LessonHourController::MaxNumber }}" required>
                        </label>
                        <div class="form-help">Contoh: mulai 07:00, durasi 45 menit, 8 jam menghasilkan jam ke-1
                            pukul 07:00 sampai jam ke-8 pukul 13:00.</div>
                        <button type="submit">Isi Jam Pelajaran</button>
                    </form>
                    <p class="panel-footnote">Jam terakhir tidak boleh melewati tengah malam. Kalau terlalu panjang,
                        kurangi jumlah jam, durasi, atau majukan jam mulainya.</p>
                </section>
            @endif
        </main>

        <footer>Jam pelajaran dipakai halaman <a href="{{ route('schedules.index') }}">Jadwal Pelajaran</a>, halaman
            mengajar guru, dan halaman jadwal siswa.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
