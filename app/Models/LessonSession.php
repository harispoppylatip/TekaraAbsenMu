<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pertemuan nyata dari satu jadwal pelajaran. Guru membuka sesi ini dari
 * halamannya saat jam pelajaran berjalan, dan hanya selama sesi terbuka sensor
 * kelas menerima scan. Menutup sesi membuat scan baru tidak lagi tercatat.
 *
 * @property int $id
 * @property int $lesson_schedule_id
 * @property Carbon $date
 * @property Carbon|null $opened_at
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $closed_at
 * @property int|null $opened_by
 * @property int|null $closed_by
 * @property int|null $substitute_user_id
 * @property string|null $note
 */
#[Fillable([
    'lesson_schedule_id',
    'date',
    'scheduled_at',
    'opened_at',
    'closed_at',
    'opened_by',
    'closed_by',
    'substitute_user_id',
    'note',
])]
class LessonSession extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'scheduled_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LessonSchedule, $this>
     */
    public function lessonSchedule(): BelongsTo
    {
        return $this->belongsTo(LessonSchedule::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Guru pengganti yang ditunjuk admin untuk pertemuan ini.
     *
     * @return BelongsTo<User, $this>
     */
    public function substitute(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substitute_user_id');
    }

    /**
     * Catatan kehadiran yang lahir dari sesi ini.
     *
     * @return HasMany<AttendanceLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class)->orderBy('scanned_at');
    }

    public function isOpen(): bool
    {
        return $this->opened_at !== null && $this->closed_at === null;
    }

    public function isScheduled(): bool
    {
        return $this->opened_at === null && $this->scheduled_at !== null;
    }

    public function statusLabel(): string
    {
        if ($this->isScheduled()) {
            return 'Terjadwal';
        }

        return $this->isOpen() ? 'Absen dibuka' : 'Sudah ditutup';
    }

    public function chipClass(): string
    {
        if ($this->isScheduled()) {
            return 'chip chip-info';
        }

        return $this->isOpen() ? 'chip chip-success' : 'chip';
    }

    public function lessonHour(): ?LessonHour
    {
        return $this->lessonSchedule?->lessonHour;
    }

    public function schoolClassName(): ?string
    {
        return $this->lessonSchedule?->schoolClass?->name;
    }

    /**
     * Guru yang bertanggung jawab pada pertemuan ini: guru pengganti kalau ada,
     * kalau tidak guru pengampu jadwalnya.
     */
    public function teacher(): ?User
    {
        return $this->substitute ?? $this->lessonSchedule?->teacher;
    }

    public function teacherUserId(): ?int
    {
        return $this->substitute_user_id !== null
            ? (int) $this->substitute_user_id
            : ($this->lessonSchedule?->user_id === null ? null : (int) $this->lessonSchedule->user_id);
    }

    public function teacherName(): string
    {
        return $this->teacher()?->name ?? 'Guru';
    }

    public function isSubstituted(): bool
    {
        return $this->substitute_user_id !== null;
    }

    /**
     * Jam pelajaran sesi ini sedang berjalan pada waktu tersebut.
     */
    public function coversTime(string $time): bool
    {
        return (bool) $this->lessonHour()?->covers($time);
    }

    /**
     * Nama hari dari tanggal pertemuannya, bukan dari jadwal, supaya admin
     * yang membuka sesi di tanggal lain tetap melihat hari yang benar.
     */
    public function dayLabel(): string
    {
        return LessonSchedule::dayName((int) $this->date->dayOfWeekIso);
    }

    public function label(): string
    {
        $parts = array_filter([
            $this->dayLabel(),
            $this->lessonHour()?->label(),
            $this->schoolClassName(),
        ]);

        return $parts === [] ? 'Pertemuan' : implode(' · ', $parts);
    }

    public function dateLabel(): string
    {
        return $this->date->format('d/m/Y');
    }

    /**
     * Rentang waktu sesi ini berjalan menurut jam pelajarannya.
     */
    public function range(): string
    {
        return $this->lessonHour()?->range() ?? '-';
    }

    public function scheduleLabel(): string
    {
        $schedule = $this->lessonSchedule;

        if ($schedule === null) {
            return 'Jadwal';
        }

        return $schedule->subject.' · '.($schedule->schoolClass?->name ?? 'Kelas');
    }

    /**
     * @param  Builder<LessonSession>  $query
     * @return Builder<LessonSession>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotNull('opened_at')->whereNull('closed_at');
    }

    /**
     * @param  Builder<LessonSession>  $query
     * @return Builder<LessonSession>
     */
    public function scopeForDate(Builder $query, mixed $date): Builder
    {
        return $query->whereDate('date', $date);
    }
}
