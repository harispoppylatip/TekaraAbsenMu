<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Pelajaran Saya | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">{{ $today->translatedFormat('d F Y') }}</p>
            <h1>Jadwal Pelajaran Saya</h1>
            <p>{{ $student->name }} &middot; {{ $class?->name ?? 'Kelas belum ditetapkan' }} &middot;
                {{ \App\Models\LessonSchedule::dayName($day) }}. Tempelkan sidik jari di sensor kelas setelah guru
                membuka absen. Catatan datang dan pulang dari sensor gerbang ada di menu <a
                    href="{{ route('student.gate-attendance') }}">Datang dan Pulang</a>.</p>
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
            @if ($class === null)
                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Data kelas</p>
                            <h3>Kelas Belum Ditetapkan</h3>
                        </div>
                    </div>
                    <div class="empty-state">
                        <strong>Akun Anda belum masuk kelas mana pun</strong>
                        <span>Jadwal pelajaran dan rekap absen mengikuti kelas Anda, jadi keduanya baru bisa ditampilkan
                            setelah kelasnya diisi. Hubungi wali kelas atau admin sekolah untuk memasukkan Anda ke
                            kelas, lalu buka kembali halaman ini.</span>
                    </div>
                </section>
            @else
                @php($historyBySession = $history->keyBy(fn(array $row): int => (int) $row['session']->getKey()))

                <section class="stats" aria-label="Ringkasan jadwal dan kehadiran" data-live="student-stats">
                    <article class="stat-card blue">
                        <span>Jam Pelajaran Hari Ini</span>
                        <strong>{{ $stats['jadwalHariIni'] }}</strong>
                        <small>Jadwal {{ \App\Models\LessonSchedule::dayName($day) }}</small>
                    </article>
                    <article class="stat-card green">
                        <span>Hadir</span>
                        <strong>{{ $stats['hadir'] }}</strong>
                        <small>Dari {{ $history->count() }} pertemuan terakhir</small>
                    </article>
                    <article class="stat-card neutral">
                        <span>Belum Ada Catatan</span>
                        <strong>{{ $stats['belum'] }}</strong>
                        <small>Pertemuan tanpa scan Anda</small>
                    </article>
                    <article class="stat-card orange">
                        <span>Jadwal Seminggu</span>
                        <strong>{{ $stats['jadwalMinggu'] }}</strong>
                        <small>Jam pelajaran kelas {{ $class->name }}</small>
                    </article>
                </section>

                <section class="panel" data-live="student-today">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Hari ini</p>
                            <h3>Jadwal Pelajaran Hari Ini</h3>
                        </div>
                        <span class="panel-total">{{ $todaySchedules->count() }} jam</span>
                    </div>
                    <div class="teaching-list">
                        @forelse ($todaySchedules as $schedule)
                            @php($session = $schedule->sessions->first())
                            @php($row = $session === null ? null : $historyBySession->get($session->getKey()))
                            <article class="teaching-item">
                                <div class="teaching-slot">
                                    <span
                                        class="teaching-hour">{{ $schedule->lessonHour?->label() ?? 'Jam terhapus' }}</span>
                                    <span
                                        class="teaching-range">{{ $schedule->lessonHour?->range() ?? 'Tanpa rentang jam' }}</span>
                                </div>
                                <div class="teaching-body">
                                    <h4>{{ $schedule->subject }}</h4>
                                    <p>{{ $schedule->teacher?->name ?? 'Guru belum ditentukan' }}</p>
                                    @if ($session === null)
                                        <span class="chip">Absen belum dibuka</span>
                                        <small class="table-note">Tunggu guru membuka absen di kelas ini.</small>
                                    @elseif ($row !== null && $row['present'])
                                        <span class="chip chip-success">Sudah absen
                                            {{ $row['log']?->scanned_at?->format('H:i') }}</span>
                                        <small class="table-note">Kehadiran Anda tercatat pada jam pelajaran
                                            ini.</small>
                                    @elseif ($session->isOpen())
                                        <span class="chip chip-warning">Absen dibuka
                                            {{ $session->opened_at->format('H:i') }}</span>
                                        <small class="table-note">Segera scan sidik jari Anda di sensor kelas.</small>
                                    @else
                                        <span class="chip chip-danger">Absen ditutup, tanpa catatan</span>
                                        <small class="table-note">Absen jam ini sudah ditutup dan tidak ada scan atas
                                            nama Anda. Hubungi guru pengajarnya.</small>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="empty-state">
                                <strong>Tidak ada jadwal pelajaran hari ini</strong>
                                <span>Hari {{ \App\Models\LessonSchedule::dayName($day) }} tidak ada jam pelajaran di
                                    kelas {{ $class->name }}. Jadwal lengkap seminggu ada di bawah.</span>
                            </div>
                        @endforelse
                    </div>
                    <p class="panel-footnote">Sensor kelas hanya mencatat scan selama absen jam pelajaran itu terbuka.
                        Kalau sidik jari Anda belum terdaftar, minta admin sekolah mendaftarkannya lebih dulu, karena
                        tanpa template terdaftar sensor tidak bisa mengenali Anda.</p>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Jadwal tetap</p>
                            <h3>Jadwal Seminggu</h3>
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
                                            <th>Pukul</th>
                                            <th>Mata pelajaran</th>
                                            <th>Guru</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($daySchedules as $schedule)
                                            <tr>
                                                <td data-label="Jam">
                                                    {{ $schedule->lessonHour?->label() ?? 'Jam terhapus' }}</td>
                                                <td data-label="Pukul">
                                                    {{ $schedule->lessonHour?->range() ?? 'Tanpa jam' }}</td>
                                                <td data-label="Mata pelajaran">{{ $schedule->subject }}</td>
                                                <td data-label="Guru">
                                                    {{ $schedule->teacher?->name ?? 'Guru belum ditentukan' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @empty
                        <div class="empty-state">
                            <strong>Jadwal kelas belum diisi</strong>
                            <span>Kelas {{ $class->name }} belum punya jadwal pelajaran. Minta admin mengisinya lewat
                                halaman Jadwal, supaya absen per jam pelajaran bisa dipakai.</span>
                        </div>
                    @endforelse
                </section>

                <section class="panel" data-live="student-history">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Rekap pribadi</p>
                            <h3>Kehadiran Saya</h3>
                        </div>
                        <span class="panel-total">{{ $stats['hadir'] }} hadir dari
                            {{ $history->count() }} pertemuan</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Jam pelajaran</th>
                                    <th>Mata pelajaran</th>
                                    <th>Guru</th>
                                    <th>Status</th>
                                    <th>Pukul scan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($history as $row)
                                    <tr>
                                        <td data-label="Tanggal">
                                            {{ $row['session']->date->translatedFormat('d M Y') }}
                                            <small class="table-note">{{ $row['session']->dayLabel() }}</small>
                                        </td>
                                        <td data-label="Jam pelajaran">
                                            {{ $row['session']->lessonHour()?->label() ?? 'Jam terhapus' }}
                                            <small class="table-note">{{ $row['session']->range() }}</small>
                                        </td>
                                        <td data-label="Mata pelajaran">
                                            {{ $row['session']->scheduleLabel() }}</td>
                                        <td data-label="Guru">{{ $row['session']->teacherName() }}</td>
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
                                        <td colspan="6" class="empty">Belum ada pertemuan yang tercatat untuk kelas
                                            Anda. Catatan muncul setelah guru membuka absen jam pelajaran.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="panel-footnote">Tabel ini menampilkan 20 pertemuan kelas terakhir. Kehadiran dihitung dari
                        scan sidik jari Anda di sensor kelas selama absen guru terbuka, jadi kalau sudah scan tetapi
                        tetap tercatat belum hadir, lapor ke guru atau admin untuk diperiksa.</p>
                </section>
            @endif
        </main>

        <footer>Halaman ini hanya menampilkan jadwal kelas Anda dan catatan kehadiran Anda sendiri.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
