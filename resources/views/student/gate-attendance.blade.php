@php
    $period = $from->isSameDay($to)
        ? $from->translatedFormat('d F Y')
        : $from->translatedFormat('d F Y') . ' sampai ' . $to->translatedFormat('d F Y');
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datang dan Pulang | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">Presensi gerbang</p>
            <h1>Datang dan Pulang</h1>
            <p>Riwayat scan Anda di sensor gerbang sekolah. Pilih tanggal untuk melihat catatan pada rentang tertentu.
            </p>
        </header>

        <main class="attendance-layout">
            <section class="panel report-filter">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Pilih tanggal</p>
                        <h3>Filter Riwayat</h3>
                    </div>
                </div>
                <form method="GET" action="{{ route('student.gate-attendance') }}" class="filter-form report-form">
                    <label>Dari tanggal
                        <input type="date" name="dari" value="{{ $from->toDateString() }}">
                    </label>
                    <label>Sampai tanggal
                        <input type="date" name="sampai" value="{{ $to->toDateString() }}">
                    </label>
                    <div class="filter-actions">
                        <button type="submit">Tampilkan riwayat</button>
                        <a class="button-ghost" href="{{ route('student.gate-attendance') }}">Bulan ini</a>
                    </div>
                </form>
            </section>

            <section class="stats" aria-label="Ringkasan presensi gerbang">
                <article class="stat-card blue">
                    <span>Total Scan</span>
                    <strong>{{ $stats['total'] }}</strong>
                    <small>{{ $period }}</small>
                </article>
                <article class="stat-card green">
                    <span>Datang</span>
                    <strong>{{ $stats['masuk'] }}</strong>
                    <small>{{ $stats['masuk'] > 0 ? 'Absen masuk' : 'Belum ada scan masuk' }}</small>
                </article>
                <article class="stat-card orange">
                    <span>Pulang</span>
                    <strong>{{ $stats['pulang'] }}</strong>
                    <small>{{ $stats['pulang'] > 0 ? 'Absen pulang' : 'Belum ada scan pulang' }}</small>
                </article>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">{{ $period }}</p>
                        <h3>Riwayat Scan Gerbang</h3>
                    </div>
                    <span class="panel-total">{{ $logs->count() }} catatan</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Jenis Absen</th>
                                <th>Keterangan</th>
                                <th>Perangkat</th>
                                <th>Pukul</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($logs as $log)
                                <tr>
                                    <td data-label="Tanggal">{{ $log->scanned_at->translatedFormat('d M Y') }}</td>
                                    <td data-label="Jenis Absen"><span
                                            class="chip {{ $log->type === \App\Models\AttendanceLog::TypeOut ? 'chip-info' : 'chip-success' }}">{{ $log->typeLabel() }}</span>
                                    </td>
                                    <td data-label="Keterangan"><span
                                            class="chip {{ $log->chipClass() }}">{{ $log->historyStatusLabel() }}</span>
                                    </td>
                                    <td data-label="Perangkat">{{ $log->device?->name ?: $log->device_id }}</td>
                                    <td data-label="Pukul">{{ $log->scanned_at->format('H:i:s') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="empty">Belum ada catatan datang atau pulang pada tanggal
                                        yang dipilih.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="panel-footnote">Catatan ini hanya berasal dari sensor gerbang. Absen masuk dicatat saat
                    datang pagi, absen pulang saat meninggalkan sekolah. Kalau Anda merasa sudah menempelkan jari
                    tetapi tetap tidak tercatat, lapor ke admin sekolah. Absen pada jam pelajaran ada di menu <a
                        href="{{ route('student.index') }}">Jadwal Pelajaran Saya</a>.</p>
            </section>
        </main>

        <footer>Riwayat presensi pribadi Anda dari sensor gerbang sekolah.</footer>
    </div>
</body>

</html>
