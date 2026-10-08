<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Pelajaran | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')

        <header class="page-heading">
            <p class="eyebrow">Pengaturan jadwal</p>
            <h1>Jadwal Pelajaran</h1>
            <p>Pelajaran apa, di kelas mana, hari dan jam ke berapa, serta guru yang mengajar. Guru dan siswa
                melihat jadwal ini di halamannya masing-masing.</p>
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
            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Kisi jadwal</p>
                        <h3>{{ $selectedClass?->name ?? 'Pilih kelas' }}</h3>
                    </div>
                    <span class="panel-total">{{ $hours->count() }} jam pelajaran &middot; Senin sampai
                        Jumat</span>
                </div>
                <form method="GET" action="{{ route('schedules.index') }}" class="filter-form">
                    <label>Kelas
                        <select name="class">
                            <option value="">Pilih kelas</option>
                            @foreach ($classes as $schoolClass)
                                <option value="{{ $schoolClass->getKey() }}" @selected($filters['class'] === $schoolClass->getKey())>
                                    {{ $schoolClass->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Hari
                        <select name="day">
                            <option value="">Semua hari</option>
                            @foreach ($days as $value)
                                <option value="{{ $value }}" @selected($filters['day'] === $value)>
                                    {{ $dayNames[$value] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Guru
                        <select name="teacher">
                            <option value="">Semua guru</option>
                            @foreach ($teachers as $teacher)
                                <option value="{{ $teacher->getKey() }}" @selected($filters['teacher'] === $teacher->getKey())>
                                    {{ $teacher->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button type="submit">Terapkan filter</button>
                        <a class="button-ghost" href="{{ route('schedules.index') }}">Reset filter</a>
                    </div>
                </form>

                @if ($selectedClass === null)
                    <p class="panel-hint">Pilih satu kelas pada filter di atas untuk melihat kisi jadwalnya satu
                        minggu penuh. Filter hari dan guru hanya menyaring daftar jadwal di bawah, bukan kisinya.</p>
                @else
                    <p class="panel-hint">Kisi jadwal kelas <strong>{{ $selectedClass->name }}</strong> dari jam
                        pelajaran pertama sampai terakhir. Sel yang masih kosong berarti jam itu belum diisi
                        pelajaran. Satu kelas tidak boleh punya dua pelajaran pada jam yang sama, dan satu guru juga
                        tidak boleh mengajar dua kelas pada jam yang sama.</p>
                    <div class="table-wrap">
                        <table class="schedule-grid">
                            <thead>
                                <tr>
                                    <th scope="col">Jam</th>
                                    @foreach ($days as $value)
                                        <th scope="col">{{ $dayNames[$value] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($hours as $hour)
                                    <tr>
                                        <th scope="row">
                                            <span class="schedule-hour">{{ $hour->label() }}</span>
                                            <small>{{ $hour->range() }}</small>
                                        </th>
                                        @foreach ($days as $value)
                                            @php($cell = $grid[$hour->getKey()][$value] ?? null)
                                            <td data-label="{{ $dayNames[$value] }}">
                                                @if ($cell === null)
                                                    <span class="schedule-empty">Kosong</span>
                                                @else
                                                    <span class="schedule-cell">
                                                        <span class="schedule-subject">{{ $cell->subject }}</span>
                                                        <span
                                                            class="schedule-teacher">{{ $cell->teacher?->name ?? 'Guru' }}</span>
                                                    </span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($days) + 1 }}" class="empty">Belum ada jam pelajaran.
                                            Isi dulu daftar jam di halaman <a
                                                href="{{ route('lesson-hours.index') }}">Jam Pelajaran</a>.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Tambah pelajaran</p>
                        <h3>Isi Jam Pelajaran</h3>
                    </div>
                </div>
                <p class="panel-hint">Pilih kelas, hari, dan jamnya, lalu tulis nama mata pelajarannya dan pilih guru
                    yang mengajar di kelas itu pada jam tersebut. Satu slot jam hanya bisa diisi satu pelajaran.</p>
                @if ($hours->isEmpty() || $classes->isEmpty() || $teachers->isEmpty())
                    <div class="empty-state">
                        <strong>Jadwal belum bisa diisi</strong>
                        <span>
                            @if ($hours->isEmpty())
                                Daftar <a href="{{ route('lesson-hours.index') }}">jam pelajaran</a> masih kosong,
                                jadi belum ada jam yang bisa dipilih.
                            @endif
                            @if ($classes->isEmpty())
                                Belum ada <a href="{{ route('classes.index') }}">kelas</a> yang terdaftar.
                            @endif
                            @if ($teachers->isEmpty())
                                Belum ada pengguna berperan guru, tambahkan dulu lewat halaman <a
                                    href="{{ route('members.index') }}">Anggota</a>.
                            @endif
                        </span>
                    </div>
                @else
                    <form method="POST" action="{{ route('schedules.store') }}" class="form-grid">
                        @csrf
                        <label>Kelas
                            <select name="school_class_id" required>
                                @foreach ($classes as $schoolClass)
                                    <option value="{{ $schoolClass->getKey() }}" @selected((int) old('school_class_id', $filters['class']) === (int) $schoolClass->getKey())>
                                        {{ $schoolClass->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Hari
                            <select name="day" required>
                                @foreach ($days as $value)
                                    <option value="{{ $value }}" @selected((int) old('day') === $value)>
                                        {{ $dayNames[$value] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Jam pelajaran
                            <select name="lesson_hour_id" required>
                                @foreach ($hours as $hour)
                                    <option value="{{ $hour->getKey() }}" @selected((int) old('lesson_hour_id') === (int) $hour->getKey())>
                                        {{ $hour->label() }} ({{ $hour->range() }})</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Mata pelajaran
                            <input name="subject" value="{{ old('subject') }}" maxlength="50"
                                placeholder="Contoh: Matematika" required>
                        </label>
                        <label>Guru pengampu
                            <select name="user_id" required>
                                @foreach ($teachers as $teacher)
                                    <option value="{{ $teacher->getKey() }}" @selected((int) old('user_id') === (int) $teacher->getKey())>
                                        {{ $teacher->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="form-help">Jadwal yang baru ditambahkan langsung muncul di kisi jadwal kelasnya dan
                            bisa dibuka absennya oleh guru pada jam itu.</div>
                        <button type="submit">Tambah Jadwal</button>
                    </form>
                @endif
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Daftar</p>
                        <h3>Jadwal Terpilih</h3>
                    </div>
                    <span class="panel-total">{{ $schedules->count() }} jadwal</span>
                </div>
                <p class="panel-hint">Daftar ini mengikuti filter di atas. Buka satu jadwal untuk mengubah pelajaran,
                    jam, atau gurunya. Menghapus jadwal ikut menghapus pertemuan yang pernah dibuka untuk jadwal itu,
                    tetapi catatan presensi siswa tetap tersimpan.
                </p>
                <div class="schedule-list">
                    @forelse ($schedules as $schedule)
                        <details class="schedule-item">
                            <summary>
                                <span class="schedule-item-main">
                                    <span class="schedule-item-title">{{ $schedule->subject }}</span>
                                    <span class="schedule-item-meta">
                                        {{ $schedule->dayLabel() }} &middot;
                                        {{ $schedule->lessonHour?->label() ?? 'jam terhapus' }}
                                        @if ($schedule->lessonHour)
                                            ({{ $schedule->lessonHour->range() }})
                                        @endif
                                        &middot; {{ $schedule->schoolClass?->name ?? 'kelas terhapus' }}
                                        &middot; {{ $schedule->teacher?->name ?? 'guru terhapus' }}
                                    </span>
                                </span>
                                <span class="schedule-item-count">{{ $schedule->sessions_count }} pertemuan &middot;
                                    Buka untuk mengubah</span>
                            </summary>

                            <form method="POST" action="{{ route('schedules.update', $schedule) }}" class="form-grid">
                                @csrf
                                @method('PUT')
                                <label>Kelas
                                    <select name="school_class_id" required>
                                        @foreach ($classes as $schoolClass)
                                            <option value="{{ $schoolClass->getKey() }}" @selected((int) $schedule->school_class_id === (int) $schoolClass->getKey())>
                                                {{ $schoolClass->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Hari
                                    <select name="day" required>
                                        @foreach ($days as $value)
                                            <option value="{{ $value }}" @selected((int) $schedule->day === $value)>
                                                {{ $dayNames[$value] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Jam pelajaran
                                    <select name="lesson_hour_id" required>
                                        @foreach ($hours as $hour)
                                            <option value="{{ $hour->getKey() }}" @selected((int) $schedule->lesson_hour_id === (int) $hour->getKey())>
                                                {{ $hour->label() }} ({{ $hour->range() }})</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Mata pelajaran
                                    <input name="subject" value="{{ $schedule->subject }}" maxlength="50" required>
                                </label>
                                <label>Guru pengampu
                                    <select name="user_id" required>
                                        @foreach ($teachers as $teacher)
                                            <option value="{{ $teacher->getKey() }}" @selected((int) $schedule->user_id === (int) $teacher->getKey())>
                                                {{ $teacher->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <button type="submit">Simpan Perubahan</button>
                            </form>

                            <form method="POST" action="{{ route('schedules.destroy', $schedule) }}"
                                class="delete-form"
                                onsubmit="return window.confirm('Hapus jadwal {{ $schedule->subject }} {{ $schedule->dayLabel() }} {{ $schedule->lessonHour?->label() }}? Pertemuan yang pernah dibuka ikut terhapus, catatan presensi siswa tetap tersimpan.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="button-danger">Hapus Jadwal</button>
                            </form>
                        </details>
                    @empty
                        <p class="empty">Tidak ada jadwal yang cocok dengan filter ini. Ubah filternya, atau tambahkan
                            jadwal baru lewat formulir di atas.</p>
                    @endforelse
                </div>
            </section>
        </main>

        <footer>Jadwal pelajaran dipakai halaman mengajar guru, halaman jadwal siswa, dan sensor kelas untuk
            mencatat kehadiran per jam.
            <span class="live-status" data-live-status></span>
        </footer>
    </div>
</body>

</html>
