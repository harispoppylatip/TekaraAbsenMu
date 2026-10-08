<?php

namespace App\Http\Controllers;

use App\Models\FaceEmbedding;
use App\Models\User;
use App\Services\FaceEncodingService;
use App\Services\FaceEnrollmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Pengelolaan data wajah.
 *
 * Satu halaman ini yang memiliki urusan wajah: siapa saja yang sudah
 * terdaftar, foto mana saja yang tersimpan, siapa yang ditugaskan memegang
 * kamera, dan apakah mesin wajahnya sedang hidup. Sengaja dikumpulkan di satu
 * tempat supaya admin tidak perlu mencari-cari di halaman lain.
 *
 * Sejak halaman ini juga bisa mendaftarkan wajah dari foto yang diunggah,
 * aturan penyimpanannya dipinjam dari FaceEnrollmentService supaya sama dengan
 * pendaftaran dari komputer server.
 */
class FaceDataController extends Controller
{
    /**
     * Batas jumlah foto dalam satu kali kirim formulir pendaftaran.
     *
     * Dibuat kecil karena membaca satu foto di mesin tanpa kartu grafis bisa
     * memakan belasan detik. Lima foto sekaligus pernah menembus batas waktu
     * PHP dan membuat seluruh kiriman gagal tanpa hasil apa pun.
     */
    public const MaxPhotosPerSubmit = 3;

    /**
     * Batas jumlah anggota dalam satu halaman daftar wajah terdaftar.
     *
     * Daftar itu berbentuk kartu, jadi satu halaman dibuat pendek dan sisanya
     * dibuka lewat pembagian halaman. Pembagian halaman hanya muncul kalau
     * anggotanya memang lebih banyak dari batas ini.
     */
    public const MembersPerPage = 10;

    public function __construct(
        private FaceEncodingService $encoder,
        private FaceEnrollmentService $enrollment,
    ) {
        /*
         * Membaca satu foto di mesin tanpa kartu grafis bisa memakan belasan
         * detik, bahkan lebih lama lagi saat mesin wajahnya baru dinyalakan.
         * Batas bawaan PHP 30 detik terlalu ketat untuk pekerjaan ini, jadi
         * seluruh permintaan wajah diberi waktu longgar. Lama menunggu tiap
         * foto tetap dibatasi oleh pengaturan layanan wajah.
         */
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('face.index', [
            'registered' => $this->registeredMembers($search),
            'search' => $search,
            'registeredCount' => FaceEmbedding::query()->distinct()->count('user_id'),
            'totalPhotos' => FaceEmbedding::query()->count(),
            'teachers' => User::query()
                ->where('role', User::RoleTeacher)
                ->orderBy('can_scan_face', 'desc')
                ->orderBy('name')
                ->get(),
            'memberCount' => User::query()->count(),
            'serviceAvailable' => $this->encoder->isAvailable(),
            'members' => User::query()->orderBy('name')->get(['id', 'name', 'role', 'identifier_number', 'class_name', 'subject']),
            'maxPhotosPerUser' => FaceEnrollmentService::MaxPhotosPerUser,
            'maxPhotosPerSubmit' => self::MaxPhotosPerSubmit,
            'uploadReport' => $request->session()->get('faceUploadReport', []),
            'uploadReportName' => $request->session()->get('faceUploadName'),
        ]);
    }

    /**
     * Daftarkan wajah seorang anggota dari foto yang diunggah di halaman ini.
     *
     * Foto dikirim beberapa sekaligus dalam satu formulir, lalu masing-masing
     * dinilai mesin wajah satu per satu. Hasil tiap berkas dilaporkan kembali ke
     * halaman supaya admin tahu foto mana yang dipakai dan mana yang tidak,
     * tanpa perlu membaca layar terminal komputer server.
     */
    public function storePhotos(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'photos' => ['required', 'array', 'max:'.self::MaxPhotosPerSubmit],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'user_id.required' => 'Pilih dulu anggota yang wajahnya didaftarkan.',
            'photos.max' => 'Satu kali kirim paling banyak '.self::MaxPhotosPerSubmit.' foto. Tiap foto diproses belasan detik, jadi kirim bertahap sampai foto wajahnya cukup.',
            'photos.*.mimes' => 'Format gambar tidak dikenali. Pakai foto jpg, jpeg, png, atau webp.',
        ]);

        $user = User::query()->findOrFail($data['user_id']);
        $report = [];

        foreach ($request->file('photos') as $photo) {
            $result = $this->enrollment->enrollFromUpload($user, $photo);

            $report[] = [
                'file' => $photo->getClientOriginalName(),
                'status' => $result['status'],
                'message' => $result['message'],
            ];
        }

        $saved = count(array_filter($report, fn (array $row): bool => $row['status'] === FaceEnrollmentService::StatusSaved));
        $failed = count($report) - $saved;
        $total = $this->enrollment->photoCount($user);

        $redirect = to_route('face.index')
            ->with('faceUploadReport', $report)
            ->with('faceUploadName', $user->name)
            ->with($saved > 0 ? 'success' : 'warning', $saved > 0
                ? $saved.' foto wajah '.$user->name.' tersimpan, total '.$total.' foto.'
                : 'Tidak ada foto wajah '.$user->name.' yang bisa disimpan.');

        return $failed > 0 && $saved > 0
            ? $redirect->with('warning', $failed.' foto dilewati. Lihat alasannya di bawah.')
            : $redirect;
    }

    /**
     * Satu berkas dari impor massal folder foto.
     *
     * Dipanggil berkas demi berkas oleh halaman, bukan sekaligus, karena
     * membaca satu foto butuh waktu dan kiriman besar bisa ditolak batas unggah
     * PHP tanpa pesan yang jelas.
     *
     * Nama folder tepat di atas berkas itulah nomor induknya, aturan yang sama
     * dengan folder foto yang dipakai pendaftaran dari komputer server.
     */
    public function uploadFolderFile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'relative' => ['nullable', 'string', 'max:255'],
        ]);

        $photo = $request->file('photo');
        $relative = trim((string) ($data['relative'] ?? '')) ?: $photo->getClientOriginalName();
        $identifier = $this->folderName($relative);

        if ($identifier === null) {
            return $this->skipped($relative, '', 'Berkas ini tidak berada di dalam folder bernama nomor induk anggota, jadi dilewati.');
        }

        $user = User::query()->where('identifier_number', $identifier)->first();

        if ($user === null) {
            return $this->skipped($relative, $identifier, 'Folder '.$identifier.' tidak cocok dengan nomor induk anggota mana pun.');
        }

        $result = $this->enrollment->enrollFromUpload($user, $photo, $relative);

        return response()->json([
            'status' => $result['status'],
            'file' => $relative,
            'identifier' => $identifier,
            'name' => $user->name,
            'message' => $result['message'],
            'total' => $result['total'],
        ]);
    }

    /**
     * Nama folder induk dari jalur berkas impor massal.
     *
     * @return string|null null bila berkasnya tidak berada di dalam folder.
     */
    private function folderName(string $relative): ?string
    {
        $segments = array_values(array_filter(
            explode('/', str_replace('\\', '/', $relative)),
            fn (string $part): bool => ! in_array($part, ['', '.', '..'], true),
        ));

        return count($segments) >= 2 ? $segments[count($segments) - 2] : null;
    }

    private function skipped(string $file, string $identifier, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'unknown_identifier',
            'file' => $file,
            'identifier' => $identifier,
            'name' => '',
            'message' => $message,
            'total' => 0,
        ]);
    }

    /**
     * Anggota yang wajahnya sudah terdaftar, satu kartu per anggota.
     *
     * Dibaca lewat tabel pengguna supaya pembagian halamannya menghitung
     * anggota, bukan foto. Foto tiap anggota diambil sekaligus agar satu kartu
     * tidak memanggil basis data sendiri-sendiri, dan yang paling baru muncul
     * di baris atas.
     *
     * Urutannya menurut nama supaya letak kartu tidak berpindah setiap kali ada
     * foto baru tersimpan, dan pencarian nama atau nomor induk tetap bekerja di
     * antara halaman.
     *
     * @return LengthAwarePaginator<int, User>
     */
    private function registeredMembers(string $search): LengthAwarePaginator
    {
        return User::query()
            ->whereHas('faceEmbeddings')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('identifier_number', 'like', $term);
                });
            })
            ->with(['faceEmbeddings' => fn (HasMany $photos): HasMany => $photos->latest('id')])
            ->orderBy('name')
            ->paginate(self::MembersPerPage)
            ->withQueryString()
            ->onEachSide(2);
    }

    public function destroyEmbedding(FaceEmbedding $faceEmbedding): RedirectResponse
    {
        $name = $faceEmbedding->user?->name ?? 'Anggota';
        $label = $faceEmbedding->label ? ' ('.$faceEmbedding->label.')' : '';

        $faceEmbedding->delete();

        return to_route('face.index')->with('success', 'Satu foto wajah '.$name.$label.' dihapus.');
    }

    public function destroyUser(User $user): RedirectResponse
    {
        $count = $user->faceEmbeddings()->count();

        $user->faceEmbeddings()->delete();

        return to_route('face.index')->with('success', 'Semua '.$count.' foto wajah '.$user->name.' dihapus. Anggota itu perlu didaftarkan ulang untuk bisa absen wajah.');
    }

    /**
     * Tugaskan atau lepaskan seorang guru sebagai petugas kamera wajah.
     * Perannya tidak berubah, hanya penandanya yang dibalik.
     */
    public function toggleOperator(User $user): RedirectResponse
    {
        abort_unless($user->isTeacher(), 422, 'Petugas kamera wajah harus berperan guru.');

        $user->update(['can_scan_face' => ! $user->canScanFace()]);

        return to_route('face.index')->with('success', $user->name.($user->canScanFace()
            ? ' ditugaskan sebagai petugas kamera wajah.'
            : ' tidak lagi menjadi petugas kamera wajah.'));
    }
}
