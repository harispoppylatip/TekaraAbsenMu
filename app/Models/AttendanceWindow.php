<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu rentang jam presensi. `kind` menentukan sekaligus jenis absen dan status
 * yang dicatat, sehingga tidak mungkin ada kombinasi yang bertentangan.
 */
class AttendanceWindow extends Model
{
    /** Absen masuk yang dihitung hadir. */
    public const KindPresent = 'present';

    /** Absen masuk yang dihitung terlambat. */
    public const KindLate = 'late';

    /** Absen pulang. */
    public const KindCheckOut = 'check_out';

    /** @var array<int, string> */
    public const Kinds = [self::KindPresent, self::KindLate, self::KindCheckOut];

    protected $fillable = ['kind', 'starts_at', 'ends_at'];

    /**
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return [
            self::KindPresent => 'Masuk - Hadir',
            self::KindLate => 'Masuk - Terlambat',
            self::KindCheckOut => 'Pulang',
        ];
    }

    /**
     * Jam mulai rentang terlambat, dipakai untuk keterangan di dashboard.
     */
    public static function lateStartsAt(): ?string
    {
        return static::query()->where('kind', self::KindLate)->ordered()->value('starts_at');
    }

    public function type(): string
    {
        return $this->kind === self::KindCheckOut ? AttendanceLog::TypeOut : AttendanceLog::TypeIn;
    }

    public function status(): string
    {
        return $this->kind === self::KindLate ? AttendanceLog::StatusLate : AttendanceLog::StatusPresent;
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::KindLate => 'Terlambat',
            self::KindCheckOut => 'Pulang',
            default => 'Hadir',
        };
    }

    public function chipClass(): string
    {
        return match ($this->kind) {
            self::KindLate => 'chip-warning',
            self::KindCheckOut => 'chip-info',
            default => 'chip-success',
        };
    }

    public function typeLabel(): string
    {
        return $this->type() === AttendanceLog::TypeOut ? 'Pulang' : 'Masuk';
    }

    public function range(): string
    {
        return $this->starts_at.' - '.$this->ends_at;
    }

    /**
     * Batas mulai termasuk dan batas selesai tidak termasuk, supaya jam yang
     * berdempetan (07:30) hanya masuk ke satu rentang yaitu rentang yang mulai
     * pada jam tersebut.
     */
    public function covers(string $time): bool
    {
        return $time >= $this->starts_at && $time < $this->ends_at;
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('starts_at')->orderBy('id');
    }
}
