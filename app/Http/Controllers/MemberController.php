<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\CsvExporter;
use App\Services\FaceEnrollmentService;
use App\Services\MemberImportService;
use App\Services\MqttFingerprintService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberController extends Controller
{
    public function __construct(
        private MqttFingerprintService $mqtt,
        private FaceEnrollmentService $enrollment,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'role' => in_array($request->query('role'), User::Roles, true) ? (string) $request->query('role') : '',
            'class_name' => trim((string) $request->query('class_name', '')),
            'fingerprint' => in_array($request->query('fingerprint'), ['registered', 'pending'], true)
                ? (string) $request->query('fingerprint')
                : '',
        ];

        // Hitungan per peran memakai filter lain kecuali peran itu sendiri,
        // supaya angka di tombol peran menunjukkan hasil kalau tombol itu ditekan.
        $roleCounts = $this->filteredMembers(['role' => ''] + $filters)
            ->toBase()
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role')
            ->map(fn (mixed $total): int => (int) $total);

        $users = $this->filteredMembers($filters)
            ->withCount('fingerprints')
            ->latest()
            ->get();

        return view('members.index', [
            'users' => $users,
            'filters' => $filters,
            'hasFilters' => array_filter($filters) !== [],
            'roleCounts' => $roleCounts,
            'totalMembers' => User::query()->count(),
            'editUser' => $this->editingUser($request),
            'pairedDevices' => Device::usable()->orderBy('name')->get(),
            'classes' => SchoolClass::query()->orderedByName()->pluck('name'),
        ]);
    }

    /**
     * Anggota yang cocok dengan filter halaman. Kelas dicocokkan tanpa
     * membedakan huruf besar dan kecil, sama seperti kelas di halaman lain.
     *
     * @param  array{q: string, role: string, class_name: string, fingerprint: string}  $filters
     * @return Builder<User>
     */
    private function filteredMembers(array $filters): Builder
    {
        return User::query()
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.$filters['q'].'%';

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('identifier_number', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when($filters['role'] !== '', fn (Builder $query) => $query->where('role', $filters['role']))
            ->when($filters['class_name'] !== '', fn (Builder $query) => $query->whereRaw('LOWER(class_name) = ?', [mb_strtolower($filters['class_name'])]))
            ->when($filters['fingerprint'] === 'registered', fn (Builder $query) => $query->has('fingerprints'))
            ->when($filters['fingerprint'] === 'pending', fn (Builder $query) => $query->doesntHave('fingerprints'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'photo.file' => 'Foto gagal diunggah. Coba pilih ulang fotonya.',
            'photo.mimes' => 'Foto harus berformat JPG, PNG, atau WEBP.',
            'photo.max' => 'Foto terlalu besar (paling besar 5 MB).',
        ]);

        unset($data['photo']);

        $user = User::create(User::applyStudyFields($data) + [
            'password' => User::defaultPasswordFor($data['role'], $data['identifier_number']),
            'must_change_password' => true,
            'status' => User::StatusActive,
        ]);

        $message = 'Anggota '.$user->name.' berhasil ditambahkan dengan kata sandi bawaan '.$this->defaultPasswordHint($user).'. Lanjutkan dengan pendaftaran sidik jari dari tombol pada tabel.';
        $faceWarning = null;

        if ($request->hasFile('photo')) {
            $result = $this->enrollment->enrollFromUpload($user, $request->file('photo'));

            if ($result['status'] === FaceEnrollmentService::StatusSaved) {
                $message .= ' '.$result['message'];
            } else {
                $faceWarning = 'Anggota sudah tersimpan, tetapi fotonya belum bisa dipakai. '.$result['message'].' Daftarkan wajahnya dari halaman Data Wajah.';
            }
        }

        $redirect = to_route('members.index')->with('success', $message);

        return $faceWarning === null ? $redirect : $redirect->with('warning', $faceWarning);
    }

    /**
     * Tambah banyak anggota sekaligus dari file CSV. Baris yang bermasalah
     * dilewati dan dilaporkan, baris lainnya tetap disimpan.
     */
    public function import(Request $request, MemberImportService $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'file.required' => 'Pilih dulu file CSV yang akan diunggah.',
            'file.file' => 'File gagal diunggah. Coba pilih ulang filenya.',
            'file.mimes' => 'File harus berformat CSV. Di Excel pilih Simpan Sebagai, lalu CSV.',
            'file.max' => 'File terlalu besar (paling besar 2 MB).',
        ]);

        $result = $importer->import($request->file('file')->getRealPath());

        if ($result['error'] !== null) {
            return to_route('members.index')->withErrors(['file' => $result['error']]);
        }

        $created = count($result['created']);
        $skipped = count($result['skipped']);

        $message = match (true) {
            $created > 0 && $skipped === 0 => $created.' anggota berhasil ditambahkan dari file.',
            $created > 0 => $created.' anggota berhasil ditambahkan, '.$skipped.' baris dilewati. Lihat daftar baris yang dilewati di bawah.',
            default => 'Tidak ada anggota yang ditambahkan, semua '.$skipped.' baris dilewati. Lihat alasannya di bawah.',
        };

        return to_route('members.index')
            ->with($created > 0 ? 'success' : 'warning', $message.($created > 0 ? ' Kata sandi bawaan siswa adalah NIS masing-masing, guru dan admin '.User::DefaultPassword.'.' : ''))
            ->with('importSkipped', $result['skipped']);
    }

    /**
     * File CSV kosong dengan judul kolom yang dikenali impor anggota.
     */
    public function importTemplate(CsvExporter $csv): StreamedResponse
    {
        return $csv->download('contoh-impor-anggota.csv', MemberImportService::TemplateHeaders, []);
    }

    /**
     * Kata sandi bawaan yang perlu diberitahukan ke pemilik akun. Siswa memakai
     * nomor induknya, guru dan admin memakai satu kata sandi bersama.
     */
    private function defaultPasswordHint(User $user): string
    {
        return $user->isStudent()
            ? $user->identifier_number.' (nomor induk)'
            : User::DefaultPassword;
    }

    /**
     * Kembalikan kata sandi anggota ke bawaan. Dipakai kalau anggota lupa kata
     * sandinya; setelah ini dia wajib menggantinya sendiri saat masuk.
     */
    public function resetPassword(User $user): RedirectResponse
    {
        $user->update([
            'password' => User::defaultPasswordFor((string) $user->role, $user->identifier_number),
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);

        return to_route('members.index')->with('success', 'Kata sandi '.$user->name.' dikembalikan ke bawaan '.$this->defaultPasswordHint($user).'. Anggota wajib menggantinya saat masuk.');
    }

    /**
     * Perbarui data anggota, termasuk kelas atau mata pelajaran dan perannya.
     * Nilainya diketik bebas: kelas yang belum terdaftar ikut dibuat, sedangkan
     * mata pelajaran guru hanya disimpan sebagai teks.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validator = Validator::make($request->all(), $this->rules($user));

        if ($validator->fails()) {
            return to_route('members.index', ['edit' => $user->getKey()])
                ->withErrors($validator)
                ->withInput();
        }

        $user->update(User::applyStudyFields($validator->validated()));

        return to_route('members.index')->with('success', 'Data anggota berhasil diperbarui.');
    }

    public function enroll(User $user): RedirectResponse
    {
        abort_if($user->fingerprints()->exists(), 422, 'Pengguna ini sudah memiliki template sidik jari. Pakai tombol ganti sidik jari untuk mendaftarkan ulang.');

        $problem = $this->enrollmentProblem($user);

        if ($problem !== null) {
            return $problem;
        }

        return $this->startEnrollment($user, 'Mode pendaftaran aktif. Tempelkan jari pada sensor dalam dua menit.');
    }

    /**
     * Hapus template lama lalu minta sensor mendaftarkan sidik jari baru.
     *
     * Syaratnya diperiksa lebih dulu supaya template lama tidak terhapus
     * percuma saat perintah pendaftarannya ternyata tidak bisa dikirim.
     */
    public function replaceFingerprint(User $user): RedirectResponse
    {
        abort_unless($user->fingerprints()->exists(), 422, 'Pengguna ini belum memiliki template sidik jari. Pakai tombol scan sidik jari.');

        $problem = $this->enrollmentProblem($user);

        if ($problem !== null) {
            return $problem;
        }

        $user->fingerprints()->delete();

        return $this->startEnrollment($user, 'Template sidik jari lama dihapus. Tempelkan jari baru pada sensor dalam dua menit.');
    }

    /**
     * Syarat yang harus terpenuhi sebelum perintah pendaftaran dikirim.
     *
     * Nomor induk wajib ada karena nomor itulah identitas yang dikirim ke
     * sensor bersama perintahnya. Perintah tanpa nomor induk ditolak alat,
     * sehingga sidik jarinya tidak pernah sampai ke sensor dan halaman ini
     * terlihat seolah tidak terjadi apa-apa. Karena itu alasannya diperiksa di
     * sini supaya bisa ditampilkan ke admin.
     */
    private function enrollmentProblem(User $user): ?RedirectResponse
    {
        if (blank($user->identifier_number)) {
            return to_route('members.index')->withErrors([
                'identifier_number' => 'Anggota '.$user->name.' belum punya nomor induk, jadi perintah pendaftaran tidak bisa dikirim ke sensor. Isi nomor induknya lewat tombol Ubah lebih dulu.',
            ]);
        }

        if (! Device::usable()->exists()) {
            return to_route('members.index')->withErrors([
                'device' => 'Belum ada perangkat yang didaftarkan. Daftarkan perangkat di halaman Alat Sensor sebelum mendaftarkan sidik jari.',
            ]);
        }

        return null;
    }

    /**
     * Kirim perintah pendaftaran ke setiap perangkat yang sudah didaftarkan dan aktif.
     *
     * Perangkat yang belum didaftarkan tidak boleh dipakai, jadi admin harus
     * mendaftarkan perangkat di halaman Alat Sensor terlebih dahulu.
     */
    private function startEnrollment(User $user, string $successMessage): RedirectResponse
    {
        $deviceIds = Device::usable()->orderBy('name')->pluck('device_id');

        foreach ($deviceIds as $deviceId) {
            Cache::put('fingerprint.command.'.$deviceId, [
                'mode' => 'enroll',
                'user_id' => $user->identifier_number,
            ], now()->addMinutes(2));

            $this->mqtt->publishCommand($deviceId, [
                'mode' => 'enroll',
                'user_id' => $user->identifier_number,
                'expires_at' => now()->addMinutes(2)->toIso8601String(),
            ]);
        }

        return to_route('members.index')->with('success', $successMessage);
    }

    /**
     * Hapus pengguna beserta sidik jari dan riwayat presensinya (foreign key cascade).
     */
    public function destroy(User $user): RedirectResponse
    {
        $identifierNumber = $user->identifier_number;

        $user->delete();
        $this->forgetEnrollment($identifierNumber);

        return to_route('members.index')->with('success', 'Anggota beserta sidik jari dan riwayat presensinya berhasil dihapus.');
    }

    /**
     * Batalkan perintah pendaftaran yang masih menunggu untuk NIM yang baru dihapus.
     */
    private function forgetEnrollment(?string $identifierNumber): void
    {
        if (blank($identifierNumber)) {
            return;
        }

        $deviceIds = Device::pluck('device_id')->unique();

        foreach ($deviceIds as $deviceId) {
            $command = Cache::get('fingerprint.command.'.$deviceId);

            if (($command['user_id'] ?? null) !== $identifierNumber) {
                continue;
            }

            Cache::forget('fingerprint.command.'.$deviceId);
            $this->mqtt->publishCommand($deviceId, [
                'mode' => 'verify',
                'user_id' => null,
            ]);
        }
    }

    /**
     * Anggota yang sedang diubah dari tautan pada tabel. Nilai asing pada
     * query string diabaikan supaya halaman kembali ke mode tambah, bukan
     * menampilkan error.
     */
    private function editingUser(Request $request): ?User
    {
        $edit = $request->query('edit');

        return is_numeric($edit) ? User::query()->find((int) $edit) : null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?User $user = null): array
    {
        return User::memberRules($user);
    }
}
