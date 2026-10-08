<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug API | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')
        <header class="page-heading">
            <p class="eyebrow">Debug sementara</p>
            <h1>Request fingerprint terakhir</h1>
            <p>Halaman ini menampilkan request terakhir yang diterima endpoint scan.</p>
        </header>

        <main class="debug-grid" data-live="debug-request">
            @if ($requestData)
                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Informasi request</p>
                            <h3>{{ $requestData['method'] }} {{ $requestData['url'] }}</h3>
                        </div>
                        <span class="panel-total">{{ $requestData['received_at'] }}</span>
                    </div>
                    <dl class="debug-details">
                        <div>
                            <dt>Alamat IP</dt>
                            <dd>{{ $requestData['ip'] ?: 'Tidak diketahui' }}</dd>
                        </div>
                        <div>
                            <dt>Content-Type</dt>
                            <dd>{{ $requestData['content_type'] ?: 'Tidak dikirim' }}</dd>
                        </div>
                        <div>
                            <dt>User-Agent</dt>
                            <dd>{{ $requestData['user_agent'] ?: 'Tidak dikirim' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Isi data</p>
                            <h3>Payload JSON</h3>
                        </div>
                    </div>
                    <pre class="debug-json">{{ json_encode($requestData['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </section>
            @else
                <section class="panel debug-empty">
                    <h3>Belum ada request</h3>
                    <p>Jalankan scan dari ESP32, halaman ini memperbarui sendiri.</p>
                </section>
            @endif
        </main>
        <footer>Halaman debug sementara.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
