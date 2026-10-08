@php
    $maxTotal = max(1, (int) collect($recap)->max('total'));
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="topbar">
            <div>
                <p class="eyebrow">Hari ini</p>
                <h1>Dashboard</h1>
                <p class="topbar-copy">Kehadiran di jam pelajaran hari ini dan kondisi alat sensor.</p>
            </div>
            <div class="topbar-note">{{ $today->translatedFormat('l, d F Y') }}</div>
        </header>

        @if (session('success'))
            <div class="notice success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="notice error">{{ $errors->first() }}</div>
        @endif

        <main>
            <section class="stats" aria-label="Ringkasan hari ini" data-live="dashboard-stats">
                <article class="stat-card blue">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <rect x="3.5" y="4.5" width="17" height="16" rx="2.5" />
                            <path d="M3.5 9.5h17M8 3.5v3M16 3.5v3" />
                        </svg>
                    </span>
                    <span>Jam Pelajaran Hari Ini</span>
                    <strong>{{ $stats['lessons'] }}</strong>
                    <small>{{ $stats['opened'] }} sudah dibuka absennya</small>
                </article>

                <article class="stat-card orange">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="8.5" />
                            <path d="M12 7.4V12l3.1 2.1" />
                        </svg>
                    </span>
                    <span>Absen Sedang Dibuka</span>
                    <strong>{{ $stats['open'] }}</strong>
                    <small>Siswa bisa scan sekarang</small>
                </article>

                <article class="stat-card green">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <circle cx="9.5" cy="8.5" r="3.5" />
                            <path d="M3.5 20.5c0-3.3 2.7-6 6-6s6 2.7 6 6" />
                            <path d="m16.4 8.8 2 2 3.6-3.6" />
                        </svg>
                    </span>
                    <span>Siswa Hadir di Kelas</span>
                    <strong>{{ $stats['studentsPresent'] }}</strong>
                    <small>Dari {{ $stats['students'] }} siswa aktif</small>
                </article>

                <article class="stat-card neutral">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <rect x="5" y="2.5" width="14" height="19" rx="2.5" />
                            <path d="M10 18.4h4" />
                        </svg>
                    </span>
                    <span>Alat Sensor Aktif</span>
                    <strong>{{ $stats['devices'] }}</strong>
                    <small>{{ $stats['unmapped'] > 0 ? $stats['unmapped'].' alat baru menunggu didaftarkan' : 'Semua alat sudah didaftarkan' }}</small>
                </article>
            </section>

            <section class="panel dashboard-lessons" data-live="dashboard-lessons">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">{{ \App\Models\LessonSchedule::dayName((int) $today->dayOfWeekIso) }}</p>
                        <h3>Jam Pelajaran Hari Ini</h3>
                    </div>
                    <a class="button-ghost" href="{{ route('sessions.index') }}">Buka Sesi Absen</a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Jam</th>
                                <th>Kelas</th>
                                <th>Pelajaran dan guru</th>
                                <th>Absen</th>
                                <th>Hadir</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lessons as $lesson)
                                @php($session = $lesson['session'])
                                <tr>
                                    <td data-label="Jam">
                                        <strong>{{ $lesson['schedule']->lessonHour?->label() ?? 'Jam terhapus' }}</strong>
                                        <small class="table-note">{{ $lesson['schedule']->lessonHour?->range() }}</small>
                                    </td>
                                    <td data-label="Kelas">{{ $lesson['schedule']->schoolClass?->name ?? 'Kelas terhapus' }}</td>
                                    <td data-label="Pelajaran dan guru">
                                        {{ $lesson['schedule']->subject }}
                                        <small class="table-note">{{ $session?->teacherName() ?? ($lesson['schedule']->teacher?->name ?? 'Guru belum diisi') }}</small>
                                    </td>
                                    <td data-label="Absen">
                                        @if ($session === null)
                                            <span class="chip">Belum dibuka</span>
                                        @else
                                            <span class="{{ $session->chipClass() }}">{{ $session->statusLabel() }}</span>
                                        @endif
                                    </td>
                                    <td data-label="Hadir">{{ $lesson['present'] }} dari {{ $lesson['students'] }}</td>
                                    <td data-label="Aksi">
                                        @if ($session)
                                            <a class="button-ghost" href="{{ route('sessions.show', $session) }}">Rekap</a>
                                        @else
                                            <span class="table-note">Menunggu guru</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="empty">Tidak ada jam pelajaran di jadwal hari ini. Jadwal
                                        diisi di halaman <a href="{{ route('schedules.index') }}">Jadwal Pelajaran</a>.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="dashboard-grid">
                <section class="panel" id="presensi" data-live="dashboard-attendance-log">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Terbaru</p>
                            <h3>Scan Terakhir Hari Ini</h3>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>NIS</th>
                                    <th>Kelas</th>
                                    <th>Pukul</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($logs as $log)
                                    <tr>
                                        <td data-label="Nama">
                                            <strong>{{ $log->user?->name ?? 'Pengguna terhapus' }}</strong><small>{{ $log->user?->roleLabel() }}</small>
                                        </td>
                                        <td data-label="NIS">{{ $log->user?->identifier_number ?: 'Belum diisi' }}</td>
                                        <td data-label="Kelas">
                                            {{ $log->lessonSession?->schoolClassName() ?? ($log->user?->class_name ?: '-') }}</td>
                                        <td data-label="Pukul">{{ $log->scanned_at->format('H:i') }}</td>
                                        <td data-label="Status">
                                            <span class="chip {{ $log->chipClass() }}">{{ $log->statusLabel() }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="empty">Belum ada yang scan hari ini.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="panel" id="rekap">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Tujuh hari terakhir</p>
                            <h3>Siswa Hadir per Hari</h3>
                        </div>
                        <span class="panel-total">{{ $recap[0]['date'] }} - {{ $recap[6]['date'] }}</span>
                    </div>
                    <div class="chart" role="img"
                        aria-label="Grafik batang jumlah siswa yang hadir di jam pelajaran selama tujuh hari terakhir">
                        @foreach ($recap as $day)
                            <div class="chart-col">
                                <span class="chart-value">{{ $day['total'] }}</span>
                                <div class="chart-bars">
                                    <span class="chart-bar chart-bar-hadir"
                                        style="--value: {{ round(($day['total'] / $maxTotal) * 100) }}"></span>
                                </div>
                                <span class="chart-label">{{ $day['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="panel-footnote">Satu siswa dihitung sekali per hari walau hadir di beberapa jam
                        pelajaran. Rekap lengkap ada di halaman <a href="{{ route('reports.index') }}">Laporan</a>.</p>
                </section>
            </div>

            <div class="content-grid">
                <section class="panel" id="devices" data-live="dashboard-devices">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Alat sensor</p>
                            <h3>Status Alat</h3>
                        </div>
                        <a class="button-ghost" href="{{ route('devices.index') }}">Kelola Alat</a>
                    </div>
                    <div class="device-list">
                        @forelse ($devices as $device)
                            <article class="device-item">
                                <div class="device-heading">
                                    <span>
                                        <strong>{{ $device->label() }}</strong>
                                        <small>{{ $device->location ?: 'Belum ada lokasi' }}</small>
                                        <small>{{ $device->last_ping ? 'Terakhir terhubung ' . $device->last_ping->diffForHumans() : 'Belum pernah terhubung' }}</small>
                                    </span>
                                    <em class="status-{{ $device->status }}">{{ $device->statusLabel() }}</em>
                                </div>
                            </article>
                        @empty
                            <p class="empty">Belum ada alat sensor yang terhubung.</p>
                        @endforelse
                    </div>
                </section>

                <section class="panel enrollment-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Sidik jari</p>
                            <h3>Belum Punya Sidik Jari</h3>
                        </div>
                        <span class="panel-total" data-live="dashboard-enrollments">{{ $withoutFingerprint }} anggota</span>
                    </div>
                    <p class="panel-hint">Siswa dan guru ini belum bisa absen. Daftarkan sidik jarinya dari tombol Scan
                        sidik jari di halaman Anggota.</p>
                    <a class="button-link"
                        href="{{ route('members.index', ['fingerprint' => 'pending']) }}#cari-anggota">Lihat daftarnya</a>
                </section>
            </div>
        </main>
        <footer>Data sidik jari disimpan privat dan hanya dipakai untuk mencatat kehadiran.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
