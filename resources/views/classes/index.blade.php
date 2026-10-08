<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelas | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')
        <header class="page-heading">
            <p class="eyebrow">Data kelas</p>
            <h1>Kelas</h1>
            <p>Daftar kelas di sekolah. Kelas baru juga otomatis masuk ke sini saat diketik di halaman Anggota.</p>
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

        <main class="class-layout">
            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Kelas baru</p>
                        <h3>Tambah Kelas</h3>
                    </div>
                </div>
                <form method="POST" action="{{ route('classes.store') }}" class="form-grid class-form">
                    @csrf
                    <label>Nama kelas
                        <input name="name" value="{{ old('name') }}" maxlength="50" placeholder="Contoh: X TKJ 1"
                            required>
                    </label>
                    <div class="form-help">Pakai satu pola nama untuk semua kelas, misalnya tingkat, jurusan, lalu nomor
                        rombel.</div>
                    <button type="submit">Tambah Kelas</button>
                </form>
            </section>

            <section class="panel registered-panel" data-live="classes-list">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Daftar kelas</p>
                        <h3>Kelas terdaftar</h3>
                    </div>
                    <span class="panel-total">{{ $classes->count() }} kelas</span>
                </div>
                <div class="class-cards">
                    @forelse ($classes as $schoolClass)
                        <article class="class-card">
                            <div class="class-card-head">
                                <form method="POST" action="{{ route('classes.update', $schoolClass) }}"
                                    class="class-rename">
                                    @csrf
                                    @method('PUT')
                                    <input name="name" value="{{ $schoolClass->name }}" maxlength="50"
                                        aria-label="Nama kelas">
                                    <button type="submit">Simpan</button>
                                </form>
                                <div class="class-card-meta">
                                    <span class="chip chip-info">{{ $schoolClass->members_count }} anggota</span>
                                    @if ($schoolClass->devices->isEmpty())
                                        <span class="chip">Tanpa sensor kelas</span>
                                    @else
                                        <span class="chip chip-success">{{ $schoolClass->devices->count() }} sensor
                                            kelas</span>
                                    @endif
                                    <span class="chip">{{ $schoolClass->lessonSchedules->count() }} jadwal</span>
                                </div>
                                <div class="table-actions">
                                    <a class="button-ghost"
                                        href="{{ route('members.index', ['class_name' => $schoolClass->name]) }}">Lihat
                                        anggota</a>
                                    <form method="POST" action="{{ route('classes.destroy', $schoolClass) }}"
                                        onsubmit="return window.confirm('Hapus kelas ini? Kelas yang masih dipakai anggota tidak dapat dihapus.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button-danger">Hapus</button>
                                    </form>
                                </div>
                            </div>

                            <div class="class-card-section">
                                <h4>Sensor kelas</h4>
                                @if ($schoolClass->devices->isEmpty())
                                    <p class="class-card-note">Belum ada sensor yang dipilih melayani kelas ini. Pilih
                                        kelas ini pada kolom <strong>Kelas yang dilayani</strong> di halaman <a
                                            href="{{ route('attendance.index') }}">Presensi</a>.</p>
                                @else
                                    <ul class="link-list">
                                        @foreach ($schoolClass->devices as $device)
                                            <li>
                                                <span class="link-list-name">{{ $device->label() }}</span>
                                                <span class="link-list-time">
                                                    {{ $device->isGate() ? 'Pulang masuk + kelas' : 'Kelas' }}
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <p class="class-card-note">Sensor di atas hanya melayani anggota kelas ini, dan
                                        hanya menerima scan saat jam pelajaran kelas ini sedang dibuka oleh gurunya.
                                        Ubah pilihannya di halaman <a
                                            href="{{ route('attendance.index') }}">Presensi</a>.</p>
                                @endif
                            </div>

                            <div class="class-card-section">
                                <h4>Jadwal pelajaran</h4>
                                @php
                                    $classSchedules = $schoolClass->lessonSchedules
                                        ->sortBy(fn($schedule) => [$schedule->day, $schedule->lessonHour?->number ?? 0])
                                        ->values();
                                @endphp
                                @if ($classSchedules->isEmpty())
                                    <p class="class-card-note">Belum ada pelajaran yang dijadwalkan di kelas ini.
                                        Absen per jam pelajaran belum bisa dibuka sampai jadwalnya diisi.</p>
                                @else
                                    <ul class="link-list">
                                        @foreach ($classSchedules as $schedule)
                                            <li>
                                                <span class="link-list-name">{{ $schedule->subject }}</span>
                                                <span class="link-list-time">{{ $schedule->dayLabel() }} &middot;
                                                    {{ $schedule->lessonHour?->label() }} &middot;
                                                    {{ $schedule->teacher?->name ?? 'Guru' }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                <p class="class-card-note">Seluruh pelajaran kelas ini dalam satu minggu diatur di
                                    halaman <a
                                        href="{{ route('schedules.index', ['class' => $schoolClass->getKey()]) }}">Jadwal</a>,
                                    beserta jam pelajaran dan guru pengampunya.</p>
                            </div>
                        </article>
                    @empty
                        <p class="empty">Belum ada kelas terdaftar.</p>
                    @endforelse
                </div>
                <p class="panel-footnote">Nama kelas bisa diubah langsung pada cardnya, seluruh anggota ikut
                    diperbarui. Sensor kelas yang melayani tiap kelas dipilih di halaman <a
                        href="{{ route('attendance.index') }}">Presensi</a>: sensor seperti itu hanya melayani anggota
                    kelas tersebut, dan hanya mencatat kehadiran saat jam pelajaran kelas itu sedang dibuka oleh
                    gurunya. Semua sesi absen yang sudah dibuka terlihat di halaman <a
                        href="{{ route('sessions.index') }}">Sesi Absen</a>. Kelas yang masih dipakai
                    anggota tidak bisa dihapus, pindahkan anggotanya lewat halaman <a
                        href="{{ route('members.index') }}">Anggota</a> lebih dulu.
                </p>
            </section>
        </main>
        <footer>Daftar kelas dipakai bersama oleh halaman Anggota, Sidik Jari, dan Laporan.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
