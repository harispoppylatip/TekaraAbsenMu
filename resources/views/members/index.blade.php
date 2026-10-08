<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anggota | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')
        <header class="page-heading">
            <p class="eyebrow">Manajemen anggota</p>
            <h1>Anggota</h1>
            <p>Kelola data anggota dan sidik jari. Perintah pendaftaran hanya dikirim ke perangkat yang sudah
                didaftarkan
                dan aktif.</p>
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

        @if (session('importSkipped'))
            <section class="panel import-skipped" aria-label="Baris yang dilewati">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Perlu diperbaiki</p>
                        <h3>Baris yang Dilewati</h3>
                    </div>
                    <span class="panel-total">{{ count(session('importSkipped')) }} baris</span>
                </div>
                <p class="panel-hint">Perbaiki baris ini di Excel, lalu unggah lagi. Cukup sisakan baris yang
                    diperbaiki saja, karena baris lain sudah tersimpan.</p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Baris</th>
                                <th>Nama</th>
                                <th>Alasan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (session('importSkipped') as $skip)
                                <tr>
                                    <td data-label="Baris">{{ $skip['line'] }}</td>
                                    <td data-label="Nama">{{ $skip['name'] }}</td>
                                    <td data-label="Alasan">{{ $skip['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <main class="member-layout">
            <section class="panel" id="form-tambah-anggota">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">{{ $editUser ? 'Ubah data anggota' : 'Anggota baru' }}</p>
                        <h3>{{ $editUser ? $editUser->name : 'Tambah anggota' }}</h3>
                    </div>
                    @if ($editUser)
                        <a class="button-ghost" href="{{ route('members.index', array_filter($filters)) }}">Batal
                            mengubah</a>
                    @endif
                </div>
                <form method="POST"
                    action="{{ $editUser ? route('members.update', $editUser) : route('members.store') }}"
                    enctype="multipart/form-data" class="form-grid member-form" data-study-form>
                    @csrf
                    @if ($editUser)
                        @method('PUT')
                    @endif
                    <label>Nama lengkap
                        <input name="name" value="{{ old('name', $editUser?->name) }}" required>
                    </label>
                    <label>Nomor induk (NIS / NIP)
                        <input name="identifier_number"
                            value="{{ old('identifier_number', $editUser?->identifier_number) }}" required>
                    </label>
                    <label>Email
                        <input name="email" type="email" value="{{ old('email', $editUser?->email) }}" required>
                    </label>
                    <label>Peran
                        <select name="role" required>
                            <option value="student" @selected(old('role', $editUser?->role) === 'student')>Siswa</option>
                            <option value="teacher" @selected(old('role', $editUser?->role) === 'teacher')>Guru</option>
                            <option value="admin" @selected(old('role', $editUser?->role) === 'admin')>Admin</option>
                        </select>
                    </label>
                    @include('partials.class-options', [
                        'classValue' => $editUser?->class_name,
                        'subjectValue' => $editUser?->subject,
                        'role' => old('role', $editUser?->role ?? App\Models\User::RoleStudent),
                    ])
                    <div class="form-help">Kolom ini mengikuti peran: siswa diisi kelas, guru diisi mata
                        pelajaran. Kelas yang belum terdaftar otomatis ditambahkan ke halaman <a
                            href="{{ route('classes.index') }}">Kelas</a>, sedangkan mata pelajaran disimpan sebagai
                        teks dan tidak ikut terdaftar sebagai kelas. Biarkan kosong kalau belum ditentukan.</div>
                    @unless ($editUser)
                        <label>Foto wajah (opsional)
                            <input type="file" name="photo" accept="image/*">
                        </label>
                        <div class="form-help">Foto ini langsung dipakai untuk absen wajah, jadi pilih foto yang
                            memuat tepat satu wajah dan paling besar 5 MB. Untuk beberapa sudut wajah atau banyak
                            anggota sekaligus, pakai halaman <a href="{{ route('face.index') }}">Data Wajah</a>.</div>
                    @else
                        <div class="form-help">Foto wajah anggota ini diatur dari halaman <a
                                href="{{ route('face.index') }}">Data Wajah</a>, satu foto satu sudut wajah.</div>
                    @endunless
                    <button type="submit">{{ $editUser ? 'Simpan perubahan' : 'Simpan anggota' }}</button>
                </form>
            </section>

            @unless ($editUser)
                <section class="panel member-import" id="impor-anggota">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Banyak sekaligus</p>
                            <h3>Tambah dari File CSV</h3>
                        </div>
                    </div>
                    <ol class="import-steps">
                        <li>
                            <strong>Unduh file contoh</strong>
                            <span>Berisi judul kolom: Nama, NIS, Email, Peran, Kelas, Mapel.</span>
                            <a class="button-ghost" href="{{ route('members.import.template') }}">Unduh file contoh</a>
                        </li>
                        <li>
                            <strong>Isi di Excel</strong>
                            <span>Satu anggota satu baris. Peran diisi siswa, guru, atau admin (kosong berarti siswa).
                                Siswa isi Kelas, guru isi Mapel. Simpan sebagai CSV.</span>
                        </li>
                        <li>
                            <strong>Unggah di sini</strong>
                            <form method="POST" action="{{ route('members.import') }}" enctype="multipart/form-data"
                                class="import-form">
                                @csrf
                                <label>File CSV
                                    <input type="file" name="file" accept=".csv,text/csv" required>
                                </label>
                                <button type="submit">Unggah dan tambahkan</button>
                            </form>
                        </li>
                    </ol>
                    <p class="panel-footnote">Kata sandi bawaan siswa adalah NIS masing-masing, guru dan admin
                        {{ \App\Models\User::DefaultPassword }}, dan semuanya wajib diganti saat pertama masuk. Kelas yang
                        belum ada otomatis ditambahkan. Kalau NIS berubah jadi angka seperti 2,02E+10 di Excel, ubah format
                        kolom NIS menjadi Teks. Paling banyak {{ \App\Services\MemberImportService::MaxRows }} anggota
                        per unggahan.</p>
                </section>
            @endunless

            <section class="panel member-filter" id="cari-anggota" aria-label="Cari anggota">
                @php
                    $roleTabs = ['' => 'Semua'] + \App\Models\User::RoleLabels;
                    $roleOrder = [
                        '',
                        \App\Models\User::RoleStudent,
                        \App\Models\User::RoleTeacher,
                        \App\Models\User::RoleAdmin,
                    ];
                @endphp
                <div class="role-tabs" role="group" aria-label="Tampilkan menurut peran">
                    @foreach ($roleOrder as $roleKey)
                        @php
                            $roleTotal = $roleKey === '' ? $roleCounts->sum() : (int) $roleCounts->get($roleKey, 0);
                            $isCurrentRole = $filters['role'] === $roleKey;
                        @endphp
                        <a class="role-tab {{ $isCurrentRole ? 'is-current' : '' }}"
                            href="{{ route('members.index', array_filter(['role' => $roleKey] + $filters)) }}#cari-anggota"{!! $isCurrentRole ? ' aria-current="true"' : '' !!}>
                            {{ $roleTabs[$roleKey] }} <span class="role-tab-count">{{ $roleTotal }}</span>
                        </a>
                    @endforeach
                </div>

                <form method="GET" action="{{ route('members.index') }}#cari-anggota" class="member-search">
                    @if ($filters['role'] !== '')
                        <input type="hidden" name="role" value="{{ $filters['role'] }}">
                    @endif
                    <label class="member-search-query">Cari nama, NIS, atau email
                        <input type="search" name="q" value="{{ $filters['q'] }}"
                            placeholder="Ketik lalu tekan Enter" autocomplete="off">
                    </label>
                    <label>Kelas
                        <select name="class_name" data-auto-submit>
                            <option value="">Semua kelas</option>
                            @foreach ($classes as $className)
                                <option value="{{ $className }}" @selected(mb_strtolower($filters['class_name']) === mb_strtolower($className))>
                                    {{ $className }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Sidik jari
                        <select name="fingerprint" data-auto-submit>
                            <option value="">Semua</option>
                            <option value="registered" @selected($filters['fingerprint'] === 'registered')>Sudah punya sidik jari</option>
                            <option value="pending" @selected($filters['fingerprint'] === 'pending')>Belum punya sidik jari</option>
                        </select>
                    </label>
                    <button type="submit">Cari</button>
                </form>

                <div class="member-filter-summary">
                    @if ($hasFilters)
                        <span>Menampilkan <strong>{{ $users->count() }}</strong> dari {{ $totalMembers }}
                            anggota</span>
                        <a class="button-ghost" href="{{ route('members.index') }}#cari-anggota">Hapus filter</a>
                    @else
                        <span>Menampilkan semua <strong>{{ $totalMembers }}</strong> anggota</span>
                    @endif
                </div>
            </section>

            <section class="panel registered-panel" data-live="members-users">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Database</p>
                        <h3>Anggota terdaftar</h3>
                    </div>
                    <span class="panel-total">{{ $users->count() }} anggota</span>
                </div>
                @if ($pairedDevices->isEmpty())
                    <p class="panel-hint">Belum ada perangkat yang didaftarkan. Daftarkan perangkat di halaman
                        <a href="{{ route('devices.index') }}">Alat Sensor</a> sebelum mendaftarkan sidik jari.
                    </p>
                @else
                    <p class="panel-hint">Perintah pendaftaran dikirim ke perangkat yang sudah didaftarkan:
                        {{ $pairedDevices->map(fn($device) => $device->label())->implode(', ') }}.</p>
                @endif
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>NIS</th>
                                <th>Kelas / Mapel</th>
                                <th>Peran</th>
                                <th>Sidik jari</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($users as $user)
                                <tr>
                                    <td data-label="Nama"><strong>{{ $user->name }}</strong></td>
                                    <td data-label="NIS">{{ $user->identifier_number }}</td>
                                    <td data-label="{{ $user->studyFieldLabel() }}">
                                        {{ $user->studyFieldValue() ?: 'Belum diisi' }}</td>
                                    <td data-label="Peran">{{ $user->roleLabel() }}</td>
                                    <td data-label="Sidik jari">
                                        @if ($user->fingerprints_count > 0)
                                            <span class="chip chip-success">Sudah terdaftar</span>
                                        @else
                                            <span class="chip chip-warning">Belum terdaftar</span>
                                        @endif
                                    </td>
                                    <td data-label="Aksi">
                                        <div class="table-actions">
                                            <a class="button-ghost"
                                                href="{{ route('members.index', array_filter($filters) + ['edit' => $user->getKey()]) }}">Ubah</a>
                                            @if ($user->fingerprints_count === 0)
                                                <form method="POST"
                                                    action="{{ route('members.fingerprint.enroll', $user) }}">
                                                    @csrf
                                                    <button type="submit">Scan sidik jari</button>
                                                </form>
                                            @else
                                                <form method="POST"
                                                    action="{{ route('members.fingerprint.replace', $user) }}"
                                                    onsubmit="return window.confirm('Template sidik jari lama akan dihapus lalu didaftarkan ulang. Lanjutkan?')">
                                                    @csrf
                                                    <button type="submit">Ganti sidik jari</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('members.destroy', $user) }}"
                                                onsubmit="return window.confirm('Hapus anggota ini beserta sidik jari dan riwayat presensinya? Tindakan ini tidak bisa dibatalkan.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="button-danger">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="empty">
                                        {{ $hasFilters ? 'Tidak ada anggota yang cocok. Coba kata lain atau tekan Hapus filter.' : 'Belum ada anggota terdaftar. Tambahkan lewat formulir di atas.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
        <footer>Ubah data anggota, kelas, atau perannya dari tombol pada tabel.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
