<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Presensi Gerbang | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">Pengaturan presensi</p>
            <h1>Presensi Gerbang</h1>
            <p>Jam absen masuk, batas terlambat, dan jam pulang untuk sensor di gerbang sekolah. Kehadiran di jam
                pelajaran diatur lewat <a href="{{ route('sessions.index') }}">Sesi Absen</a>.</p>
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
            <section class="stats" aria-label="Ringkasan presensi hari ini" data-live="attendance-stats">
                <article class="stat-card green">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <circle cx="9.5" cy="8.5" r="3.5" />
                            <path d="M3.5 20.5c0-3.3 2.7-6 6-6s6 2.7 6 6" />
                            <path d="m16.4 8.8 2 2 3.6-3.6" />
                        </svg>
                    </span>
                    <span>Hadir Hari Ini</span>
                    <strong>{{ $stats['hadir'] }}</strong>
                    <small>Absen masuk di dalam jam hadir</small>
                </article>

                <article class="stat-card orange">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="8.5" />
                            <path d="M12 7.4V12l3.1 2.1" />
                        </svg>
                    </span>
                    <span>Terlambat</span>
                    <strong>{{ $stats['terlambat'] }}</strong>
                    <small>Masuk setelah batas jam hadir</small>
                </article>

                <article class="stat-card blue">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M14.5 3.5h3A2.5 2.5 0 0 1 20 6v12a2.5 2.5 0 0 1-2.5 2.5h-3" />
                            <path d="M4 12h10.5" />
                            <path d="m10.5 8 4 4-4 4" />
                        </svg>
                    </span>
                    <span>Sudah Pulang</span>
                    <strong>{{ $stats['pulang'] }}</strong>
                    <small>{{ $stats['belumPulang'] }} anggota belum absen pulang</small>
                </article>

                <article class="stat-card neutral">
                    <span class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <circle cx="9.5" cy="8.5" r="3.5" />
                            <path d="M3.5 20.5c0-3.3 2.7-6 6-6s6 2.7 6 6" />
                            <path d="m16.6 14.8 5 5M21.6 14.8l-5 5" />
                        </svg>
                    </span>
                    <span>Belum Absen</span>
                    <strong>{{ $stats['belumHadir'] }}</strong>
                    <small>Dari {{ $stats['users'] }} anggota aktif</small>
                </article>
            </section>

            <div class="attendance-stack">
                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Jam absen</p>
                            <h3>Jam Presensi</h3>
                        </div>
                        <span class="panel-total">{{ $windows->count() }} rentang jam</span>
                    </div>
                    <p class="panel-hint">Jam presensi ditentukan dari halaman ini. Perangkat sidik jari hanya
                        mengirim hasil scan, lalu server memutuskan jamnya masuk, terlambat, atau pulang. Scan di
                        luar semua rentang jam di bawah akan ditolak dan perangkat menerima pesan
                        <strong>di luar jam absen</strong>. Batas mulai termasuk, batas selesai tidak termasuk, jadi
                        07:30 hanya masuk ke satu rentang.
                    </p>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Jenis dan jam</th>
                                    <th>Keterangan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($windows as $window)
                                    <tr>
                                        <td data-label="Jenis dan jam">
                                            <form method="POST"
                                                action="{{ route('attendance.windows.update', $window) }}"
                                                class="window-form">
                                                @csrf
                                                @method('PUT')
                                                <select name="kind" aria-label="Jenis absen">
                                                    @foreach ($kindOptions as $value => $label)
                                                        <option value="{{ $value }}"
                                                            @selected($window->kind === $value)>
                                                            {{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="time" name="starts_at" value="{{ $window->starts_at }}"
                                                    aria-label="Jam mulai" required>
                                                <span class="window-dash">sampai</span>
                                                <input type="time" name="ends_at" value="{{ $window->ends_at }}"
                                                    aria-label="Jam selesai" required>
                                                <button type="submit">Simpan</button>
                                            </form>
                                        </td>
                                        <td data-label="Keterangan">
                                            <span
                                                class="chip {{ $window->chipClass() }}">{{ $window->label() }}</span>
                                            <small class="table-note">absen {{ $window->typeLabel() }}</small>
                                        </td>
                                        <td data-label="Aksi">
                                            <div class="table-actions">
                                                <form method="POST"
                                                    action="{{ route('attendance.windows.destroy', $window) }}"
                                                    onsubmit="return window.confirm('Hapus jam {{ $window->label() }} {{ $window->range() }}? Scan pada jam itu akan ditolak.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="button-danger">Hapus</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="empty">Belum ada jam presensi. Tanpa jam presensi,
                                            presensi memakai batas lama, yaitu terlambat mulai pukul
                                            {{ sprintf('%02d:00', $fallbackLateHour) }}.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <form method="POST" action="{{ route('attendance.windows.store') }}" class="form-grid class-form">
                        @csrf
                        <label>Jenis absen
                            <select name="kind" required>
                                @foreach ($kindOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('kind') === $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label>Jam mulai
                            <input type="time" name="starts_at" value="{{ old('starts_at', '06:00') }}" required>
                        </label>
                        <label>Jam selesai
                            <input type="time" name="ends_at" value="{{ old('ends_at', '07:30') }}" required>
                        </label>
                        <div class="form-help">Contoh: hadir 06:00 - 07:30, terlambat 07:30 - 09:00, pulang 15:00 -
                            16:00. Rentang jam tidak boleh saling bertabrakan.</div>
                        <button type="submit">Tambah Jam</button>
                    </form>
                    <p class="panel-footnote">Jam mengikuti zona waktu {{ config('app.timezone') }} dan berlaku untuk
                        semua perangkat yang sudah didaftarkan. Anggota yang belum tercatat masuk pada hari ini tetap
                        bisa
                        absen pulang, tetapi tidak sebaliknya.</p>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Sensor presensi</p>
                            <h3>Perangkat Presensi</h3>
                        </div>
                        <span class="panel-total">{{ $devices->count() }} perangkat</span>
                    </div>
                    <p class="panel-hint">Ringkasan alat yang tersimpan beserta layanannya. Perangkat yang baru terbaca
                        otomatis masuk dengan status <strong>belum didaftarkan</strong> dan belum boleh dipakai sampai
                        didaftarkan. Pendaftaran alat baru, ganti nama dan token, pengaturan layanan, mematikan alat,
                        sampai menghapus perangkat dilakukan di halaman
                        <a href="{{ route('devices.index') }}">Alat Sensor</a> yang menjadi pusat kontrol sensor.
                    </p>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Perangkat</th>
                                    <th>Status</th>
                                    <th>Kelas yang dilayani</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($devices as $device)
                                    <tr class="{{ $device->isPaired() ? '' : 'row-untied' }}">
                                        <td data-label="Perangkat">
                                            <strong>{{ $device->label() }}</strong>
                                            <small class="table-note">{{ $device->device_id }} &middot;
                                                {{ $device->location ?: 'Belum ada lokasi' }}</small>
                                        </td>
                                        <td data-label="Status">
                                            <em
                                                class="status-{{ $device->status }}">{{ $device->statusLabel() }}</em>
                                        </td>
                                        <td data-label="Kelas yang dilayani">
                                            <div class="service-list">
                                                @if ($device->isGate())
                                                    <span class="chip chip-info">Pulang masuk</span>
                                                @endif
                                                @foreach ($device->schoolClasses as $schoolClass)
                                                    <span class="chip">{{ $schoolClass->name }}</span>
                                                @endforeach
                                                @if (!$device->isGate() && $device->schoolClasses->isEmpty())
                                                    <span class="table-note">Belum dipilih, alat tidak bisa
                                                        dipakai.</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td data-label="Aksi">
                                            <div class="table-actions">
                                                <a class="button-ghost"
                                                    href="{{ route('devices.index') }}">Kelola</a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="empty">Belum ada perangkat yang tersimpan. Nyalakan
                                            alat sidik jari agar perangkat terdaftar otomatis dari API, lalu daftarkan
                                            di halaman Alat Sensor.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="panel-footnote">Daftar ini terisi otomatis saat alat sidik jari mengirim data. Layanan
                        pulang masuk dan kelas yang tampil di kolom layanan diatur dari halaman
                        <a href="{{ route('devices.index') }}">Alat Sensor</a>.
                        @if ($unmappedDevices > 0)
                            <span>{{ $unmappedDevices }} perangkat menunggu didaftarkan.</span>
                        @endif
                    </p>
                </section>
            </div>

            <section class="panel" data-live="attendance-log">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Aktivitas sensor gerbang</p>
                        <h3>Presensi Hari Ini</h3>
                    </div>
                    <span class="panel-total">{{ $stats['scans'] }} scan tercatat</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>NIS</th>
                                <th>Kelas / Mapel</th>
                                <th>Jenis Absen</th>
                                <th>Waktu</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($logs as $log)
                                <tr>
                                    <td data-label="Nama">
                                        <strong>{{ $log->user->name }}</strong><small>{{ $log->user->roleLabel() }}</small>
                                    </td>
                                    <td data-label="NIS">{{ $log->user->identifier_number ?: 'Belum diisi' }}</td>
                                    <td data-label="{{ $log->user->studyFieldLabel() }}">
                                        {{ $log->user->studyFieldValue() ?: 'Belum diisi' }}</td>
                                    <td data-label="Jenis Absen">{{ $log->typeLabel() }}</td>
                                    <td data-label="Waktu">{{ $log->scanned_at->format('H:i') }}</td>
                                    <td data-label="Keterangan">
                                        <span
                                            class="chip {{ $log->chipClass() }}">{{ $log->historyStatusLabel() }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="empty">Belum ada presensi yang tercatat hari ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="panel-footnote">Hanya sensor pulang masuk yang tercatat di tabel ini. Scan berulang pada
                    rentang jam yang sama tidak menambah baris baru, melainkan dijawab <strong>Anda sudah
                        absen</strong> lewat layar perangkat.</p>
            </section>

            <section class="panel" data-live="class-log">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Aktivitas sensor kelas</p>
                        <h3>Scan Kelas Hari Ini</h3>
                    </div>
                    <span class="panel-total">{{ $stats['classScans'] }} scan &middot;
                        {{ $stats['classRecorded'] }} orang</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>NIS</th>
                                <th>Kelas / Mapel</th>
                                <th>Jam pelajaran</th>
                                <th>Perangkat</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($classLogs as $log)
                                <tr>
                                    <td data-label="Nama">
                                        <strong>{{ $log->user->name }}</strong><small>{{ $log->user->roleLabel() }}</small>
                                    </td>
                                    <td data-label="NIS">{{ $log->user->identifier_number ?: 'Belum diisi' }}</td>
                                    <td data-label="{{ $log->user->studyFieldLabel() }}">
                                        {{ $log->user->studyFieldValue() ?: 'Belum diisi' }}</td>
                                    <td data-label="Jam pelajaran">
                                        @if ($log->lessonSession)
                                            {{ $log->lessonSession->label() }}
                                            <small
                                                class="table-note">{{ $log->lessonSession->schoolClassName() ?? 'Kelas terhapus' }}</small>
                                        @else
                                            <span class="table-note">Tanpa sesi jam pelajaran</span>
                                        @endif
                                    </td>
                                    <td data-label="Perangkat">
                                        <span class="chip">{{ $log->device_id }}</span>
                                    </td>
                                    <td data-label="Waktu">{{ $log->scanned_at->format('H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="empty">Belum ada scan sensor kelas hari ini. Tentukan
                                        kelas yang dilayani tiap alat di halaman
                                        <a href="{{ route('devices.index') }}">Alat Sensor</a> supaya sensor di dalam
                                        kelas ikut mencatat kehadiran.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="panel-footnote">Sensor kelas mencatat hanya selama guru membuka absen jam pelajaran
                    kelasnya. Scan yang masuk di luar itu dijawab <strong>belum waktunya</strong> atau
                    <strong>di luar jadwal</strong> lewat layar perangkat. Scan ini tidak memakai jam presensi gerbang
                    dan tidak dihitung sebagai hadir harian di Dashboard. Guru pengajar yang ikut scan tercatat di sini
                    sebagai bukti kehadirannya di kelas, dan hasilnya bisa dilihat satu per satu di halaman
                    <a href="{{ route('sessions.index') }}">Sesi Absen</a>.
                </p>
            </section>
        </main>

        <footer>Jam presensi yang diatur di halaman ini dan layanan perangkat yang diatur di halaman
            <a href="{{ route('devices.index') }}">Alat Sensor</a> dipakai bersama oleh halaman Sidik Jari dan
            Dashboard.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
