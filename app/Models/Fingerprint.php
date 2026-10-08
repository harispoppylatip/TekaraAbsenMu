<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fingerprint extends Model
{
    public const DefaultFingerPosition = 'Jelunjuk Kanan';

    protected $fillable = ['user_id', 'template', 'finger_position'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Nama posisi jari untuk ditampilkan. Nilai tersimpan sejak awal memakai
     * ejaan "Jelunjuk" dan dipakai sebagai kunci pencocokan template, jadi
     * nilainya dibiarkan dan hanya tampilannya yang dibetulkan.
     */
    public static function positionLabel(?string $position): string
    {
        return str_replace('Jelunjuk', 'Telunjuk', $position ?: self::DefaultFingerPosition);
    }
}
