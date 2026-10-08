<?php

namespace Tests;

use App\Models\LessonHour;
use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    /**
     * Masuk sebagai pengguna yang sudah ada. Dipakai tes yang perlu memeriksa
     * peran selain admin, misalnya halaman guru atau siswa.
     */
    protected function signInAs(User $user): User
    {
        $this->actingAs($user);

        return $user;
    }

    /**
     * Masuk sebagai admin sekolah, peran yang memegang pengaturan jam
     * pelajaran, jadwal, kelas, dan anggota.
     */
    protected function signInAsAdmin(): User
    {
        return $this->signInAs(User::factory()->admin()->create());
    }

    /**
     * Siapkan satu jam pelajaran. Waktunya diturunkan dari nomor jamnya, jadi
     * jam ke-1 selalu 07:00 sampai 07:45 dan jam ke-2 mulai 07:45. Waktu bisa
     * diganti sendiri kalau tesnya memang menguji rentang tertentu.
     */
    protected function lessonHour(int $number = 1, ?string $startsAt = null, ?string $endsAt = null): LessonHour
    {
        return LessonHour::create([
            'number' => $number,
            'starts_at' => $startsAt ?? $this->lessonTime($number),
            'ends_at' => $endsAt ?? $this->lessonTime($number, 45),
        ]);
    }

    /**
     * Pukul berapa jam ke-`$number` mulai, ditambah berapa menit.
     */
    protected function lessonTime(int $number, int $offsetMinutes = 0): string
    {
        return Carbon::createFromTime(7, 0)
            ->addMinutes(max(0, $number - 1) * 45 + $offsetMinutes)
            ->format('H:i');
    }

    /**
     * Jadwalkan satu pelajaran di sebuah kelas. Hari bawaannya Senin (1).
     */
    protected function lessonSchedule(
        SchoolClass $schoolClass,
        User $teacher,
        ?LessonHour $lessonHour = null,
        int $day = 1,
        ?string $subject = null,
    ): LessonSchedule {
        $lessonHour ??= $this->lessonHour();

        return LessonSchedule::create([
            'day' => $day,
            'lesson_hour_id' => $lessonHour->getKey(),
            'school_class_id' => $schoolClass->getKey(),
            'user_id' => $teacher->getKey(),
            'subject' => $subject ?? $teacher->subject ?? 'Matematika',
        ]);
    }

    /**
     * Buka satu pertemuan absen tanpa lewat halaman guru, untuk tes yang hanya
     * perlu keadaan "sesi sedang terbuka".
     */
    protected function openLessonSession(
        LessonSchedule $schedule,
        mixed $date = null,
        ?int $substituteUserId = null,
    ): LessonSession {
        return LessonSession::create([
            'lesson_schedule_id' => $schedule->getKey(),
            'date' => Carbon::parse($date ?? Carbon::now())->toDateString(),
            'opened_at' => Carbon::now(),
            'opened_by' => $schedule->user_id,
            'substitute_user_id' => $substituteUserId,
        ]);
    }
}
