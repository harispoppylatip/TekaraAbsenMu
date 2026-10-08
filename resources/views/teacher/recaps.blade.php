<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Mengajar | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">Riwayat</p>
            <h1>Rekap Mengajar</h1>
            <p>Semua pertemuan yang pernah Anda pegang, terbaru di atas. Rekapnya bisa dilihat atau diunduh.</p>
        </header>

        @if ($errors->any())
            <div class="notice error">{{ $errors->first() }}</div>
        @endif

        @php($totalScans = $sessions->sum('logs_count'))

        <main class="attendance-layout">
            <section class="stats" aria-label="Ringkasan riwayat mengajar">
                <article class="stat-card blue">
                    <span>Pertemuan Tercatat</span>
                    <strong>{{ $sessions->count() }}</strong>
                    <small>Dari absen yang pernah Anda buka</small>
                </article>
                <article class="stat-card green">
                    <span>Scan Siswa</span>
                    <strong>{{ $totalScans }}</strong>
                    <small>Total catatan kehadiran</small>
                </article>
                <article class="stat-card orange">
                    <span>Rata-rata per Pertemuan</span>
                    <strong>{{ $sessions->isEmpty() ? 0 : round($totalScans / $sessions->count()) }}</strong>
                    <small>Siswa hadir tiap jam</small>
                </article>
                <article class="stat-card neutral">
                    <span>Masih Dibuka</span>
                    <strong>{{ $sessions->where('closed_at', null)->count() }}</strong>
                    <small>Belum ditutup</small>
                </article>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Unduh rekap</p>
                        <h3>Simpan Daftar Hadir sebagai CSV</h3>
                    </div>
                </div>
                @if ($classes->isEmpty())
                    <p class="panel-hint">Belum ada pertemuan yang bisa diunduh. Tombol unduh muncul setelah Anda
                        membuka absen minimal satu kali.</p>
                @else
                    <div class="recap-download">
                        <div class="recap-download-option">
                            <strong>Semua kelas</strong>
                            <span>Semua pertemuan di {{ $classes->count() }} kelas yang Anda ampu.</span>
                            <a class="button-primary"
                                href="{{ route('teacher.recaps.export') }}">Unduh CSV semua kelas</a>
                        </div>
                        <form method="GET" action="{{ route('teacher.recaps.export') }}"
                            class="recap-download-option">
                            <strong>Satu kelas</strong>
                            <label>Pilih kelas
                                <select name="kelas" required>
                                    @foreach ($classes as $schoolClass)
                                        <option value="{{ $schoolClass->getKey() }}">{{ $schoolClass->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="submit">Unduh CSV kelas ini</button>
                        </form>
                    </div>
                    <p class="panel-footnote">File berisi satu baris untuk setiap siswa di setiap pertemuan: tanggal,
                        jam, kelas, pelajaran, nama, nomor induk, status hadir, dan jam scan. File langsung bisa dibuka
                        di Excel. Rekap satu pertemuan saja bisa diunduh dari tombol Rekap di tabel bawah.</p>
                @endif
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Daftar pertemuan</p>
                        <h3>Absen yang Pernah Anda Pegang</h3>
                    </div>
                    <span class="panel-total">{{ $sessions->count() }} pertemuan</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Jam dan kelas</th>
                                <th>Pelajaran</th>
                                <th>Peran</th>
                                <th>Status</th>
                                <th>Hadir</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sessions as $session)
                                <tr>
                                    <td data-label="Tanggal">
                                        {{ $session->date->translatedFormat('d M Y') }}
                                        <small class="table-note">{{ $session->dayLabel() }}</small>
                                    </td>
                                    <td data-label="Jam dan kelas">
                                        {{ $session->lessonHour()?->label() ?? 'Jam terhapus' }}
                                        <small class="table-note">
                                            {{ $session->schoolClassName() ?? 'Kelas terhapus' }}
                                            @if ($session->lessonHour())
                                                &middot; {{ $session->lessonHour()->range() }}
                                            @endif
                                        </small>
                                    </td>
                                    <td data-label="Pelajaran">{{ $session->scheduleLabel() }}</td>
                                    <td data-label="Peran">
                                        @if ($session->isSubstituted())
                                            <span class="chip chip-warning">Guru pengganti</span>
                                        @else
                                            <span class="chip">Guru pengampu</span>
                                        @endif
                                    </td>
                                    <td data-label="Status">
                                        <span class="{{ $session->chipClass() }}">{{ $session->statusLabel() }}</span>
                                        @if ($session->closed_at)
                                            <small class="table-note">Ditutup
                                                {{ $session->closed_at->format('H:i') }}</small>
                                        @endif
                                    </td>
                                    <td data-label="Hadir">
                                        <span class="chip chip-info">{{ $session->logs_count }} scan</span>
                                    </td>
                                    <td data-label="Aksi">
                                        <div class="table-actions">
                                            <a class="button-ghost"
                                                href="{{ route('teacher.sessions.recap', $session) }}">Rekap</a>
                                            @if ($session->isOpen())
                                                <form method="POST"
                                                    action="{{ route('teacher.sessions.close', $session) }}"
                                                    onsubmit="return window.confirm('Tutup absen {{ $session->label() }} tanggal {{ $session->date->toDateString() }}?')">
                                                    @csrf
                                                    <button type="submit" class="button-primary">Tutup</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="empty">Belum ada pertemuan yang Anda buka. Buka absen
                                        dari
                                        halaman <a href="{{ route('teacher.index') }}">Jadwal Mengajar</a> saat jam
                                        pelajaran Anda berjalan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="panel-footnote">Pertemuan yang dibuka admin atas nama Anda juga muncul di sini, termasuk yang
                    Anda pegang sebagai guru pengganti.</p>
            </section>
        </main>

        <footer>Riwayat ini membantu mencocokkan catatan presensi dengan jam pelajaran yang Anda ampu.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
