<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pelajaran pada jadwal mingguan: kelas mana, hari apa, jam ke berapa,
 * mata pelajaran apa, dan guru siapa yang mengajar.
 *
 * Siswa tidak punya jadwal sendiri; mereka memakai jadwal kelasnya
 * (`school_class_id`), sedangkan guru memakai baris yang `user_id`-nya dirinya.
 *
 * @property int $id
 * @property int $day
 * @property int $lesson_hour_id
 * @property int $school_class_id
 * @property int $user_id
 * @property string $subject
 */
#[Fillable(['day', 'lesson_hour_id', 'school_class_id', 'user_id', 'subject'])]
class LessonSchedule extends Model
{
    /** Nama hari menurut penomoran ISO, sama dengan Carbon: 1 = Senin. */
    public const DayNames = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat'];

    /** Hari yang dipakai sekolah. Sabtu dan Minggu bukan hari pelajaran. */
    public const Days = [1, 2, 3, 4, 5];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day' => 'integer'];
    }

    /**
     * @return BelongsTo<LessonHour, $this>
     */
    public function lessonHour(): BelongsTo
    {
        return $this->belongsTo(LessonHour::class);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Pertemuan yang sudah pernah dibuka untuk jadwal ini.
     *
     * @return HasMany<LessonSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(LessonSession::class);
    }

    /**
     * Rapikan nama mata pelajaran yang diketik bebas. Mata pelajaran belum
     * punya data induk, jadi hanya spasi berlebih yang dibuang.
     */
    public static function normalizeSubject(mixed $value): string
    {
        return is_string($value) ? (string) preg_replace('/\s+/', ' ', trim($value)) : '';
    }

    public static function dayName(?int $day): string
    {
        return self::DayNames[$day] ?? 'Hari '.$day;
    }

    public function dayLabel(): string
    {
        return self::dayName($this->day);
    }

    public function label(): string
    {
        return $this->dayLabel().' '.($this->lessonHour?->label() ?? 'Jam');
    }

    /**
     * Urut menurut hari lalu nomor jam, bukan id, supaya tampilan tetap benar
     * walau jam pelajaran ditambahkan tidak berurutan.
     *
     * @param  Builder<LessonSchedule>  $query
     * @return Builder<LessonSchedule>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('day')
            ->orderByRaw('(SELECT number FROM lesson_hours WHERE lesson_hours.id = lesson_schedules.lesson_hour_id)');
    }
}
