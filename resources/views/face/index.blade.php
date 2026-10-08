<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Wajah | {{ config('app.name', 'Presensi Sekolah') }}</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="shell">
        @include('partials.navigation')
        <header class="page-heading">
            <p class="eyebrow">Alat sensor</p>
            <h1>Data Wajah</h1>
            <p>Wajah anggota yang sudah terdaftar untuk absen lewat kamera, dan guru yang ditugaskan memegang
                kamera. Foto wajah didaftarkan dari halaman ini, satu anggota sekaligus atau banyak anggota
                sekaligus dari folder foto.</p>
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

        @if (count($uploadReport) > 0)
            <section class="panel face-upload-report" aria-label="Hasil pendaftaran foto">
                <div class="panel-heading">
                    <div>
                        <p class="eyebrow">Hasil pendaftaran foto</p>
                        <h3>{{ $uploadReportName ?: 'Anggota' }}</h3>
                    </div>
                    <span class="panel-total">{{ count($uploadReport) }} foto diperiksa</span>
                </div>
                <p class="panel-hint">Hasil setiap foto dibaca dari atas ke bawah. Foto yang tersimpan langsung ikut
                    dipakai saat absen wajah, foto yang dilewati perlu diambil ulang.</p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Foto</th>
                                <th>Hasil</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($uploadReport as $row)
                                <tr>
                                    <td data-label="Foto">{{ $row['file'] }}</td>
                                    <td data-label="Hasil">{{ $row['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @unless ($serviceAvailable)
            <div class="notice warning">
                Layanan wajah di server belum aktif, jadi absen wajah belum bisa dipakai dan pendaftaran wajah
                belum bisa dijalankan. Nyalakan layanan wajah di komputer server, lalu muat ulang halaman ini.
            </div>
        @endunless

        <main class="face-layout">
            <section class="panel" data-live="face-stats">
                <div class="panel-heading">
                    <div>
                        <h3>Ringkasan</h3>
                    </div><span class="panel-total">{{ $registeredCount }} anggota terdaftar</span>
                </div>
                <div class="stats" aria-label="Ringkasan data wajah">
                    <div class="stat-card blue">
                        <span>Anggota dengan wajah</span>
                        <strong>{{ $registeredCount }}</strong>
                        <small>dari {{ $memberCount }} anggota</small>
                    </div>
                    <div class="stat-card green">
                        <span>Foto wajah tersimpan</span>
                        <strong>{{ $totalPhotos }}</strong>
                        <small>satu foto satu sudut wajah</small>
                    </div>
                    <div class="stat-card neutral">
                        <span>Petugas kamera</span>
                        <strong>{{ $teachers->where('can_scan_face', true)->count() }}</strong>
                        <small>guru yang boleh membuka kamera</small>
                    </div>
                </div>
            </section>

            <section class="panel" id="daftarkan-wajah">
                <div class="panel-heading">
                    <div>
                        <h3>Daftarkan Wajah dari Foto</h3>
                        <p class="panel-description">Pilih anggota, lalu kirim fotonya. Setiap foto mewakili satu sudut
                            wajah, jadi pakai foto tampak depan serta dua sudut serong. Satu foto hanya boleh memuat
                            satu
                            orang.</p>
                    </div>
                    <span class="panel-total">batas {{ $maxPhotosPerUser }} foto per anggota</span>
                </div>

                @if (!$serviceAvailable)
                    <p class="empty">Layanan wajah di server belum aktif, jadi foto belum bisa diproses.</p>
                @elseif ($members->isEmpty())
                    <p class="empty">Belum ada anggota yang bisa didaftarkan. Tambahkan anggotanya dulu di halaman
                        Anggota.</p>
                @else
                    <form method="POST" action="{{ route('face.photos.store') }}" enctype="multipart/form-data"
                        class="form-grid face-upload">
                        @csrf
                        <label>Anggota
                            <select name="user_id" required>
                                <option value="">Pilih anggota</option>
                                @foreach ($members as $member)
                                    <option value="{{ $member->id }}" @selected((string) old('user_id') === (string) $member->id)>
                                        {{ $member->name }}
                                        ({{ $member->identifier_number ?: 'nomor induk belum diisi' }})
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label>Foto wajah
                            <input type="file" name="photos[]" accept="image/*" multiple required>
                        </label>
                        <div class="form-help">Paling banyak {{ $maxPhotosPerSubmit }} foto sekali kirim, tiap foto
                            sampai 5 MB, dan prosesnya sampai setengah menit. Kirim bertahap sampai
                            ke-{{ $maxPhotosPerUser }}
                            foto boleh tersimpan. Hasil setiap foto ditampilkan di bagian atas halaman ini, dan foto
                            yang salah masih bisa dihapus satu per satu di daftar wajah terdaftar.</div>
                        <button type="submit">Simpan foto wajah</button>
                    </form>
                @endif
            </section>

            <section class="panel" id="impor-wajah">
                <div class="panel-heading">
                    <div>
                        <h3>Impor Massal dari Folder Foto</h3>
                        <p class="panel-description">Pilih satu folder yang di dalamnya berisi subfolder foto. Nama
                            setiap subfolder adalah nomor induk anggota, dan isinya foto wajah anggota itu. Foto
                            dikirim satu per satu supaya tiap hasilnya terlihat, jadi halaman ini boleh dibiarkan
                            terbuka sampai selesai. Satu foto diproses sekitar setengah menit.</p>
                    </div>
                    <span class="panel-total">batas {{ $maxPhotosPerUser }} foto per anggota</span>
                </div>

                @if (!$serviceAvailable)
                    <p class="empty">Layanan wajah di server belum aktif, jadi impor belum bisa dijalankan.</p>
                @else
                    <div class="import-form face-upload face-upload-bulk"
                        data-face-upload-url="{{ route('face.photos.upload') }}">
                        <label>Folder foto
                            <input type="file" accept="image/*" multiple webkitdirectory directory data-face-folder>
                        </label>
                        <p class="form-help">Susunan folder yang dikenali: <code>foto/25001/depan.jpg</code> dan
                            <code>foto/25002/serong.jpg</code>. Foto yang tidak berada di dalam subfolder bernama nomor
                            induk anggota akan dilewati dan alasannya dilaporkan di bawah.
                        </p>
                        <div class="face-upload-actions">
                            <button type="button" data-face-import-start>Mulai kirim</button>
                            <button type="button" class="button-ghost" data-face-import-stop hidden>Berhenti</button>
                        </div>
                    </div>
                    <div class="face-import-log" data-face-import-log hidden>
                        <p class="panel-hint" role="status" data-face-import-summary></p>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Foto yang dilewati</th>
                                        <th>Alasan</th>
                                    </tr>
                                </thead>
                                <tbody data-face-import-rows></tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </section>

            <section class="panel" data-live="face-operators">
                <div class="panel-heading">
                    <div>
                        <h3>Petugas Kamera Wajah</h3>
                        <p class="panel-description">Tugaskan guru yang memegang kamera absen wajah. Perannya
                            tetap guru, hanya menambah akses membuka kamera.</p>
                    </div>
                </div>
                @forelse ($teachers as $teacher)
                    <div class="face-operator">
                        <div>
                            <strong>{{ $teacher->name }}</strong>
                            <small>{{ $teacher->email }}@if (filled($teacher->subject))
                                    &middot; {{ $teacher->subject }}
                                @endif
                            </small>
                        </div>
                        <span
                            class="face-operator-state">{{ $teacher->canScanFace() ? 'Petugas kamera' : 'Bukan petugas' }}</span>
                        <form method="POST" action="{{ route('face.operators.toggle', $teacher) }}">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="button-ghost">
                                {{ $teacher->canScanFace() ? 'Lepas tugas' : 'Tugaskan' }}
                            </button>
                        </form>
                    </div>
                    @empty
                        <p class="empty">Belum ada guru terdaftar. Tambahkan guru di halaman Anggota.</p>
                    @endforelse
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3>Wajah Terdaftar</h3>
                            <p class="panel-description">Klik satu nama untuk membuka foto wajahnya, lalu hapus foto
                                yang perlu diganti. Cari menurut nama atau NIS bila anggotanya sudah banyak. Menghapus satu
                                foto hanya membuang sudut wajah itu saja, foto lain tetap dipakai.</p>
                        </div>
                        <span class="panel-total">{{ $registered->total() }} anggota</span>
                    </div>

                    <form method="GET" action="{{ route('face.index') }}" class="face-search">
                        <label>Cari nama atau NIS
                            <input type="search" name="q" value="{{ $search }}" placeholder="contoh: 25001">
                        </label>
                        <button type="submit">Cari</button>
                        @if ($search !== '')
                            <a class="button-ghost" href="{{ route('face.index') }}">Bersihkan</a>
                        @endif
                    </form>

                    <div class="live-slot" data-live="face-registered">
                        @if ($registered->isEmpty())
                            <p class="empty">
                                {{ $search !== '' ? 'Tidak ada wajah yang cocok dengan pencarian itu.' : 'Belum ada wajah terdaftar. Daftarkan wajah anggota dari panel Daftarkan Wajah dari Foto atau lewat folder foto di atas.' }}
                            </p>
                        @else
                            <div class="face-card-list">
                                @foreach ($registered as $member)
                                    @php
                                        $photos = $member->faceEmbeddings;
                                    @endphp
                                    <details class="face-card">
                                        <summary>
                                            <span class="face-card-identity">
                                                <strong>{{ $member->name }}</strong>
                                                <small>NIS {{ $member->identifier_number ?: 'belum diisi' }}</small>
                                                <small>{{ $photos->count() }} foto</small>
                                            </span>
                                            <span class="face-card-action" data-face-card-open>Lihat detail</span>
                                            <span class="face-card-action" data-face-card-close>Tutup</span>
                                        </summary>
                                        <div class="face-card-body">
                                            <div class="table-wrap">
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Foto</th>
                                                            <th>Didaftarkan</th>
                                                            <th>Aksi</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($photos as $row)
                                                            <tr>
                                                                <td data-label="Foto">
                                                                    {{ $row->label ?: 'Tanpa nama berkas' }}</td>
                                                                <td data-label="Didaftarkan">
                                                                    {{ $row->created_at?->translatedFormat('d M Y, H:i') ?? 'Tidak diketahui' }}
                                                                </td>
                                                                <td data-label="Aksi">
                                                                    <form method="POST"
                                                                        action="{{ route('face.embeddings.destroy', $row) }}"
                                                                        onsubmit="return confirm('Hapus satu foto wajah ini?')">
                                                                        @csrf
                                                                        @method('DELETE')
                                                                        <button type="submit" class="button-danger">Hapus
                                                                            foto</button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="face-card-actions">
                                                <form method="POST" action="{{ route('face.users.destroy', $member) }}"
                                                    onsubmit="return confirm('Hapus semua foto wajah {{ $member->name }}?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="button-danger">Hapus semua foto</button>
                                                </form>
                                            </div>
                                        </div>
                                    </details>
                                @endforeach
                            </div>

                            @if ($registered->hasPages())
                                {{ $registered->links('pagination.records', ['noun' => 'anggota']) }}
                            @endif
                        @endif
                    </div>
                </section>
            </main>
        </div>
    </body>

    </html>
