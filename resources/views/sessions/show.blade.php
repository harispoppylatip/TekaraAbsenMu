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
            <p>{{ $session->scheduleLabel() }} &middot; dibuka
                {{ $session->opened_at->format('H:i') }} oleh {{ $session->openedBy?->name ?? 'sistem' }}.
                @if ($session->closed_at)
                    Ditutup {{ $session->closed_at->format('H:i') }} oleh {{ $session->closedBy?->name ?? 'admin' }}.
                @else
                    Masih menerima scan sampai ditutup.
                @endif
            </p>
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
            <section class="stats" aria-label="Ringkasan kehadiran pertemuan">
                <article class="stat-card green">
                    <span>Hadir</span>
                    <strong>{{ $recap['presentCount'] }}</strong>
                    <small>Siswa yang sudah scan</small>
                </article>
                <article class="stat-card neutral">
                    <span>Belum Hadir</span>
                    <strong>{{ $recap['absentCount'] }}</strong>
                    <small>Tanpa catatan scan</small>
                </article>
                <article class="stat-card blue">
                    <span>Siswa di Kelas</span>
                    <strong>{{ $recap['rows']->count() }}</strong>
                    <small>{{ $session->schoolClassName() }}</small>
                </article>
                <article class="stat-card orange">
                    <span>Bukti Guru</span>
                    <strong>{{ $recap['teacherLog'] ? 'Ada' : 'Belum' }}</strong>
                    <small>{{ $session->teacherName() }}</small>
                </article>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Pertemuan</p>
                        <h3>Keterangan Sesi</h3>
                    </div>
                    <span class="{{ $session->chipClass() }}">{{ $session->statusLabel() }}</span>
                </div>
                <div class="session-meta">
                    <div class="session-meta-item">
                        <span>Jam pelajaran</span>
                        <strong>{{ $session->lessonHour()?->label() ?? 'Jam terhapus' }}</strong>
                        <small>{{ $session->range() }}</small>
                    </div>
                    <div class="session-meta-item">
                        <span>Mata pelajaran</span>
                        <strong>{{ $session->lessonSchedule?->subject ?? 'Pelajaran terhapus' }}</strong>
                        <small>{{ $session->schoolClassName() ?? 'Kelas terhapus' }}</small>
                    </div>
                    <div class="session-meta-item">
                        <span>Guru pengampu</span>
                        <strong>{{ $session->teacherName() }}</strong>
                        <small>
                            @if ($session->isSubstituted())
                                Pengganti dari
                                {{ $session->lessonSchedule?->teacher?->name ?? 'guru terhapus' }}
                            @else
                                Sesuai jadwal
                            @endif
                        </small>
                    </div>
                    <div class="session-meta-item">
                        <span>Keterangan</span>
                        <strong>{{ filled($session->note) ? $session->note : 'Tanpa keterangan' }}</strong>
                        <small>Ditulis oleh {{ $session->openedBy?->name ?? 'sistem' }}</small>
                    </div>
                </div>
                <div class="table-actions session-actions">
                    @if ($session->isOpen())
                        <form method="POST" action="{{ route('sessions.close', $session) }}">
                            @csrf
                            <button type="submit" class="button-primary">Tutup Absen</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('sessions.reopen', $session) }}">
                            @csrf
                            <button type="submit" class="button-primary">Buka Lagi</button>
                        </form>
                    @endif
                    <a class="button-ghost"
                        href="{{ route('sessions.index', ['date' => $session->date->toDateString()]) }}">Daftar sesi
                        {{ $session->dateLabel() }}</a>
                    <a class="button-ghost"
                        href="{{ route('schedules.index', ['class' => $session->lessonSchedule?->school_class_id]) }}">Jadwal
                        kelas</a>
                </div>
                <p class="panel-footnote">Guru yang mengajar wajib ikut scan sidik jarinya saat absen dibuka, sebagai
                    bukti dia hadir di kelas. Bukti scan itu dicatat pada pertemuan ini, tidak menahan scan siswa.</p>
            </section>

            <section class="panel" data-live="session-roster">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Daftar hadir</p>
                        <h3>Siswa {{ $session->schoolClassName() }}</h3>
                    </div>
                    <span class="panel-total">{{ $recap['presentCount'] }} dari
                        {{ $recap['rows']->count() }} siswa hadir</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>NIS</th>
                                <th>Status</th>
                                <th>Jam scan</th>
                                <th>Sensor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recap['rows'] as $row)
                                <tr>
                                    <td data-label="Nama">
                                        {{ $row['user']->name }}
                                        @if ($row['user']->status === \App\Models\User::StatusInactive)
                                            <small class="table-note">Akun tidak aktif</small>
                                        @endif
                                    </td>
                                    <td data-label="NIS">{{ $row['user']->identifier_number ?? 'Belum diisi' }}</td>
                                    <td data-label="Status">
                                        @if ($row['present'])
                                            <span class="chip chip-success">Hadir</span>
                                        @else
                                            <span class="chip chip-danger">Belum hadir</span>
                                        @endif
                                    </td>
                                    <td data-label="Jam scan">
                                        {{ $row['log']?->scanned_at?->format('H:i:s') ?? 'Tanpa catatan' }}
                                    </td>
                                    <td data-label="Sensor">
                                        {{ $row['log']?->device?->name ?? 'Tanpa sensor' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="empty">Kelas ini belum punya anggota, jadi tidak ada yang
                                        bisa didaftarkan kehadirannya. Tambahkan siswa ke kelas lewat halaman <a
                                            href="{{ route('members.index') }}">Anggota</a>.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="panel-footnote">Daftar ini mengikuti data anggota kelas terkini, jadi siswa yang baru
                    dipindahkan ke kelas ini ikut muncul meskipun belum pernah scan pada pertemuan ini. Guru pengajar
                    tidak dihitung sebagai siswa.</p>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Bukti kehadiran guru</p>
                        <h3>Scan Guru Pengajar</h3>
                    </div>
                </div>
                @if ($recap['teacherLog'])
                    <div class="session-meta">
                        <div class="session-meta-item">
                            <span>Nama</span>
                            <strong>{{ $recap['teacherLog']->user?->name ?? $session->teacherName() }}</strong>
                            <small>{{ $session->teacherName() }}</small>
                        </div>
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
                    <p class="panel-hint">Guru pengajar pertemuan ini belum tercatat scan. Catatan ini bukan syarat
                        siswa bisa absen, hanya bukti kehadiran guru di kelas.
                        @if ($session->isOpen())
                            Scan guru masih bisa masuk selama absennya belum ditutup.
                        @else
                            Absen sudah ditutup, jadi bukti scan guru tidak bisa lagi ditambahkan pada pertemuan ini.
                        @endif
                    </p>
                @endif
            </section>

            @if ($recap['otherLogs']->isNotEmpty())
                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Perlu diperiksa</p>
                            <h3>Scan di Luar Daftar Kelas</h3>
                        </div>
                        <span class="panel-total">{{ $recap['otherLogs']->count() }} catatan</span>
                    </div>
                    <p class="panel-hint">Sidik jari ini terbaca pada pertemuan ini, tetapi pemiliknya bukan anggota
                        kelas {{ $session->schoolClassName() }} dan bukan guru pengajarnya. Biasanya karena siswa baru
                        dipindahkan kelas atau salah kelas saat memindai.</p>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Peran</th>
                                    <th>Kelas</th>
                                    <th>Jam scan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recap['otherLogs'] as $log)
                                    <tr>
                                        <td data-label="Nama">{{ $log->user?->name ?? 'Pengguna terhapus' }}</td>
                                        <td data-label="Peran">{{ $log->user?->roleLabel() ?? 'Tidak diketahui' }}</td>
                                        <td data-label="Kelas">{{ $log->user?->class_name ?? 'Tidak ada' }}</td>
                                        <td data-label="Jam scan">{{ $log->scanned_at?->format('H:i:s') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </main>

        <footer>Rekap ini dipakai guru dan admin untuk mencocokkan kehadiran siswa dengan jam pelajaran.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
