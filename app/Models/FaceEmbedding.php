<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris sidik wajah: hasil pembacaan mesin wajah dari satu foto anggota.
 *
 * Nilai `embedding` adalah 128 angka pembanding wajah, dan `label` menyimpan
 * asal fotonya (misalnya nama berkas) supaya salah kenal bisa ditelusuri.
 */
class FaceEmbedding extends Model
{
    /** Nama model mesin wajah yang menghasilkan angka pembanding. */
    public const DefaultModel = 'dlib-resnet-v1';

    /** Panjang angka pembanding (embedding) yang dikenali aplikasi ini. */
    public const Dimensions = 128;

    protected $fillable = ['user_id', 'embedding', 'label', 'model'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['embedding' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Rapikan angka pembanding yang dikirim layanan wajah menjadi deretan
     * bilangan pecahan bertipe pasti, supaya perbandingan tidak terpengaruh
     * tipe data kiriman JSON.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, float>
     */
    public static function normalizeEmbedding(array $values): array
    {
        return array_values(array_map(fn (mixed $value): float => (float) $value, $values));
    }

    /**
     * @param  Builder<FaceEmbedding>  $query
     * @return Builder<FaceEmbedding>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
