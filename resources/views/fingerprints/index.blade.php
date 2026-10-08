@php
    $steps = [
        1 => 'Siapkan jari',
        2 => 'Letakkan jari',
        3 => 'Proses pembacaan',
        4 => 'Berhasil',
    ];

    $actionLabels = [
        'lookup' => 'Ambil data sidik jari',
        'match' => 'Cocokkan sidik jari',
        'register' => 'Daftarkan sidik jari',
        'scan' => 'Scan',
    ];

    $flowStep = match (true) {
        $readyEnrollments->isNotEmpty() => 4,
        $pendingEnrollments->contains(fn($session) => $session->status === 'waiting_tap_2') => 2,
        default => 1,
    };
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sidik Jari | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')
        <header class="page-heading">
            <p class="eyebrow">Alat sensor</p>
            <h1>Sidik Jari</h1>
            <p>Siapa saja yang sudah punya sidik jari dan siapa yang baru saja scan. Untuk mendaftarkan sidik jari,
                pakai tombol Scan sidik jari di halaman <a href="{{ route('members.index') }}">Anggota</a>.</p>
        </header>

        @if (session('success'))
            <div class="notice success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="notice error">{{ $errors->first() }}</div>
        @endif

        <main class="fingerprint-layout">
            <div class="live-slot" data-live="fingerprints-ready">
                @if ($readyEnrollments->isNotEmpty())
                    <section class="panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">Perlu tindakan</p>
                                <h3>Sidik Jari Baru dari Alat</h3>
                            </div><span class="panel-total">{{ $readyEnrollments->count() }} menunggu disimpan</span>
                        </div>
                        @foreach ($readyEnrollments as $session)
                            <div class="ready-session">
                                <div><strong>Sesi #{{ $session->id }}</strong><small>Alat {{ $session->device_id }} ·
                                        Dua kali tempel jari
                                        cocok</small></div>
                                <form method="POST" action="{{ route('enrollments.store') }}" class="form-grid"
                                    data-study-form>
                                    @csrf
                                    <input type="hidden" name="enrollment_session_id" value="{{ $session->id }}">
                                    <label>Nama lengkap<input name="name" required></label>
                                    <label>Email<input name="email" type="email" required></label>
                                    <label>NIS / Nomor Induk<input name="identifier_number"></label>
                                    <label>Peran<select name="role">
                                            <option value="student">Siswa</option>
                                            <option value="teacher">Guru</option>
                                            <option value="admin">Admin</option>
                                        </select></label>
                                    @include('partials.class-options', [
                                        'classListId' => 'class-suggestions-' . $session->id,
                                        'role' => old('role', App\Models\User::RoleStudent),
                                    ])
                                    <label>Posisi jari<select name="finger_position">
                                            <option value="Jelunjuk Kanan">Telunjuk Kanan</option>
                                            <option value="Jelunjuk Kiri">Telunjuk Kiri</option>
                                            <option>Jempol Kanan</option>
                                            <option>Jempol Kiri</option>
                                        </select></label>
                                    <button type="submit">Simpan anggota</button>
                                </form>
                                <form method="POST" action="{{ route('enrollments.destroy', $session) }}"
                                    class="delete-form" onsubmit="return confirm('Hapus sesi sidik jari ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="button-danger">Hapus sesi</button>
                                </form>
                            </div>
                        @endforeach
                    </section>
                @endif
            </div>

            <div class="live-slot" data-live="fingerprints-pending">
                @if ($pendingEnrollments->isNotEmpty())
                    <section class="panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">Proses di alat</p>
                                <h3>Menunggu Tempelan Jari</h3>
                            </div><span class="panel-total">{{ $pendingEnrollments->count() }} proses</span>
                        </div>

                        <div class="stepper">
                            @foreach ($steps as $number => $label)
                                @php
                                    $state = match (true) {
                                        $number < $flowStep => 'is-done',
                                        $number === $flowStep => 'is-active',
                                        default => 'is-waiting',
                                    };
                                @endphp
                                <div class="step {{ $state }}">
                                    <span class="step-number">Langkah {{ $number }}</span>
                                    <span class="step-label">{{ $label }}</span>
                                </div>
                            @endforeach
                        </div>

                        @foreach ($pendingEnrollments as $session)
                            <div class="pending-item"><strong>Sesi #{{ $session->id }}</strong><span>Alat
                                    {{ $session->device_id }}</span>
                                <span
                                    class="chip {{ $session->status === 'waiting_tap_1' ? 'chip-warning' : 'chip-info' }}">{{ $session->status === 'waiting_tap_1' ? 'Menunggu tempelan pertama' : 'Menunggu tempelan kedua' }}</span>
                                <form method="POST" action="{{ route('enrollments.destroy', $session) }}"
                                    class="delete-form" onsubmit="return confirm('Hapus sesi sidik jari ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="button-danger">Hapus</button>
                                </form>
                            </div>
                        @endforeach
                    </section>
                @endif
            </div>

            <section class="panel registered-panel" data-live="fingerprints-registered">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Sudah terdaftar</p>
                        <h3>Sidik Jari Terdaftar</h3>
                    </div><span class="panel-total">{{ $fingerprints->count() }} sidik jari</span>
                </div>
                @forelse ($fingerprints as $fingerprint)
                    <div class="registered-item">
                        <div>
                            <strong>{{ $fingerprint->user->name }}</strong>
                            <small>NIS {{ $fingerprint->user->identifier_number ?: 'belum diisi' }} ·
                                {{ \App\Models\Fingerprint::positionLabel($fingerprint->finger_position) }}</small>
                        </div>
                        <span>Didaftarkan {{ $fingerprint->created_at?->translatedFormat('d M Y') }}</span>
                    </div>
                @empty
                    <p class="empty">Belum ada sidik jari terdaftar. Daftarkan dari halaman Anggota.</p>
                @endforelse
            </section>

            <section class="panel scan-panel" data-live="fingerprints-scan-log">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Aktivitas alat</p>
                        <h3>Scan Terakhir</h3>
                    </div><span class="panel-total">{{ $scanLogs->count() }} terbaru</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>NIS</th>
                                <th>Alat</th>
                                <th>Waktu scan</th>
                                <th>Jenis</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($scanLogs as $log)
                                <tr>
                                    <td data-label="Nama"><strong>{{ $log->user->name }}</strong></td>
                                    <td data-label="NIS">{{ $log->user->identifier_number ?: 'Belum diisi' }}</td>
                                    <td data-label="Alat">{{ $log->device_id }}</td>
                                    <td data-label="Waktu scan">{{ $log->scanned_at->format('d M Y, H:i') }}</td>
                                    <td data-label="Jenis Absen">{{ $log->typeLabel() }}</td>
                                    <td data-label="Keterangan">
                                        <span
                                            class="chip {{ $log->chipClass() }}">{{ $log->historyStatusLabel() }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="empty">Belum ada yang scan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel scan-panel" data-live="fingerprints-api-log">
                <details class="technical-log">
                    <summary>
                        <span>
                            <strong>Catatan teknis alat</strong>
                            <small>Untuk teknisi yang memeriksa alat. {{ $apiLogs->count() }} catatan terbaru.</small>
                        </span>
                    </summary>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Kegiatan</th>
                                    <th>Alat / NIS</th>
                                    <th>Ukuran data</th>
                                    <th>Hasil</th>
                                    <th>Cocok dengan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($apiLogs as $log)
                                    <tr>
                                        <td data-label="Waktu">{{ $log->created_at->format('d M Y, H:i:s') }}</td>
                                        <td data-label="Kegiatan">
                                            {{ $actionLabels[$log->action] ?? ucfirst((string) $log->action) }}</td>
                                        <td data-label="Alat / NIS">
                                            {{ $log->device_id ?: 'Tanpa alat' }}
                                            <small
                                                class="table-note">{{ $log->user_identifier ?: 'Tanpa NIS' }}</small>
                                        </td>
                                        <td data-label="Ukuran data">
                                            {{ $log->template_length ? $log->template_length . ' karakter' : '-' }}
                                        </td>
                                        <td data-label="Hasil">{{ $log->result_status ?: '-' }}</td>
                                        <td data-label="Cocok dengan">{{ $log->matchedUser?->name ?: '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="empty">Belum ada kiriman data dari alat.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="panel-footnote">Data sidik jari mentah tidak pernah ditampilkan, hanya ukurannya.</p>
                </details>
            </section>
        </main>
        <footer>Data sidik jari disimpan privat dan hanya dipakai untuk mencatat kehadiran.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
