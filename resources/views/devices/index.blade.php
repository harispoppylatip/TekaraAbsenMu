<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alat Sensor | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">Pusat kontrol sensor</p>
            <h1>Alat Sensor</h1>
            <p>Alat sensor sidik jari yang terhubung ke sekolah. Alat baru harus didaftarkan dulu di sini sebelum
                bisa dipakai.</p>
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

        <main class="device-layout">
            <section class="stats" aria-label="Ringkasan alat sensor" data-live="devices-stats">
                <article class="stat-card green">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <rect x="5" y="2.5" width="14" height="19" rx="2.5" />
                            <path d="M10 18.4h4" />
                            <path d="m9 11.6 2 2 4-4" />
                        </svg>
                    </span>
                    <span>Alat Aktif</span>
                    <strong>{{ $stats['active'] }}</strong>
                    <small>Terdaftar dan siap menerima scan</small>
                </article>

                <article class="stat-card orange">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M12 3.5V11" />
                            <path d="M6.8 7.4a7.5 7.5 0 1 0 10.4 0" />
                        </svg>
                    </span>
                    <span>Dimatikan</span>
                    <strong>{{ $stats['inactive'] }}</strong>
                    <small>Terdaftar tetapi menolak semua scan</small>
                </article>

                <article class="stat-card blue">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="8.5" />
                            <path d="M12 7.4V12l3.1 2.1" />
                        </svg>
                    </span>
                    <span>Menunggu Pendaftaran</span>
                    <strong>{{ $stats['pending'] }}</strong>
                    <small>Alat baru terhubung, belum boleh dipakai</small>
                </article>

                <article class="stat-card neutral">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <rect x="3.5" y="4.5" width="7" height="15" rx="2" />
                            <rect x="13.5" y="4.5" width="7" height="15" rx="2" />
                            <path d="M10.5 12h3" />
                        </svg>
                    </span>
                    <span>Total Alat</span>
                    <strong>{{ $stats['total'] }}</strong>
                    <small>Termasuk alat yang belum didaftarkan</small>
                </article>
            </section>

            <section class="panel" data-live="devices-pending">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Belum didaftarkan</p>
                        <h3>Alat Baru Terhubung</h3>
                    </div>
                    <span class="panel-total">{{ $pendingDevices->count() }} alat</span>
                </div>
                <p class="panel-hint">Alat masuk ke daftar ini sendiri begitu alat sidik jari mengirim data ke server,
                    tetapi belum boleh dipakai: permintaan presensi dan pendaftaran sidik jari ditolak dengan pesan
                    <strong>alat belum didaftarkan</strong> sampai Bapak/Ibu mendaftarkannya di sini. Saat siswa
                    menempelkan jari, layar alat menampilkan pesan <strong>Belum terdaftar</strong>. Isi nama dan lokasi
                    supaya alat mudah dikenali, lalu tekan <strong>Daftarkan</strong>.
                </p>
                <div class="device-list">
                    @forelse ($pendingDevices as $device)
                        <article class="device-item">
                            <div class="device-heading">
                                <span>
                                    <strong>{{ $device->label() }}</strong>
                                    <small>{{ $device->device_id }}</small>
                                    <small>{{ $device->last_ping ? 'Terakhir mengirim data ' . $device->last_ping->diffForHumans() : 'Belum pernah mengirim data' }}</small>
                                </span>
                                <em class="status-{{ $device->status }}">{{ $device->statusLabel() }}</em>
                            </div>
                            <div class="device-body">
                                <form method="POST" action="{{ route('devices.link', $device) }}" class="device-form">
                                    @csrf
                                    @method('PUT')
                                    <label>Nama alat
                                        <input name="name" value="{{ $device->name }}" maxlength="100"
                                            placeholder="Contoh: Sensor Gerbang">
                                    </label>
                                    <label>Lokasi
                                        <input name="location" value="{{ $device->location }}" maxlength="100"
                                            placeholder="Contoh: Gerbang depan">
                                    </label>
                                    <label>Kode keamanan alat (opsional)
                                        <input name="device_token" minlength="12"
                                            placeholder="Opsional, minimal 12 karakter">
                                    </label>
                                    <button type="submit">Daftarkan</button>
                                </form>
                                <div class="device-actions">
                                    <form method="POST" action="{{ route('devices.destroy', $device) }}"
                                        onsubmit="return window.confirm('Hapus alat {{ $device->label() }}? Sesi pendaftaran sidik jarinya ikut dibatalkan, sedangkan riwayat presensinya tetap tersimpan.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button-danger">Hapus</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @empty
                        <p class="empty">Tidak ada alat yang menunggu didaftarkan. Semua alat yang mengirim data
                            sudah terdaftar.</p>
                    @endforelse
                </div>
                <p class="panel-footnote">Kode keamanan membuat alat hanya diterima kalau mengirim kode yang
                    sama. Isi kalau alat sudah diatur dengan kode itu, atau biarkan kosong supaya alat tetap bisa
                    mengirim data seperti sekarang.</p>
            </section>

            <section class="panel" data-live="devices-registered">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Terdaftar</p>
                        <h3>Alat Terdaftar</h3>
                    </div>
                    <span class="panel-total">{{ $registeredDevices->count() }} alat</span>
                </div>
                <p class="panel-hint">Buka satu baris untuk mengubah nama dan lokasi, mengatur layanan, mematikan alat,
                    membatalkan pendaftaran, atau menghapusnya. Perangkat yang dimatikan tetap terdaftar dan tetap
                    muncul di halaman <a href="{{ route('attendance.index') }}">Presensi</a>, tetapi semua permintaan
                    presensinya ditolak. Selama menganggur layar alat tetap mengajak menempelkan jari, dan begitu jari
                    menempel layar alat menampilkan pesan <strong>Sensor dimatikan</strong>.</p>
                <div class="device-list">
                    @forelse ($registeredDevices as $device)
                        <details class="device-item">
                            <summary>
                                <span>
                                    <strong>{{ $device->label() }}</strong>
                                    <small>{{ $device->device_id }} &middot;
                                        {{ $device->location ?: 'Belum ada lokasi' }}</small>
                                    <small>Layanan: {{ $device->serviceSummary() }}</small>
                                    <small>{{ $device->last_ping ? 'Terakhir mengirim data ' . $device->last_ping->diffForHumans() : 'Belum pernah mengirim data' }}</small>
                                </span>
                                <em class="status-{{ $device->status }}">{{ $device->statusLabel() }}</em>
                            </summary>
                            <div class="device-body">
                                <form method="POST" action="{{ route('devices.update', $device) }}"
                                    class="device-form">
                                    @csrf
                                    @method('PUT')
                                    <label>Nama alat
                                        <input name="name" value="{{ $device->name }}" required maxlength="100">
                                    </label>
                                    <label>Lokasi
                                        <input name="location" value="{{ $device->location }}" maxlength="100"
                                            placeholder="Contoh: Gerbang depan">
                                    </label>
                                    <label>Kode keamanan alat (opsional)
                                        <input name="device_token" minlength="12"
                                            placeholder="{{ $device->token_hash ? 'Sudah diisi, biarkan kosong kalau tidak diganti' : 'Opsional, minimal 12 karakter' }}">
                                    </label>
                                    <button type="submit">Simpan data</button>
                                </form>

                                <details class="service-picker">
                                    <summary>Atur layanan</summary>
                                    <form method="POST" action="{{ route('devices.services', $device) }}">
                                        @csrf
                                        @method('PUT')
                                        <p class="service-picker-title">Alat ini melayani:</p>
                                        <label class="service-option">
                                            <input type="checkbox" name="services[]" value="gate"
                                                @checked($device->isGate())>
                                            <span>
                                                <strong>Sensor pulang masuk</strong>
                                                <small>Absen masuk dan pulang di gerbang, mengikuti jam presensi di
                                                    halaman Presensi Gerbang.</small>
                                            </span>
                                        </label>
                                        @foreach ($classes as $schoolClass)
                                            <label class="service-option">
                                                <input type="checkbox" name="services[]"
                                                    value="{{ $schoolClass->id }}" @checked($device->servesClass($schoolClass->name))>
                                                <span>
                                                    <strong>{{ $schoolClass->name }}</strong>
                                                    <small>Anggota kelas ini, dan guru yang sedang mengajar di kelas
                                                        ini.</small>
                                                </span>
                                            </label>
                                        @endforeach
                                        <button type="submit" class="button-primary">Terapkan</button>
                                    </form>
                                </details>

                                <p class="device-note">{{ $device->attendanceLogs_count }} presensi dan
                                    {{ $device->enrollmentSessions_count }} sesi pendaftaran sidik jari tercatat dari
                                    alat ini.</p>

                                <div class="device-actions">
                                    @if ($device->isUsable())
                                        <form method="POST" action="{{ route('devices.status', $device) }}"
                                            onsubmit="return window.confirm('Matikan alat {{ $device->label() }}? Alat ini menolak semua permintaan presensi dan menampilkan pesan Sensor dimatikan di layarnya sampai dinyalakan lagi.')">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="status" value="inactive">
                                            <button type="submit" class="button-ghost">Matikan alat</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('devices.status', $device) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="button-primary">Nyalakan alat</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('devices.unlink', $device) }}"
                                        onsubmit="return window.confirm('Batalkan pendaftaran {{ $device->label() }}? Sesi pendaftaran sidik jari yang belum selesai ikut dibatalkan dan alat tidak bisa dipakai sampai didaftarkan lagi.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button-ghost">Batalkan pendaftaran</button>
                                    </form>
                                    <form method="POST" action="{{ route('devices.destroy', $device) }}"
                                        onsubmit="return window.confirm('Hapus alat {{ $device->label() }}? Sesi pendaftaran sidik jarinya ikut dibatalkan, sedangkan riwayat presensinya tetap tersimpan.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button-danger">Hapus alat</button>
                                    </form>
                                </div>
                            </div>
                        </details>
                    @empty
                        <p class="empty">Belum ada alat yang terdaftar. Nyalakan alat sidik jari supaya terbaca
                            server, lalu daftarkan dari bagian di atas.</p>
                    @endforelse
                </div>
                <p class="panel-footnote">Perubahan nama, lokasi, status, dan layanan langsung dipakai halaman
                    Presensi,
                    Sidik Jari, dan Dashboard. Halaman ini juga yang menjadi tempat pertama alat baru didaftarkan
                    sebelum dipakai.</p>
            </section>
        </main>

        <footer>Menghapus alat hanya melepas alat dari sistem, riwayat presensinya tetap tersimpan.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
