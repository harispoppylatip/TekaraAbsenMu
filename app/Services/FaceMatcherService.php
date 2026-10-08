<?php

namespace App\Services;

use App\Models\FaceEmbedding;
use App\Models\User;

/**
 * Pencocokan wajah di sisi aplikasi.
 *
 * Angka pembanding dari layanan wajah (128 bilangan) dicocokkan dengan seluruh
 * sidik wajah yang tersimpan, lalu diambil yang paling mirip. Cara ini disebut
 * pencocokan 1:N: dari satu wajah di depan kamera, dicari siapa di antara
 * semua anggota sekolah yang paling mendekati.
 */
class FaceMatcherService
{
    /**
     * Cari anggota yang paling mirip dengan sebuah wajah.
     *
     * @param  array<int, float>  $embedding
     * @return array{user: User, distance: float, embedding: FaceEmbedding}|null
     */
    public function identify(array $embedding): ?array
    {
        if (count($embedding) !== FaceEmbedding::Dimensions) {
            return null;
        }

        $best = null;

        FaceEmbedding::query()
            ->with('user')
            ->chunkById(500, function ($rows) use ($embedding, &$best): void {
                foreach ($rows as $row) {
                    $stored = $row->embedding;

                    if (! is_array($stored) || count($stored) !== count($embedding)) {
                        continue;
                    }

                    $distance = $this->distance($embedding, $stored);

                    if ($best === null || $distance < $best['distance']) {
                        $best = [
                            'user' => $row->user,
                            'distance' => $distance,
                            'embedding' => $row,
                        ];
                    }
                }
            });

        if ($best === null || $best['user'] === null) {
            return null;
        }

        return $best['distance'] > $this->tolerance() ? null : $best;
    }

    /**
     * Jarak antara dua angka pembanding. Semakin kecil, semakin mirip.
     *
     * @param  array<int, mixed>  $left
     * @param  array<int, mixed>  $right
     */
    public function distance(array $left, array $right): float
    {
        $sum = 0.0;

        foreach ($left as $index => $value) {
            $difference = (float) $value - (float) ($right[$index] ?? 0.0);
            $sum += $difference * $difference;
        }

        return sqrt($sum);
    }

    public function tolerance(): float
    {
        return (float) config('face.tolerance', 0.6);
    }
}
