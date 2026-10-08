<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    /** Presensi dari sensor gerbang: absen masuk dan pulang mengikuti jam presensi. */
    public const SourceGate = 'gate';

    /** Presensi dari sensor kelas: kehadiran di kelas tempat sensor itu dipasang. */
    public const SourceClass = 'class';

    /** Absen masuk, dicatat saat anggota datang. */
    public const TypeIn = 'in';

    /** Absen pulang, dicatat saat anggota meninggalkan sekolah. */
    public const TypeOut = 'out';

    public const StatusPresent = 'present';

    public const StatusLate = 'late';

    public const StatusPermission = 'permission';

    protected $fillable = ['user_id', 'device_id', 'lesson_session_id', 'scanned_at', 'status', 'type', 'source'];

    /**
     * Model di memori tidak ikut mengambil default dari kolom database, jadi
     * jenis absen dan sumbernya perlu punya default di model agar label selalu
     * benar.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['type' => self::TypeIn, 'source' => self::SourceGate];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    /**
     * Sesi jam pelajaran yang menghasilkan catatan ini. Kosong untuk presensi
     * gerbang dan untuk catatan yang dibuat sebelum fitur jam pelajaran ada.
     */
    public function lessonSession(): BelongsTo
    {
        return $this->belongsTo(LessonSession::class);
    }

    public function isClassLog(): bool
    {
        return $this->source === self::SourceClass;
    }

    public function sourceLabel(): string
    {
        return $this->isClassLog() ? 'Kelas' : 'Gerbang';
    }

    public function typeLabel(): string
    {
        return match (true) {
            $this->lesson_session_id !== null => 'Jam Pelajaran',
            $this->isClassLog() => 'Kelas',
            $this->type === self::TypeOut => 'Pulang',
            default => 'Masuk',
        };
    }

    /**
     * Label yang dibaca pengguna. Jenis absen lebih menentukan daripada status,
     * karena absen pulang selalu tercatat pada jam yang sudah ditentukan.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->lesson_session_id !== null => 'Hadir Kelas',
            $this->isClassLog() => 'Hadir Kelas',
            $this->type === self::TypeOut => 'Pulang',
            $this->status === self::StatusLate => 'Terlambat',
            $this->status === self::StatusPermission => 'Izin',
            default => 'Hadir',
        };
    }

    /**
     * Keterangan yang dibaca pengguna pada tabel riwayat. Absen pulang gerbang
     * tidak punya status keterlambatan, jadi cukup ditandai sudah tercatat agar
     * tidak tampil kembar dengan kolom jenis absen yang juga berbunyi "Pulang".
     */
    public function historyStatusLabel(): string
    {
        return $this->isClassLog() || $this->type === self::TypeIn
            ? $this->statusLabel()
            : 'Tercatat';
    }

    public function chipClass(): string
    {
        return match ($this->statusLabel()) {
            'Terlambat' => 'chip-warning',
            'Pulang', 'Izin' => 'chip-info',
            default => 'chip-success',
        };
    }

    /**
     * Presensi dari sensor gerbang saja, dipakai untuk hitungan kehadiran harian
     * supaya scan sensor kelas tidak dihitung dua kali.
     *
     * @param  Builder<AttendanceLog>  $query
     * @return Builder<AttendanceLog>
     */
    public function scopeFromGate(Builder $query): Builder
    {
        return $query->where('source', self::SourceGate);
    }

    /**
     * @param  Builder<AttendanceLog>  $query
     * @return Builder<AttendanceLog>
     */
    public function scopeFromClassSensor(Builder $query): Builder
    {
        return $query->where('source', self::SourceClass);
    }
}
