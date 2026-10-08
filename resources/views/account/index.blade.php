<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akun Saya | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">Pengaturan pribadi</p>
            <h1>Akun Saya</h1>
            <p>Perbarui identitas akun yang sedang dipakai di perangkat ini. Peran dan status akun dikelola admin,
                sedangkan kata sandi bisa Anda ganti sendiri kapan saja.</p>
        </header>

        @if (session('success'))
            <div class="notice success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="notice error">{{ $errors->first() }}</div>
        @endif

        <main class="attendance-layout">
            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Identitas</p>
                        <h3>{{ $user->name }}</h3>
                    </div>
                    <span
                        class="chip {{ $user->status === \App\Models\User::StatusActive ? 'chip-success' : 'chip-danger' }}">
                        {{ $user->status === \App\Models\User::StatusActive ? 'Akun aktif' : 'Akun tidak aktif' }}
                    </span>
                </div>
                <div class="account-grid">
                    <div class="account-item">
                        <span>Peran</span>
                        <strong>{{ $user->roleLabel() }}</strong>
                        <small>Menentukan menu yang muncul dan halaman yang boleh dibuka</small>
                    </div>
                    <div class="account-item">
                        <span>Email</span>
                        <strong>{{ $user->email }}</strong>
                        <small>Dipakai untuk masuk</small>
                    </div>
                    <div class="account-item">
                        <span>Nomor telepon</span>
                        <strong>{{ $user->phone_number ?? 'Belum diisi' }}</strong>
                        <small>Kontak pribadi akun</small>
                    </div>
                    <div class="account-item">
                        <span>{{ $user->role === \App\Models\User::RoleStudent ? 'Nomor induk' : 'Nomor pegawai' }}</span>
                        <strong>{{ $user->identifier_number ?? 'Belum diisi' }}</strong>
                        <small>Kata sandi awal akun siswa memakai nomor ini</small>
                    </div>
                    @if ($user->role === \App\Models\User::RoleStudent)
                        <div class="account-item">
                            <span>Kelas</span>
                            <strong>{{ $user->class_name ?? 'Belum masuk kelas' }}</strong>
                            <small>Jadwal dan absen mengikuti kelas ini</small>
                        </div>
                    @endif
                    @if ($user->role === \App\Models\User::RoleTeacher)
                        <div class="account-item">
                            <span>Mata pelajaran</span>
                            <strong>{{ $user->subject ?? 'Belum diisi' }}</strong>
                            <small>Mata pelajaran utama yang tersimpan di akun</small>
                        </div>
                    @endif
                    <div class="account-item">
                        <span>Kata sandi</span>
                        <strong>{{ $user->hasChangedPassword() ? 'Sudah diganti' : 'Masih kata sandi awal' }}</strong>
                        <small>
                            @if ($user->password_changed_at)
                                Terakhir diganti {{ $user->password_changed_at->translatedFormat('d F Y H:i') }}
                            @else
                                Belum pernah diganti sejak akun dibuat
                            @endif
                        </small>
                    </div>
                    <div class="account-item">
                        <span>Kelas yang dijadwalkan</span>
                        <strong>{{ $user->lessonSchedules()->count() }} jadwal</strong>
                        <small>
                            @if ($user->isAdmin())
                                Admin tidak terikat jadwal mengajar
                            @elseif ($user->isTeacher())
                                Jadwal pelajaran yang diampu di kelas yang berbeda
                            @else
                                Jadwal pelajaran kelas {{ $user->class_name ?? 'Anda' }}
                            @endif
                        </small>
                    </div>
                </div>
                <div class="table-actions account-actions">
                    <a class="button-primary" href="{{ route('password.edit') }}">Ubah Kata Sandi</a>
                    <a class="button-ghost" href="{{ route($user->homeRoute()) }}">Buka Halaman Utama</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="button-danger">Keluar</button>
                    </form>
                </div>
                <p class="panel-footnote">Keluar dari akun berguna kalau perangkat ini dipakai bergantian, misalnya
                    komputer guru di ruang piket. Setelah keluar, perangkat kembali ke halaman masuk.</p>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Edit data</p>
                        <h3>Ubah Identitas</h3>
                    </div>
                </div>
                <form method="POST" action="{{ route('account.update') }}" class="form-grid">
                    @csrf
                    @method('PUT')
                    <label>Nama lengkap
                        <input name="name" value="{{ old('name', $user->name) }}" maxlength="100" required>
                        @error('name')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>
                    <label>Email
                        <input type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="255"
                            required>
                        @error('email')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>
                    <label>{{ $user->role === \App\Models\User::RoleStudent ? 'Nomor induk' : 'Nomor pegawai' }}
                        <input name="identifier_number"
                            value="{{ old('identifier_number', $user->identifier_number) }}" maxlength="50" required>
                        @error('identifier_number')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>
                    <label>Nomor telepon
                        <input name="phone_number" value="{{ old('phone_number', $user->phone_number) }}"
                            maxlength="20">
                        @error('phone_number')
                            <small class="field-error">{{ $message }}</small>
                        @enderror
                    </label>
                    @if ($user->role === \App\Models\User::RoleStudent)
                        <label>Kelas
                            <input name="class_name" list="account-class-suggestions"
                                value="{{ old('class_name', $user->class_name) }}" maxlength="50"
                                placeholder="Ketik atau pilih kelas">
                            <datalist id="account-class-suggestions">
                                @foreach ($classes as $className)
                                    <option value="{{ $className }}"></option>
                                @endforeach
                            </datalist>
                            @error('class_name')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>
                    @elseif ($user->role === \App\Models\User::RoleTeacher)
                        <label>Mata pelajaran
                            <input name="subject" value="{{ old('subject', $user->subject) }}" maxlength="50"
                                placeholder="Ketik mata pelajaran">
                            @error('subject')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>
                    @endif
                    <div class="form-actions">
                        <button type="submit">Simpan perubahan</button>
                    </div>
                </form>
                <p class="panel-footnote">Peran, status, kata sandi, dan data sidik jari tidak berubah dari formulir
                    ini.</p>
            </section>
        </main>

        <footer>Perubahan kata sandi langsung berlaku untuk masuk berikutnya, termasuk dari perangkat lain.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
