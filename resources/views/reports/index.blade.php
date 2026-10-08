@php
    $query = array_filter([
        'jenis' => $type,
        'dari' => $from->toDateString(),
        'sampai' => $to->toDateString(),
        'kelas' => $schoolClass?->getKey(),
    ]);
    $period = $from->isSameDay($to)
        ? $from->format('d/m/Y')
        : $from->format('d/m/Y') . ' sampai ' . $to->format('d/m/Y');
    $scope = $schoolClass?->name ?? 'Semua kelas';
    $rateChip = fn(?int $rate): string => match (true) {
        $rate === null => 'chip',
        $rate >= 90 => 'chip chip-success',
        $rate >= $attentionRate => 'chip chip-info',
        default => 'chip chip-danger',
    };
    $rateText = fn(?int $rate): string => $rate === null ? '-' : $rate . '%';
    $stats = $report['stats'];
    $rows = $report['rows'];
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">Rekap kehadiran</p>
            <h1>Laporan</h1>
            <p>Rekap kehadiran siswa, guru, dan gerbang untuk tanggal yang dipilih. Bisa dicetak atau diunduh ke
                Excel.</p>
        </header>

        @if ($notice)
            <div class="notice warning">{{ $notice }}</div>
        @endif

        <main class="attendance-layout">
            <section class="panel report-filter">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Filter</p>
                        <h3>Pilih Laporan</h3>
                    </div>
                </div>
                <div class="report-presets" aria-label="Rentang cepat">
                    @foreach ($presets as $presetLabel => $preset)
                        @php
                            $isCurrent =
                                $preset['dari'] === $from->toDateString() && $preset['sampai'] === $to->toDateString();
                        @endphp
                        <a class="button-ghost {{ $isCurrent ? 'is-current' : '' }}"
                            href="{{ route('reports.index', array_filter(['jenis' => $type, 'kelas' => $schoolClass?->getKey()] + $preset)) }}"{!! $isCurrent ? ' aria-current="true"' : '' !!}>{{ $presetLabel }}</a>
                    @endforeach
                </div>
                <form method="GET" action="{{ route('reports.index') }}" class="filter-form report-form">
                    <label>Jenis laporan
                        <select name="jenis">
                            @foreach ($typeLabels as $typeKey => $typeLabel)
                                <option value="{{ $typeKey }}" @selected($type === $typeKey)>{{ $typeLabel }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label>Dari tanggal
                        <input type="date" name="dari" value="{{ $from->toDateString() }}">
                    </label>
                    <label>Sampai tanggal
                        <input type="date" name="sampai" value="{{ $to->toDateString() }}">
                    </label>
                    <label>Kelas
                        <select name="kelas">
                            <option value="">Semua kelas</option>
                            @foreach ($classes as $classOption)
                                <option value="{{ $classOption->getKey() }}" @selected($schoolClass?->getKey() === $classOption->getKey())>
                                    {{ $classOption->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button type="submit">Tampilkan laporan</button>
                        <a class="button-ghost" href="{{ route('reports.export', $query) }}">Unduh CSV</a>
                        <button type="button" class="button-ghost" onclick="window.print()">Cetak</button>
                    </div>
                </form>
            </section>

            <section class="stats" aria-label="Ringkasan laporan">
                @if ($type === 'guru')
                    <article class="stat-card blue">
                        <span>Guru</span>
                        <strong>{{ $stats['teachers'] }}</strong>
                        <small>{{ $scope }}</small>
                    </article>
                    <article class="stat-card green">
                        <span>Pertemuan Dibuka</span>
                        <strong>{{ $stats['meetings'] }}</strong>
                        <small>{{ $period }}</small>
                    </article>
                    <article class="stat-card neutral">
                        <span>Bukti Scan Guru</span>
                        <strong>{{ $rateText($stats['rate']) }}</strong>
                        <small>{{ $stats['scanned'] }} dari {{ $stats['meetings'] }} pertemuan</small>
                    </article>
                    <article class="stat-card orange">
                        <span>Jadwal Tanpa Sesi</span>
                        <strong>{{ $stats['missed'] }}</strong>
                        <small>Jadwal yang absennya tidak dibuka</small>
                    </article>
                @elseif ($type === 'gerbang')
                    <article class="stat-card blue">
                        <span>Siswa Aktif</span>
                        <strong>{{ $stats['members'] }}</strong>
                        <small>{{ $scope }}</small>
                    </article>
                    <article class="stat-card green">
                        <span>Hari Sekolah</span>
                        <strong>{{ $stats['schoolDays'] }}</strong>
                        <small>Senin sampai Jumat, {{ $period }}</small>
                    </article>
                    <article class="stat-card neutral">
                        <span>Rata-rata Kehadiran</span>
                        <strong>{{ $rateText($stats['rate']) }}</strong>
                        <small>{{ $stats['days'] }} hari masuk tercatat</small>
                    </article>
                    <article class="stat-card orange">
                        <span>Terlambat</span>
                        <strong>{{ $stats['late'] }}</strong>
                        <small>Hari dengan absen masuk terlambat</small>
                    </article>
                @else
                    <article class="stat-card blue">
                        <span>Siswa</span>
                        <strong>{{ $stats['students'] }}</strong>
                        <small>{{ $scope }}</small>
                    </article>
                    <article class="stat-card green">
                        <span>Pertemuan Dibuka</span>
                        <strong>{{ $stats['meetings'] }}</strong>
                        <small>{{ $period }}</small>
                    </article>
                    <article class="stat-card neutral">
                        <span>Rata-rata Kehadiran</span>
                        <strong>{{ $rateText($stats['rate']) }}</strong>
                        <small>{{ $stats['present'] }} kehadiran tercatat</small>
                    </article>
                    <article class="stat-card orange">
                        <span>Perlu Perhatian</span>
                        <strong>{{ $stats['attention'] }}</strong>
                        <small>Siswa dengan kehadiran di bawah {{ $attentionRate }}%</small>
                    </article>
                @endif
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">{{ $period }} &middot; {{ $scope }}</p>
                        <h3>{{ $typeLabels[$type] }}</h3>
                    </div>
                    <span class="panel-total">{{ $rows->count() }} baris</span>
                </div>
                <div class="table-wrap">
                    <table class="report-table">
                        @if ($type === 'guru')
                            <thead>
                                <tr>
                                    <th>Guru</th>
                                    <th>Pertemuan</th>
                                    <th>Guru scan</th>
                                    <th>Jadwal tanpa sesi</th>
                                    <th>Siswa hadir</th>
                                    <th>Kehadiran siswa</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        <td data-label="Guru">
                                            <strong>{{ $row['user']->name }}</strong>
                                            <small
                                                class="table-note">{{ $row['user']->subject ?? 'Mata pelajaran belum diisi' }}</small>
                                        </td>
                                        <td data-label="Pertemuan">
                                            {{ $row['meetings'] }}
                                            @if ($row['substitute'] > 0)
                                                <small class="table-note">{{ $row['substitute'] }} sebagai
                                                    pengganti</small>
                                            @endif
                                        </td>
                                        <td data-label="Guru scan">
                                            {{ $row['scanned'] }}
                                            @if ($row['unscanned'] > 0)
                                                <small class="table-note">{{ $row['unscanned'] }} pertemuan tanpa scan
                                                    guru</small>
                                            @endif
                                        </td>
                                        <td data-label="Jadwal tanpa sesi">{{ $row['missed'] }}</td>
                                        <td data-label="Siswa hadir">{{ $row['studentsPresent'] }} dari
                                            {{ $row['studentsExpected'] }}</td>
                                        <td data-label="Kehadiran siswa">
                                            <span
                                                class="{{ $rateChip($row['rate']) }}">{{ $rateText($row['rate']) }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="empty">Belum ada guru yang punya jadwal atau
                                            pertemuan
                                            pada pilihan ini.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        @elseif ($type === 'gerbang')
                            <thead>
                                <tr>
                                    <th>Siswa</th>
                                    <th>Kelas</th>
                                    <th>Masuk</th>
                                    <th>Terlambat</th>
                                    <th>Pulang</th>
                                    <th>Tidak hadir</th>
                                    <th>Kehadiran</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        <td data-label="Siswa">
                                            <strong>{{ $row['user']->name }}</strong>
                                            <small
                                                class="table-note">{{ $row['user']->identifier_number ?? 'Tanpa nomor induk' }}</small>
                                        </td>
                                        <td data-label="Kelas">{{ $row['class'] }}</td>
                                        <td data-label="Masuk">
                                            {{ $row['days'] }} dari {{ $row['schoolDays'] }}
                                            @if ($row['permission'] > 0)
                                                <small class="table-note">{{ $row['permission'] }} hari izin</small>
                                            @endif
                                        </td>
                                        <td data-label="Terlambat">{{ $row['late'] }}</td>
                                        <td data-label="Pulang">{{ $row['checkOut'] }}</td>
                                        <td data-label="Tidak hadir">{{ $row['absent'] }}</td>
                                        <td data-label="Kehadiran">
                                            <span
                                                class="{{ $rateChip($row['rate']) }}">{{ $rateText($row['rate']) }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="empty">Tidak ada anggota aktif pada pilihan ini.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        @else
                            <thead>
                                <tr>
                                    <th>Siswa</th>
                                    <th>Kelas</th>
                                    <th>Pertemuan</th>
                                    <th>Hadir</th>
                                    <th>Tidak hadir</th>
                                    <th>Kehadiran</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        <td data-label="Siswa">
                                            <strong>{{ $row['user']->name }}</strong>
                                            <small
                                                class="table-note">{{ $row['user']->identifier_number ?? 'Tanpa nomor induk' }}</small>
                                        </td>
                                        <td data-label="Kelas">{{ $row['class'] }}</td>
                                        <td data-label="Pertemuan">{{ $row['meetings'] }}</td>
                                        <td data-label="Hadir">{{ $row['present'] }}</td>
                                        <td data-label="Tidak hadir">{{ $row['absent'] }}</td>
                                        <td data-label="Kehadiran">
                                            <span
                                                class="{{ $rateChip($row['rate']) }}">{{ $rateText($row['rate']) }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="empty">Belum ada siswa pada pilihan ini. Siswa muncul
                                            di
                                            laporan setelah didaftarkan di halaman <a
                                                href="{{ route('members.index') }}">Anggota</a>
                                            dengan kelasnya.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        @endif
                    </table>
                </div>
                <p class="panel-footnote">
                    @if ($type === 'guru')
                        Jadwal tanpa sesi adalah jadwal mingguan yang harinya sudah lewat di rentang ini tetapi absennya
                        tidak pernah dibuka. Hari libur ikut terhitung karena aplikasi belum menyimpan kalender libur.
                        Pertemuan yang dipegang guru pengganti dicatat atas nama guru pengganti.
                    @elseif ($type === 'gerbang')
                        Satu hari dihitung sekali walau siswa menempelkan jari berkali-kali. Hari sekolah dihitung
                        Senin sampai Jumat sampai hari ini, jadi hari libur ikut terhitung sebagai tidak hadir.
                    @else
                        Pertemuan hanya dihitung dari sesi absen yang dibuka guru atau admin untuk kelas siswa itu.
                        Jadwal yang tidak dibuka absennya tidak membuat siswa terlihat tidak hadir. Tanda "-" berarti
                        kelas siswa itu belum punya pertemuan di rentang ini.
                    @endif
                </p>
            </section>
        </main>

        <footer>Laporan disusun langsung dari catatan presensi sensor dan sesi absen jam pelajaran.</footer>
    </div>
</body>

</html>
