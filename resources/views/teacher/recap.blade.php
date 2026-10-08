<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap {{ $session->label() }} | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">{{ $session->dateLabel() }}</p>
            <h1>Rekap {{ $session->label() }}</h1>
            <p>{{ $session->scheduleLabel() }} &middot;
                @if ($session->closed_at)
                    absen ditutup {{ $session->closed_at->format('H:i') }}.
                @else
                    absen masih dibuka, jadi daftar ini masih bisa bertambah.
                @endif
            </p>
            <div class="page-heading-actions">
                <a class="button-primary"
                    href="{{ route('teacher.sessions.export', $session) }}">Unduh CSV pertemuan ini</a>
                <a class="button-ghost" href="{{ route('teacher.recaps') }}">Kembali ke Rekap Mengajar</a>
            </div>
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
            <section class="stats" aria-label="Ringkasan kehadiran">
                <article class="stat-card green">
                    <span>Hadir</span>
                    <strong>{{ $recap['presentCount'] }}</strong>
                    <small>Sudah scan di jam ini</small>
                </article>
                <article class="stat-card neutral">
                    <span>Belum Hadir</span>
                    <strong>{{ $recap['absentCount'] }}</strong>
                    <small>Belum ada catatan scan</small>
                </article>
                <article class="stat-card blue">
                    <span>Siswa di Kelas</span>
                    <strong>{{ $recap['rows']->count() }}</strong>
                    <small>{{ $session->schoolClassName() }}</small>
                </article>
                <article class="stat-card orange">
                    <span>Kehadiran Anda</span>
                    <strong>{{ $recap['teacherLog'] ? 'Tercatat' : 'Belum' }}</strong>
                    <small>Bukti scan guru di kelas</small>
                </article>
            </section>

            <section class="panel" data-live="teacher-recap">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Daftar hadir</p>
                        <h3>Siswa {{ $session->schoolClassName() }}</h3>
                    </div>
                    <span class="{{ $session->chipClass() }}">{{ $session->statusLabel() }}</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Nomor induk</th>
                                <th>Status</th>
                                <th>Pukul scan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recap['rows'] as $row)
                                <tr>
                                    <td data-label="Nama">{{ $row['user']->name }}</td>
                                    <td data-label="Nomor induk">
                                        {{ $row['user']->identifier_number ?? 'Belum diisi' }}</td>
                                    <td data-label="Status">
                                        @if ($row['present'])
                                            <span class="chip chip-success">Hadir</span>
                                        @else
                                            <span class="chip chip-danger">Belum hadir</span>
                                        @endif
                                    </td>
                                    <td data-label="Pukul scan">
                                        {{ $row['log']?->scanned_at?->format('H:i:s') ?? 'Tanpa catatan' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="empty">Kelas ini belum punya anggota, jadi belum ada
                                        daftar siswa yang bisa ditampilkan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="table-actions session-actions">
                    <a class="button-ghost" href="{{ route('teacher.index') }}">Kembali ke Jadwal Mengajar</a>
                    @if ($session->isOpen())
                        <form method="POST" action="{{ route('teacher.sessions.close', $session) }}"
                            onsubmit="return window.confirm('Tutup absen {{ $session->label() }}? Scan siswa setelah ini tidak lagi tercatat.')">
                            @csrf
                            <button type="submit" class="button-primary">Tutup Absen</button>
                        </form>
                    @else
                        <a class="button-ghost" href="{{ route('teacher.recaps') }}">Rekap Mengajar</a>
                    @endif
                </div>
                <p class="panel-footnote">Daftar mengikuti anggota kelas terkini. Siswa yang baru dipindahkan ke kelas
                    ini ikut muncul meskipun belum pernah scan pada pertemuan ini. Absen yang sudah ditutup hanya bisa
                    dibuka lagi oleh admin.</p>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Bukti mengajar</p>
                        <h3>Scan Sidik Jari Anda</h3>
                    </div>
                </div>
                @if ($recap['teacherLog'])
                    <div class="session-meta">
                        <div class="session-meta-item">
                            <span>Jam scan</span>
                            <strong>{{ $recap['teacherLog']->scanned_at?->format('H:i:s') }}</strong>
                            <small>{{ $recap['teacherLog']->scanned_at?->translatedFormat('d F Y') }}</small>
                        </div>
                        <div class="session-meta-item">
                            <span>Sensor</span>
                            <strong>{{ $recap['teacherLog']->device?->name ?? 'Tanpa sensor' }}</strong>
                            <small>Sensor kelas {{ $session->schoolClassName() }}</small>
                        </div>
                    </div>
                @else
                    <p class="panel-hint">Anda belum tercatat scan pada pertemuan ini. Scan sidik jari di sensor kelas
                        saat absennya terbuka sebagai bukti Anda hadir mengajar.
                        @if (!$session->isOpen())
                            Pertemuan ini sudah ditutup, jadi buktinya tidak bisa lagi ditambahkan dari sensor.
                        @endif
                    </p>
                @endif
            </section>
        </main>

        <footer>Rekap pertemuan ini juga dapat dilihat admin di halaman sesi absen.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
