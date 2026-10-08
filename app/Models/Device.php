<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    /** Layanan sensor gerbang: mencatat absen masuk dan pulang mengikuti jam presensi. */
    public const ServiceGate = 'gate';

    /** Perangkat baru terdeteksi dan belum didaftarkan ke sekolah. */
    public const StatusUnmapped = 'unmapped';

    /** Perangkat sudah didaftarkan dan boleh dipakai untuk presensi. */
    public const StatusActive = 'active';

    /** Perangkat sudah didaftarkan tetapi dimatikan sementara. */
    public const StatusInactive = 'inactive';

    protected $fillable = [
        'device_id',
        'name',
        'location',
        'status',
        'serves_gate',
        'token_hash',
        'last_ping',
    ];

    /**
     * Perangkat baru selalu lahir sebagai `unmapped` dan sensor gerbang agar
     * konsisten dengan default kolom database, termasuk saat model dibuat lewat
     * `firstOrCreate()` tanpa menyentuh database lagi.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::StatusUnmapped,
        'serves_gate' => true,
    ];

    protected function casts(): array
    {
        return [
            'serves_gate' => 'boolean',
            'last_ping' => 'datetime',
        ];
    }

    public function enrollmentSessions(): HasMany
    {
        return $this->hasMany(EnrollmentSession::class, 'device_id', 'device_id');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'device_id', 'device_id');
    }

    /**
     * Perangkat ini melayani absen masuk dan pulang, jadi jam presensi berlaku
     * penuh untuk alat ini.
     */
    public function isGate(): bool
    {
        return (bool) $this->serves_gate;
    }

    /**
     * Kelas yang dilayani perangkat ini. Tautan kosong berarti perangkat
     * melayani semua kelas, misalnya alat yang dipasang di gerbang.
     *
     * @return BelongsToMany<SchoolClass, $this>
     */
    public function schoolClasses(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class)->withTimestamps();
    }

    /**
     * Perangkat sudah didaftarkan ke sekolah (bukan hasil deteksi otomatis).
     */
    public function isPaired(): bool
    {
        return $this->status !== self::StatusUnmapped;
    }

    /**
     * Perangkat boleh dipakai untuk scan dan pendaftaran sidik jari.
     */
    public function isUsable(): bool
    {
        return $this->status === self::StatusActive;
    }

    public function label(): string
    {
        return $this->name ?: $this->device_id;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::StatusActive => 'Aktif',
            self::StatusInactive => 'Tidak aktif',
            default => 'Belum didaftarkan',
        };
    }

    /**
     * Perangkat ini terikat pada kelas tertentu sehingga tidak melayani semua
     * anggota sekolah.
     */
    public function restrictedToClasses(): bool
    {
        return $this->schoolClasses->isNotEmpty();
    }

    /**
     * Ringkasan layanan yang dipilih untuk perangkat, dipakai pada pesan
     * konfirmasi di halaman Presensi Gerbang.
     */
    public function serviceSummary(): string
    {
        $services = $this->isGate() ? ['sensor pulang masuk'] : [];
        $services = array_merge($services, $this->classNames());

        return $services === [] ? 'belum ada layanan' : implode(', ', $services);
    }

    /**
     * Nama semua kelas yang dilayani perangkat ini.
     *
     * @return array<int, string>
     */
    public function classNames(): array
    {
        return $this->schoolClasses->pluck('name')->all();
    }

    /**
     * Perangkat melayani kelas dengan nama tertentu.
     */
    public function servesClass(string $className): bool
    {
        return in_array($className, $this->classNames(), true);
    }

    /**
     * @param  Builder<Device>  $query
     * @return Builder<Device>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', self::StatusActive);
    }
}
