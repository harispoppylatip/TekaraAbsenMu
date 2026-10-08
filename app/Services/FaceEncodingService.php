<?php

namespace App\Services;

use App\Models\FaceEmbedding;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Jembatan ke layanan wajah Python yang menghitung angka pembanding wajah.
 *
 * Aplikasi ini sengaja tidak menjalankan mesin wajah langsung dari PHP, karena
 * memuat model wajah butuh beberapa detik dan terlalu berat bila dilakukan pada
 * setiap scan. Yang dipakai adalah layanan kecil, sehingga modelnya sudah siap
 * dan hanya perlu dikirimi foto.
 *
 * Alamat layanan itu diambil dari `FACE_SERVICE_URL` di berkas .env. Bawaannya
 * localhost (satu komputer), dan diisi alamat komputer lain bila mesin wajahnya
 * dipisah. Mengubah alamat itu wajib diikuti `php artisan config:clear`.
 */
class FaceEncodingService
{
    /** Foto berhasil dibaca dan wajahnya ditemukan. */
    public const StatusSuccess = 'success';

    /** Foto terbaca, tetapi tidak ada wajah yang bisa dibaca. */
    public const StatusNoFace = 'no_face';

    /** Layanan wajah tidak bisa dihubungi atau tidak merespons. */
    public const StatusUnavailable = 'service_unavailable';

    /** Balasan layanan wajah tidak sesuai yang diharapkan. */
    public const StatusInvalid = 'invalid_response';

    /**
     * Hitung angka pembanding wajah dari sebuah foto yang diunggah lewat
     * halaman.
     *
     * @return array{status: string, encoding: array<int, float>|null, face_count: int, message: string}
     */
    public function encode(UploadedFile $image): array
    {
        $contents = @file_get_contents($image->getRealPath());

        return $this->encodeContents(is_string($contents) ? $contents : '');
    }

    /**
     * Hitung angka pembanding wajah dari isi sebuah berkas foto.
     *
     * Dipisah dari `encode()` supaya berkas yang bukan unggahan halaman, seperti
     * foto hasil ekstrak arsip impor massal, bisa dibaca dengan jalan yang sama.
     *
     * @return array{status: string, encoding: array<int, float>|null, face_count: int, message: string}
     */
    public function encodeContents(string $contents): array
    {
        if ($contents === '') {
            return $this->failure(self::StatusInvalid, 'Foto tidak terbaca. Ambil ulang fotonya.');
        }

        try {
            $response = Http::timeout((int) config('face.timeout', 15))
                ->acceptJson()
                ->attach('image', $contents, 'scan.jpg')
                ->post($this->serviceUrl().'/encode');
        } catch (ConnectionException) {
            return $this->failure(self::StatusUnavailable, $this->unavailableMessage());
        }

        if (! $response->successful()) {
            return $this->failure(self::StatusInvalid, 'Layanan wajah menolak foto ini. Ambil ulang fotonya.');
        }

        $data = $response->json();

        if (! is_array($data)) {
            return $this->failure(self::StatusInvalid, 'Balasan layanan wajah tidak dikenali.');
        }

        if (($data['status'] ?? null) === self::StatusNoFace) {
            return $this->failure(self::StatusNoFace, 'Wajah tidak terlihat. Dekatkan wajah ke kamera dan pastikan cahaya cukup.');
        }

        $encoding = $data['encoding'] ?? null;

        if (! is_array($encoding) || count($encoding) !== FaceEmbedding::Dimensions) {
            return $this->failure(self::StatusInvalid, 'Wajah tidak terbaca dengan baik. Ambil ulang fotonya.');
        }

        return [
            'status' => self::StatusSuccess,
            'encoding' => FaceEmbedding::normalizeEmbedding($encoding),
            'face_count' => (int) ($data['face_count'] ?? 1),
            'message' => 'Wajah terbaca.',
        ];
    }

    /**
     * Layanan wajah hidup atau tidak. Dipakai halaman pengelolaan wajah untuk
     * memberi tahu operator kalau mesin wajahnya belum dinyalakan.
     */
    public function isAvailable(): bool
    {
        try {
            return Http::timeout(3)->acceptJson()->get($this->serviceUrl().'/health')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function unavailableMessage(): string
    {
        return 'Layanan wajah di server belum aktif. Minta operator menyalakan layanan wajah, lalu coba lagi.';
    }

    private function serviceUrl(): string
    {
        return (string) config('face.service_url', 'http://127.0.0.1:5005');
    }

    /**
     * @return array{status: string, encoding: null, face_count: int, message: string}
     */
    private function failure(string $status, string $message): array
    {
        return ['status' => $status, 'encoding' => null, 'face_count' => 0, 'message' => $message];
    }
}
