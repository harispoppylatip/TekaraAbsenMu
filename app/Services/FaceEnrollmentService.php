<?php

namespace App\Services;

use App\Models\FaceEmbedding;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pintu tunggal pendaftaran sidik wajah.
 *
 * Ada dua jalan masuk yang menyimpan sidik wajah: skrip pendaftaran di server
 * lewat API perangkat, dan halaman Wajah di panel admin yang fotonya diunggah
 * langsung. Keduanya harus memperlakukan data dengan aturan yang sama, jadi
 * aturannya dikumpulkan di sini: batas jumlah foto per anggota, ganti atau
 * tumpuk, dan penolakan foto yang bukan wajah tunggal.
 *
 * Kalau aturan ini disalin ulang di masing-masing pengendali, cepat atau lambat
 * keduanya berbeda dan sidik wajah tersimpan dengan aturan yang tidak sama.
 */
class FaceEnrollmentService
{
    /** Batas foto wajah per anggota supaya data tidak menumpuk tanpa kendali. */
    public const MaxPhotosPerUser = 20;

    /** Satu foto berhasil disimpan. */
    public const StatusSaved = 'saved';

    /** Tidak ada wajah yang terbaca di foto. */
    public const StatusNoFace = 'no_face';

    /** Wajah di foto lebih dari satu, jadi tidak boleh dipakai mendaftar. */
    public const StatusMultipleFaces = 'multiple_faces';

    /** Layanan wajah tidak bisa dihubungi. */
    public const StatusUnavailable = 'service_unavailable';

    /** Foto tidak bisa dibaca mesin wajah. */
    public const StatusInvalid = 'invalid_photo';

    public function __construct(private FaceEncodingService $encoder) {}

    /**
     * Simpan angka pembanding wajah pada baris baru.
     *
     * Bila `replace` benar, seluruh sidik wajah anggota itu diganti baris baru
     * ini. Bila tidak, baris baru ditumpuk dan yang paling lama dibuang begitu
     * jumlahnya melewati batas.
     *
     * Ganti dan tambah dikerjakan dalam satu transaksi supaya data lama tidak
     * sempat terhapus kalau penyimpanan barunya gagal.
     *
     * @param  array<int, mixed>  $embedding
     */
    public function store(User $user, array $embedding, ?string $label = null, bool $replace = false): FaceEmbedding
    {
        return DB::transaction(function () use ($user, $embedding, $label, $replace): FaceEmbedding {
            $row = FaceEmbedding::create([
                'user_id' => $user->id,
                'embedding' => FaceEmbedding::normalizeEmbedding($embedding),
                'label' => $label,
                'model' => (string) config('face.model', FaceEmbedding::DefaultModel),
            ]);

            if ($replace) {
                FaceEmbedding::query()
                    ->forUser((int) $user->id)
                    ->whereKeyNot($row->getKey())
                    ->delete();
            }

            $extra = FaceEmbedding::query()
                ->forUser((int) $user->id)
                ->orderByDesc('id')
                ->pluck('id')
                ->slice(self::MaxPhotosPerUser)
                ->all();

            if ($extra !== []) {
                FaceEmbedding::query()->whereKey($extra)->delete();
            }

            return $row;
        });
    }

    /**
     * Baca sebuah foto dari unggahan halaman, lalu simpan sidik wajahnya.
     *
     * Nama berkas aslinya dipakai sebagai label supaya foto mana yang dipakai
     * masih bisa ditelusuri dari halaman Wajah.
     *
     * @return array{status: string, message: string, total: int}
     */
    public function enrollFromUpload(User $user, UploadedFile $photo, ?string $label = null): array
    {
        return $this->enrollFromContents($user, $this->readPhoto($photo), $label ?? $photo->getClientOriginalName());
    }

    /**
     * Baca isi berkas foto, dinilai mesin wajah, lalu disimpan.
     *
     * Dipakai juga oleh impor massal, karena berkas yang sudah diekstrak dari
     * arsip tidak lagi berupa unggahan halaman.
     *
     * @return array{status: string, message: string, total: int}
     */
    public function enrollFromContents(User $user, string $contents, ?string $label = null): array
    {
        $encoded = $this->encoder->encodeContents($contents);
        $before = $this->photoCount($user);

        if ($encoded['status'] !== FaceEncodingService::StatusSuccess) {
            return [
                'status' => $this->translateStatus($encoded['status']),
                'message' => $encoded['message'],
                'total' => $before,
            ];
        }

        // Foto berisi lebih dari satu orang tidak dipakai, karena orang yang
        // tidak diniatkan bisa terdaftar dan ikut dikenali saat absen. Aturan
        // yang sama sudah dipegang skrip pendaftaran di server.
        if (($encoded['face_count'] ?? 1) > 1) {
            return [
                'status' => self::StatusMultipleFaces,
                'message' => 'Foto ini memuat lebih dari satu wajah, jadi tidak dipakai. Pilih foto yang hanya berisi satu orang.',
                'total' => $before,
            ];
        }

        $row = $this->store($user, $encoded['encoding'], $this->cleanLabel($label));
        $total = $this->photoCount($user);
        $dropped = $before + 1 - $total;

        return [
            'status' => self::StatusSaved,
            'message' => 'Wajah '.$user->name.' tersimpan dari foto '.($row->label ?: 'tanpa nama').'.'
                .($dropped > 0 ? ' Foto terlama dihapus karena batas '.self::MaxPhotosPerUser.' foto per anggota.' : '')
                .' Total '.$total.' foto.',
            'total' => $total,
        ];
    }

    public function photoCount(User $user): int
    {
        return $user->faceEmbeddings()->count();
    }

    /**
     * Sisa jatah foto wajah anggota ini sebelum menyentuh batas.
     */
    public function remaining(User $user): int
    {
        return max(0, self::MaxPhotosPerUser - $this->photoCount($user));
    }

    /**
     * Pesan layanan wajah tidak dikirim apa adanya ke layar pendaftaran, karena
     * bahasanya ditujukan untuk kamera absen. Yang perlu diketahui admin cuma
     * satu hal: layanan wajahnya belum hidup.
     */
    private function translateStatus(string $status): string
    {
        return match ($status) {
            FaceEncodingService::StatusNoFace => self::StatusNoFace,
            FaceEncodingService::StatusUnavailable => self::StatusUnavailable,
            default => self::StatusInvalid,
        };
    }

    /**
     * Isi berkas tidak selalu bisa dibaca (berkas terlalu besar, gagal dibaca).
     * Yang dikirim ke mesin wajah tetap teks kosong supaya balasannya satu
     * bentuk: "Foto tidak terbaca".
     */
    private function readPhoto(UploadedFile $photo): string
    {
        $contents = @file_get_contents($photo->getRealPath());

        return is_string($contents) ? $contents : '';
    }

    /**
     * Nama berkas dipakai sebagai label, jadi panjangnya diseragamkan dengan
     * batas label pada API pendaftaran.
     */
    private function cleanLabel(?string $label): ?string
    {
        $clean = Str::squish((string) $label);

        return $clean === '' ? null : Str::limit($clean, 50, '');
    }
}
