<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu sesi jam pelajaran dalam sehari, misalnya jam ke-1 pukul 07:00 sampai
 * 07:45. Daftar ini berlaku sama untuk semua hari, jadi cukup diatur sekali di
 * halaman Jam Pelajaran.
 *
 * @property int $id
 * @property int $number
 * @property string $starts_at
 * @property string $ends_at
 */
#[Fillable(['number', 'starts_at', 'ends_at'])]
class LessonHour extends Model
{
    /**
     * @return HasMany<LessonSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(LessonSchedule::class)->ordered();
    }

    public function label(): string
    {
        return 'Jam ke-'.$this->number;
    }

    public function range(): string
    {
        return $this->starts_at.' - '.$this->ends_at;
    }

    /**
     * Batas mulai termasuk dan batas selesai tidak termasuk, sama seperti jam
     * presensi, supaya jam yang berdempetan hanya masuk ke satu rentang.
     */
    public function covers(string $time): bool
    {
        return $time >= $this->starts_at && $time < $this->ends_at;
    }

    /**
     * Waktu mulai jam ini pada tanggal tertentu.
     */
    public function startsOn(mixed $date): Carbon
    {
        return Carbon::parse(Carbon::parse($date)->format('Y-m-d').' '.$this->starts_at);
    }

    /**
     * Waktu selesai jam ini pada tanggal tertentu.
     */
    public function endsOn(mixed $date): Carbon
    {
        return Carbon::parse(Carbon::parse($date)->format('Y-m-d').' '.$this->ends_at);
    }

    /**
     * @param  Builder<LessonHour>  $query
     * @return Builder<LessonHour>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('number');
    }
}
