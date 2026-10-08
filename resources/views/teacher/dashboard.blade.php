<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Mengajar | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">{{ $today->translatedFormat('d F Y') }}</p>
            <h1>Jadwal Mengajar {{ \App\Models\LessonSchedule::dayName($day) }}</h1>
            <p>Buka absen saat jam pelajaran Anda berjalan (paling cepat
                {{ \App\Services\LessonSessionService::EarlyOpenMinutes }} menit sebelum mulai), lalu tutup setelah
                selesai.</p>
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
            <section class="stats" aria-label="Ringkasan mengajar hari ini" data-live="teacher-stats">
                <article class="stat-card blue">
                    <span>Jam Mengajar Hari Ini</span>
                    <strong>{{ $stats['jadwalHariIni'] }}</strong>
                    <small>Jam pelajaran di jadwal {{ \App\Models\LessonSchedule::dayName($day) }}</small>
                </article>
                <article class="stat-card green">
                    <span>Absen Dibuka</span>
                    <strong>{{ $stats['dibuka'] }}</strong>
                    <small>Masih menerima scan</small>
                </article>
                <article class="stat-card orange">
                    <span>Scan Hari Ini</span>
                    <strong>{{ $stats['scan'] }}</strong>
                    <small>Siswa yang sudah absen di jam Anda</small>
                </article>
                <article class="stat-card neutral">
                    <span>Jadwal Seminggu</span>
                    <strong>{{ $stats['jadwalMinggu'] }}</strong>
                    <small>Jam pelajaran yang Anda ampu</small>
                </article>
            </section>

            <section class="panel" data-live="teacher-today">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Buka dan tutup absen</p>
                        <h3>Jam Pelajaran Hari Ini</h3>
                    </div>
                    <span class="panel-total">{{ $todaySchedules->count() }} jam</span>
                </div>
                @if ($todaySchedules->isEmpty())
                    <div class="empty-state">
                        <strong>Tidak ada jam mengajar hari ini</strong>
                        <span>Hari {{ \App\Models\LessonSchedule::dayName($day) }} tidak ada jadwal atas nama Anda.
                            Jadwal mengajar yang lengkap ada di daftar seminggu di bawah, dan admin dapat menambah atau
                            mengubah jadwal Anda. Hubungi admin sekolah kalau jadwal Anda belum sesuai.</span>
                    </div>
                @else
                    <div class="teaching-list">
                        @foreach ($todaySchedules as $schedule)
                            @php($session = $schedule->sessions->first())
                            <article class="teaching-item">
                                <div class="teaching-slot">
                                    <span
                                        class="teaching-hour">{{ $schedule->lessonHour?->label() ?? 'Jam terhapus' }}</span>
                                    <span
                                        class="teaching-range">{{ $schedule->lessonHour?->range() ?? 'Tanpa rentang jam' }}</span>
                                </div>
                                <div class="teaching-body">
                                    <h4>{{ $schedule->subject }}</h4>
                                    <p>{{ $schedule->schoolClass?->name ?? 'Kelas terhapus' }}</p>
                                    @if ($session === null)
                                        <span class="chip">Absen belum dibuka</span>
                                    @elseif ($session->isScheduled())
                                        <span class="chip chip-info">Siap dimulai pukul
                                            {{ $session->scheduled_at->format('H:i') }}</span>
                                        <small class="table-note">Anda bisa mulai saat jamnya tiba. Sistem akan
                                            membuka otomatis jika belum dimulai.</small>
                                    @elseif ($session->isOpen())
                                        <span class="chip chip-success">Absen dibuka
                                            {{ $session->opened_at->format('H:i') }}</span>
                                        <small class="table-note">{{ $session->logs()->count() }} siswa sudah
                                            scan</small>
                                    @else
                                        <span class="chip chip-warning">Absen ditutup
                                            {{ $session->closed_at?->format('H:i') }}</span>
                                        <small class="table-note">{{ $session->logs()->count() }} siswa tercatat
                                            hadir</small>
                                    @endif
                                </div>
                                <div class="teaching-actions">
                                    @if ($session === null)
                                        <form method="POST" action="{{ route('teacher.sessions.store') }}"
                                            class="window-form">
                                            @csrf
                                            <input type="hidden" name="lesson_schedule_id"
                                                value="{{ $schedule->getKey() }}">
                                            <input name="note" value="" placeholder="Keterangan (opsional)"
                                                maxlength="100" aria-label="Keterangan pertemuan">
                                            <button type="submit">Buka Absen</button>
                                        </form>
                                    @else
                                        <a class="button-ghost"
                                            href="{{ route('teacher.sessions.recap', $session) }}">Lihat Rekap</a>
                                        @if ($session->isScheduled())
                                            <form method="POST" action="{{ route('teacher.sessions.store') }}">
                                                @csrf
                                                <input type="hidden" name="lesson_schedule_id"
                                                    value="{{ $schedule->getKey() }}">
                                                <button type="submit" class="button-primary">Mulai Absen</button>
                                            </form>
                                        @elseif ($session->isOpen())
                                            <form method="POST"
                                                action="{{ route('teacher.sessions.close', $session) }}"
                                                onsubmit="return window.confirm('Tutup absen {{ $session->label() }}? Setelah ditutup, scan siswa di jam ini tidak lagi tercatat.')">
                                                @csrf
                                                <button type="submit" class="button-primary">Tutup Absen</button>
                                            </form>
                                        @endif
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                    <p class="panel-footnote">Absen yang sudah ditutup hanya bisa dibuka lagi oleh admin. Kalau tombol
                        buka absen ditolak, biasanya karena hari ini bukan harinya, atau jamnya belum masuk, atau sudah
                        lewat. Pesan penolakannya menyebutkan pukul berapa absen itu boleh dibuka.</p>
                @endif
            </section>

            @php($substituteSessions = $todaySessions->filter(fn($item) => (int) $item->lessonSchedule?->user_id !== (int) auth()->id()))

            <div class="live-slot" data-live="teacher-substitute">
                @if ($substituteSessions->isNotEmpty())
                    <section class="panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">Penugasan admin</p>
                                <h3>Absen Sebagai Guru Pengganti</h3>
                            </div>
                            <span class="panel-total">{{ $substituteSessions->count() }} pertemuan</span>
                        </div>
                        <p class="panel-hint">Admin menunjuk Anda menggantikan guru pengampu pada pertemuan ini, jadi
                            kehadiran Anda dicatat atas nama Anda sendiri.</p>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Jam</th>
                                        <th>Kelas</th>
                                        <th>Pelajaran</th>
                                        <th>Guru digantikan</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($substituteSessions as $session)
                                        <tr>
                                            <td data-label="Jam">
                                                {{ $session->lessonHour()?->label() ?? 'Jam terhapus' }}
                                                <small class="table-note">{{ $session->range() }}</small>
                                            </td>
                                            <td data-label="Kelas">
                                                {{ $session->schoolClassName() ?? 'Kelas terhapus' }}
                                            </td>
                                            <td data-label="Pelajaran">{{ $session->scheduleLabel() }}</td>
                                            <td data-label="Guru digantikan">
                                                {{ $session->lessonSchedule?->teacher?->name ?? 'Guru terhapus' }}</td>
                                            <td data-label="Status">
                                                <span
                                                    class="{{ $session->chipClass() }}">{{ $session->statusLabel() }}</span>
                                            </td>
                                            <td data-label="Aksi">
                                                <div class="table-actions">
                                                    <a class="button-ghost"
                                                        href="{{ route('teacher.sessions.recap', $session) }}">Rekap</a>
                                                    @if ($session->isOpen())
                                                        <form method="POST"
                                                            action="{{ route('teacher.sessions.close', $session) }}">
                                                            @csrf
                                                            <button type="submit" class="button-primary">Tutup</button>
                                                        </form>
                                                    @elseif ($session->isScheduled())
                                                        <form method="POST"
                                                            action="{{ route('teacher.sessions.store') }}">
                                                            @csrf
                                                            <input type="hidden" name="lesson_schedule_id"
                                                                value="{{ $session->lessonSchedule?->getKey() }}">
                                                            <button type="submit" class="button-primary">Mulai</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif
            </div>

            <section class="panel" data-live="teacher-week">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Jadwal tetap</p>
                        <h3>Jadwal Mengajar Seminggu</h3>
                    </div>
                    <span class="panel-total">{{ $weekSchedules->count() }} jadwal</span>
                </div>
                @forelse ($weekSchedules->groupBy('day') as $dayNumber => $daySchedules)
                    <div class="week-block">
                        <h4>{{ \App\Models\LessonSchedule::dayName((int) $dayNumber) }}</h4>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Jam</th>
                                        <th>Kelas</th>
                                        <th>Mata pelajaran</th>
                                        <th>Pukul</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($daySchedules as $schedule)
                                        <tr>
                                            <td data-label="Jam">
                                                {{ $schedule->lessonHour?->label() ?? 'Jam terhapus' }}
                                            </td>
                                            <td data-label="Kelas">
                                                {{ $schedule->schoolClass?->name ?? 'Kelas terhapus' }}</td>
                                            <td data-label="Mata pelajaran">{{ $schedule->subject }}</td>
                                            <td data-label="Pukul">
                                                {{ $schedule->lessonHour?->range() ?? 'Tanpa jam' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @empty
                    <div class="empty-state">
                        <strong>Belum ada jadwal atas nama Anda</strong>
                        <span>Minta admin mengisi jadwal mengajar Anda di halaman Jadwal, supaya jam mengajar dan absen
                            kelas bisa dipakai.</span>
                    </div>
                @endforelse
                <p class="panel-footnote">Riwayat pertemuan yang pernah Anda buka ada di halaman <a
                        href="{{ route('teacher.recaps') }}">Rekap Mengajar</a>.</p>
            </section>

            <section class="panel" data-live="teacher-scans">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Pemantauan</p>
                        <h3>Scan Terakhir Hari Ini</h3>
                    </div>
                    <span class="panel-total">{{ $stats['scan'] }} scan</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Peran</th>
                                <th>Kelas</th>
                                <th>Jam pelajaran</th>
                                <th>Pukul scan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentLogs as $log)
                                <tr>
                                    <td data-label="Nama">{{ $log->user?->name ?? 'Pengguna terhapus' }}</td>
                                    <td data-label="Peran">{{ $log->user?->roleLabel() ?? 'Tidak diketahui' }}</td>
                                    <td data-label="Kelas">{{ $log->user?->class_name ?? 'Tanpa kelas' }}</td>
                                    <td data-label="Jam pelajaran">
                                        {{ $log->lessonSession?->label() ?? 'Di luar jam pelajaran' }}
                                        <small class="table-note">
                                            {{ $log->lessonSession?->schoolClassName() ?? 'Tanpa kelas' }}</small>
                                    </td>
                                    <td data-label="Pukul scan">{{ $log->scanned_at?->format('H:i:s') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="empty">Belum ada siswa yang scan di jam pelajaran Anda
                                        hari
                                        ini. Buka absennya dulu, lalu minta siswa menempelkan sidik jarinya di sensor
                                        kelas.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="panel-footnote">Daftar ini menyesuaikan diri sendiri setiap beberapa detik selama halaman
                    terbuka, jadi siswa yang baru scan langsung muncul tanpa perlu dimuat ulang.</p>
            </section>
        </main>

        <footer>Halaman ini hanya menampilkan jadwal dan pertemuan atas nama Anda.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
