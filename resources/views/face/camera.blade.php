@php
    $lastLog = $recentLogs->first();
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Absen Wajah | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body data-face-scan-url="{{ route('face.attendance.scan') }}">
    <div class="shell">
        @include('partials.navigation')
        <header class="page-heading">
            <p class="eyebrow">Absen wajah</p>
            <h1>Kamera Absen Wajah</h1>
            <p>Arahkan wajah ke kamera. Setiap wajah yang dikenali langsung dicatat sebagai absen masuk atau
                pulang sesuai jam presensi yang berlaku di halaman Presensi Gerbang.</p>
        </header>

        @if ($deviceRefusal !== null)
            <div class="notice warning">
                Kamera wajah belum bisa dipakai. {{ $deviceRefusal['message'] }} Hubungi admin sekolah kalau
                perangkat ini harus dinyalakan admin.
            </div>
        @endif

        @unless ($serviceAvailable)
            <div class="notice warning">
                Layanan wajah di server belum aktif, jadi wajah belum bisa dibaca. Nyalakan layanan wajah di
                komputer server, lalu muat ulang halaman ini.
            </div>
        @endunless

        <main class="face-layout">
            <section class="panel camera-panel">
                <div class="panel-heading">
                    <div>
                        <h3>Kamera Petugas</h3>
                    </div>
                    <span class="panel-total" data-camera-status>
                        {{ $deviceRefusal !== null ? 'Perangkat belum siap' : 'Kamera belum dinyalakan' }}
                    </span>
                </div>

                <div class="camera-stage">
                    <video class="camera-video" data-camera-video playsinline muted></video>
                    <p class="camera-placeholder" data-camera-placeholder>
                        Tekan Nyalakan kamera, lalu izinkan akses kamera saat browser meminta.
                    </p>
                </div>

                <div class="camera-result" data-camera-result hidden>
                    <strong data-camera-name></strong>
                    <span data-camera-detail></span>
                </div>

                <div class="camera-actions">
                    <button type="button" data-camera-start @disabled($deviceRefusal !== null)>Nyalakan
                        kamera</button>
                    <button type="button" class="button-ghost" data-camera-stop disabled>Hentikan kamera</button>
                </div>

                <p class="panel-footnote">
                    Gambar hanya dikirim ke server sekolah untuk dibaca mesin wajah, dan tidak disimpan sebagai
                    berkas foto. Yang tersimpan cuma angka pembanding wajah anggota.
                </p>
            </section>

            <section class="panel" data-live="face-recent">
                <div class="panel-heading">
                    <div>
                        <h3>Absen Wajah Terakhir</h3>
                    </div><span class="panel-total">{{ $recentLogs->count() }} terbaru</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>NIS</th>
                                <th>Waktu</th>
                                <th>Jenis Absen</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentLogs as $log)
                                <tr>
                                    <td data-label="Nama"><strong>{{ $log->user?->name ?? 'Anggota terhapus' }}</strong>
                                    </td>
                                    <td data-label="NIS">{{ $log->user?->identifier_number ?: 'Belum diisi' }}</td>
                                    <td data-label="Waktu">{{ $log->scanned_at->format('d M Y, H:i') }}</td>
                                    <td data-label="Jenis Absen">{{ $log->typeLabel() }}</td>
                                    <td data-label="Status">
                                        <span
                                            class="chip {{ $log->chipClass() }}">{{ $log->historyStatusLabel() }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="empty">Belum ada yang absen lewat kamera wajah.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($lastLog !== null)
                    <p class="panel-footnote">
                        Wajah terakhir yang tercatat: {{ $lastLog->user?->name }} pukul
                        {{ $lastLog->scanned_at->format('H:i') }}.
                    </p>
                @endif
            </section>
        </main>
    </div>
</body>

</html>
