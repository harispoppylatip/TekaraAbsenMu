<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sesi Absen | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">Pemantauan absen</p>
            <h1>Sesi Absen</h1>
            <p>Satu sesi absen adalah satu jam pelajaran pada satu tanggal. Guru membuka absennya sendiri, admin
                bisa membuka, menjadwalkan, menutup, atau menunjuk guru pengganti di sini.</p>
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
            <section class="stats" aria-label="Ringkasan sesi absen">
                <article class="stat-card blue">
                    <span>Pertemuan</span>
                    <strong>{{ $stats['total'] }}</strong>
                    <small>{{ $date->format('d/m/Y') }} &middot;
                        {{ \App\Models\LessonSchedule::dayName((int) $date->dayOfWeekIso) }}</small>
                </article>
                <article class="stat-card green">
                    <span>Absen Dibuka</span>
                    <strong>{{ $stats['open'] }}</strong>
                    <small>Masih menerima scan</small>
                </article>
                <article class="stat-card neutral">
                    <span>Terjadwal</span>
                    <strong>{{ $stats['scheduled'] }}</strong>
                    <small>Menunggu jam mulai</small>
                </article>
                <article class="stat-card orange">
                    <span>Scan Tercatat</span>
                    <strong>{{ $stats['scans'] }}</strong>
                    <small>Dari semua pertemuan di tanggal ini</small>
                </article>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Buka untuk admin</p>
                        <h3>Buka Absen</h3>
                    </div>
                </div>
                <p class="panel-hint">Admin boleh membuka absen kapan saja untuk hari ini. Jika memilih tanggal
                    mendatang, sesi akan dijadwalkan otomatis sesuai jam pelajaran dan mulai menerima scan saat jamnya
                    tiba. Guru hanya bisa membuka jadwalnya sendiri pada hari yang sesuai dan di dalam jam pelajarannya.
                    Satu jadwal hanya punya satu sesi per tanggal. Menjadwalkan dari sini bersifat opsional; jika tidak
                    dijadwalkan, guru tetap dapat memulainya dari akun guru saat jam pelajarannya tiba.</p>
                @if ($schedules->isEmpty())
                    <div class="empty-state">
                        <strong>Belum ada jadwal pelajaran</strong>
                        <span>Isi dulu jadwal pelajaran di halaman <a href="{{ route('schedules.index') }}">Jadwal
                                Pelajaran</a>,
                            karena sesi absen selalu
                            mengikuti satu jadwal pelajaran.</span>
                    </div>
                @else
                    <form method="POST" action="{{ route('sessions.store') }}" class="form-grid">
                        @csrf
                        <label>Tanggal pertemuan
                            <input type="date" name="date" value="{{ old('date', $date->toDateString()) }}"
                                required>
                        </label>
                        <label>Jadwal pelajaran
                            <select name="lesson_schedule_id" required>
                                @foreach ($schedules as $day => $daySchedules)
                                    <optgroup label="{{ $dayNames[$day] ?? 'Hari' }}">
                                        @foreach ($daySchedules as $schedule)
                                            <option value="{{ $schedule->getKey() }}" @selected((int) old('lesson_schedule_id') === (int) $schedule->getKey())>
                                                {{ $schedule->lessonHour?->label() }} &middot;
                                                {{ $schedule->schoolClass?->name }} &middot;
                                                {{ $schedule->subject }} &middot;
                                                {{ $schedule->teacher?->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </label>
                        <label>Guru pengganti (opsional)
                            <select name="substitute_user_id">
                                <option value="">Tanpa guru pengganti</option>
                                @foreach ($teachers as $teacher)
                                    <option value="{{ $teacher->getKey() }}" @selected((int) old('substitute_user_id') === (int) $teacher->getKey())>
                                        {{ $teacher->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Keterangan (opsional)
                            <input name="note" value="{{ old('note') }}" maxlength="100"
                                placeholder="Contoh: guru berhalangan, diganti pak Budi">
                        </label>
                        <div class="form-help">Guru pengganti yang dipilih bisa membuka dan menutup absen pertemuan itu
                            dari akunnya sendiri, dan kehadirannya dicatat atas namanya.</div>
                        <button type="submit">Buka atau Jadwalkan</button>
                    </form>
                @endif
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Filter</p>
                        <h3>Cari Sesi</h3>
                    </div>
                </div>
                <form method="GET" action="{{ route('sessions.index') }}" class="filter-form">
                    <label>Tanggal
                        <input type="date" name="date" value="{{ $date->toDateString() }}">
                    </label>
                    <label>Kelas
                        <select name="class">
                            <option value="">Semua kelas</option>
                            @foreach ($classes as $schoolClass)
                                <option value="{{ $schoolClass->getKey() }}" @selected($filters['class'] === $schoolClass->getKey())>
                                    {{ $schoolClass->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Status
                        <select name="status">
                            <option value="">Semua status</option>
                            <option value="open" @selected($filters['status'] === 'open')>Masih dibuka</option>
                            <option value="closed" @selected($filters['status'] === 'closed')>Sudah ditutup</option>
                            <option value="scheduled" @selected($filters['status'] === 'scheduled')>Terjadwal</option>
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button type="submit">Terapkan filter</button>
                        <a class="button-ghost" href="{{ route('sessions.index') }}">Hari ini</a>
                    </div>
                </form>
            </section>

            <section class="panel" data-live="sessions-of-day">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Daftar pertemuan</p>
                        <h3>Sesi {{ $date->format('d/m/Y') }}</h3>
                    </div>
                    <span class="panel-total">{{ $sessions->count() }} pertemuan &middot; {{ $stats['scans'] }}
                        scan</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Jam dan kelas</th>
                                <th>Pelajaran</th>
                                <th>Guru</th>
                                <th>Status</th>
                                <th>Hadir</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sessions as $session)
                                <tr>
                                    <td data-label="Jam dan kelas">
                                        <strong>{{ $session->lessonHour()?->label() ?? 'Jam terhapus' }}</strong>
                                        <small class="table-note">
                                            {{ $session->schoolClassName() ?? 'Kelas terhapus' }}
                                            @if ($session->lessonHour())
                                                &middot; {{ $session->lessonHour()->range() }}
                                            @endif
                                            @if ($session->isScheduled())
                                                &middot; mulai {{ $session->scheduled_at->format('H:i') }}
                                            @else
                                                &middot; dibuka {{ $session->opened_at->format('H:i') }}
                                            @endif
                                        </small>
                                    </td>
                                    <td data-label="Pelajaran">
                                        {{ $session->lessonSchedule?->subject ?? 'Pelajaran terhapus' }}
                                        @if (filled($session->note))
                                            <small class="table-note">{{ $session->note }}</small>
                                        @endif
                                    </td>
                                    <td data-label="Guru">
                                        {{ $session->teacherName() }}
                                        @if ($session->isSubstituted())
                                            <small class="table-note">Guru pengganti, aslinya
                                                {{ $session->lessonSchedule?->teacher?->name ?? 'guru terhapus' }}</small>
                                        @endif
                                    </td>
                                    <td data-label="Status">
                                        <span class="{{ $session->chipClass() }}">{{ $session->statusLabel() }}</span>
                                        @if ($session->closed_at)
                                            <small class="table-note">Ditutup
                                                {{ $session->closed_at->format('H:i') }}
                                                oleh {{ $session->closedBy?->name ?? 'admin' }}</small>
                                        @endif
                                    </td>
                                    <td data-label="Hadir">
                                        <span class="chip chip-info">{{ $session->logs_count }} scan</span>
                                    </td>
                                </tr>
                            @empty
                                @if ($availableSchedules->isEmpty())
                                    <tr>
                                        <td colspan="5" class="empty">Belum ada jadwal atau sesi pada tanggal ini.
                                            Guru dapat membuka absennya dari halaman mengajar, atau admin dapat memilih
                                            jadwal dari formulir di atas.</td>
                                    </tr>
                                @endif
                            @endforelse
                            @foreach ($availableSchedules as $schedule)
                                <tr>
                                    <td data-label="Jam dan kelas">
                                        <strong>{{ $schedule->lessonHour?->label() ?? 'Jam terhapus' }}</strong>
                                        <small class="table-note">
                                            {{ $schedule->schoolClass?->name ?? 'Kelas terhapus' }}
                                            @if ($schedule->lessonHour)
                                                &middot; {{ $schedule->lessonHour->range() }}
                                            @endif
                                        </small>
                                    </td>
                                    <td data-label="Pelajaran">{{ $schedule->subject }}</td>
                                    <td data-label="Guru">{{ $schedule->teacher?->name ?? 'Guru terhapus' }}</td>
                                    <td data-label="Status"><span class="chip">Belum dibuka</span></td>
                                    <td data-label="Hadir">-</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="panel-footnote">Menutup sesi tidak menghapus catatan kehadiran, hanya menghentikan penerimaan
                    scan baru dari sensor kelas. Tabel ini menyesuaikan diri sendiri setiap beberapa detik, jadi
                    pertemuan yang baru dibuka guru langsung muncul di sini.</p>
            </section>
        </main>

        <footer>Sesi absen menghubungkan sensor kelas dengan jadwal pelajaran dan catatan kehadiran tiap siswa.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
