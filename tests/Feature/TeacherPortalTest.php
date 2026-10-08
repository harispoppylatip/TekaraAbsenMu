<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeacherPortalTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // Senin, 14 September 2026, jam ke-1 sedang berjalan dan sudah lewat
        // kelonggaran membuka absen.
        $this->travelTo(Carbon::parse('2026-09-14 07:05:00'));

        $this->class = SchoolClass::create(['name' => 'X TKJ 1']);
        $this->teacher = User::factory()->teacher('Matematika')->create(['name' => 'Pak Dedi']);

        $this->signInAs($this->teacher);
    }

    public function test_teacher_only_sees_his_own_schedule(): void
    {
        $otherClass = SchoolClass::create(['name' => 'XI AKL 1']);
        $otherTeacher = User::factory()->teacher('Bahasa Jawa')->create(['name' => 'Bu Sari']);

        $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $this->lessonSchedule($otherClass, $otherTeacher, $this->lessonHour(2), 1, 'Bahasa Jawa');

        $this->withoutVite()
            ->get(route('teacher.index'))
            ->assertOk()
            ->assertSee('Jadwal Mengajar Senin')
            ->assertSee('Matematika')
            ->assertSee('X TKJ 1')
            ->assertDontSee('Bahasa Jawa')
            ->assertDontSee('XI AKL 1')
            ->assertSee('Jadwal Mengajar Seminggu');
    }

    public function test_schedule_changed_by_admin_appears_on_the_teacher_page(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $thirdHour = $this->lessonHour(3);
        $otherClass = SchoolClass::create(['name' => 'XI AKL 1']);

        $this->signInAsAdmin();

        $this->put(route('schedules.update', $schedule), [
            'day' => 3,
            'lesson_hour_id' => $thirdHour->getKey(),
            'school_class_id' => $otherClass->getKey(),
            'user_id' => $this->teacher->getKey(),
            'subject' => 'Statistika',
        ])->assertRedirect();

        $this->signInAs($this->teacher);

        $this->withoutVite()
            ->get(route('teacher.index'))
            ->assertOk()
            ->assertSeeInOrder(['Jadwal Mengajar Seminggu', 'Rabu', 'Jam ke-3', 'XI AKL 1', 'Statistika'])
            ->assertDontSee('Matematika</td>', false)
            ->assertSee('data-live="teacher-week"', false)
            ->assertSee('data-live="teacher-substitute"', false);
    }

    public function test_teacher_recap_does_not_show_scans_from_outside_the_class(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $session = $this->openLessonSession($schedule);
        $outsider = User::factory()->student('XI AKL 1')->create(['name' => 'Siswa Kelas Lain']);

        AttendanceLog::create([
            'user_id' => $outsider->getKey(),
            'device_id' => 'ESP32-KELAS-01',
            'lesson_session_id' => $session->getKey(),
            'scanned_at' => now(),
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeIn,
            'source' => AttendanceLog::SourceClass,
        ]);

        $this->withoutVite()
            ->get(route('teacher.sessions.recap', $session))
            ->assertOk()
            ->assertDontSee('Scan di Luar Daftar Kelas')
            ->assertDontSee('Siswa Kelas Lain');
    }

    public function test_teacher_downloads_the_recap_of_every_class_he_teaches(): void
    {
        [$tkjSession, $aklSession, $foreignSession] = $this->recapExportFixture();

        $this->withoutVite()
            ->get(route('teacher.recaps'))
            ->assertOk()
            ->assertSee('Unduh CSV semua kelas')
            ->assertSee('Unduh CSV kelas ini');

        $response = $this->get(route('teacher.recaps.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('rekap-pak-dedi-semua-kelas-2026-09-14.csv');

        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Tanggal;Hari;Jam;Pukul;Kelas;"Mata Pelajaran";Guru;"Nama Siswa";"Nomor Induk";Status;"Jam Scan"', $content);
        $this->assertStringContainsString('"X TKJ 1";Matematika;"Pak Dedi";"Andi Pratama";25001;Hadir;07:05:00', $content);
        $this->assertStringContainsString('"X TKJ 1";Matematika;"Pak Dedi";"Bella Safira";25002;"Belum hadir";-', $content);
        $this->assertStringContainsString('"XI AKL 1";Matematika;"Pak Dedi";"Citra Lestari";26001;"Belum hadir"', $content);
        $this->assertStringNotContainsString('Siswa Guru Lain', $content);
    }

    public function test_teacher_downloads_the_recap_of_one_class_only(): void
    {
        $this->recapExportFixture();

        $content = $this->get(route('teacher.recaps.export', ['kelas' => $this->class->getKey()]))
            ->assertOk()
            ->assertDownload('rekap-pak-dedi-x-tkj-1-2026-09-14.csv')
            ->streamedContent();

        $this->assertStringContainsString('Andi Pratama', $content);
        $this->assertStringNotContainsString('Citra Lestari', $content);
    }

    public function test_teacher_cannot_download_a_class_he_never_taught(): void
    {
        $this->recapExportFixture();
        $foreignClass = SchoolClass::query()->where('name', 'XII TKR 1')->firstOrFail();

        $this->get(route('teacher.recaps.export', ['kelas' => $foreignClass->getKey()]))
            ->assertRedirect(route('teacher.recaps'))
            ->assertSessionHasErrors('kelas');
    }

    public function test_teacher_downloads_one_meeting_but_not_the_meeting_of_another_teacher(): void
    {
        [$tkjSession, , $foreignSession] = $this->recapExportFixture();

        $this->withoutVite()
            ->get(route('teacher.sessions.recap', $tkjSession))
            ->assertOk()
            ->assertSee(route('teacher.sessions.export', $tkjSession), false);

        $content = $this->get(route('teacher.sessions.export', $tkjSession))
            ->assertOk()
            ->assertDownload('rekap-x-tkj-1-matematika-2026-09-14.csv')
            ->streamedContent();

        $this->assertStringContainsString('Andi Pratama', $content);
        $this->assertStringNotContainsString('Citra Lestari', $content);

        $this->get(route('teacher.sessions.export', $foreignSession))
            ->assertRedirect(route('teacher.index'))
            ->assertSessionHasErrors('session');
    }

    /**
     * Pak Dedi mengajar X TKJ 1 (jam ke-1) dan XI AKL 1 (jam ke-2) hari Senin;
     * Bu Sari mengajar XII TKR 1. Andi dari X TKJ 1 sudah scan.
     *
     * @return array{0: LessonSession, 1: LessonSession, 2: LessonSession}
     */
    private function recapExportFixture(): array
    {
        $akl = SchoolClass::create(['name' => 'XI AKL 1']);
        $tkr = SchoolClass::create(['name' => 'XII TKR 1']);
        $otherTeacher = User::factory()->teacher('Bahasa Jawa')->create(['name' => 'Bu Sari']);

        $tkjSession = $this->openLessonSession($this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika'));
        $aklSession = $this->openLessonSession($this->lessonSchedule($akl, $this->teacher, $this->lessonHour(2), 1, 'Matematika'));
        $foreignSession = $this->openLessonSession($this->lessonSchedule($tkr, $otherTeacher, $this->lessonHour(3), 1, 'Bahasa Jawa'));

        $andi = User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama', 'identifier_number' => '25001']);
        User::factory()->student('X TKJ 1')->create(['name' => 'Bella Safira', 'identifier_number' => '25002']);
        User::factory()->student('XI AKL 1')->create(['name' => 'Citra Lestari', 'identifier_number' => '26001']);
        User::factory()->student('XII TKR 1')->create(['name' => 'Siswa Guru Lain']);

        AttendanceLog::create([
            'user_id' => $andi->getKey(),
            'device_id' => 'ESP32-KELAS-01',
            'lesson_session_id' => $tkjSession->getKey(),
            'scanned_at' => now(),
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeIn,
            'source' => AttendanceLog::SourceClass,
        ]);

        return [$tkjSession, $aklSession, $foreignSession];
    }

    public function test_teacher_opens_his_own_lesson_inside_the_hour(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');

        $this->post(route('teacher.sessions.store'), [
            'lesson_schedule_id' => $schedule->getKey(),
            'note' => 'Kelas penuh.',
        ])->assertRedirect(route('teacher.index'))
            ->assertSessionHas('success');

        $session = $schedule->sessions()->sole();

        $this->assertTrue($session->isOpen());
        $this->assertSame($this->teacher->getKey(), $session->opened_by);
        $this->assertSame('2026-09-14', $session->date->toDateString());
        $this->assertSame('Kelas penuh.', $session->note);
    }

    public function test_teacher_cannot_open_before_the_hour_starts(): void
    {
        // Pukul 06:30, sedangkan absen jam ke-1 baru boleh dibuka 06:50.
        $this->travelTo(Carbon::parse('2026-09-14 06:30:00'));

        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');

        $response = $this->post(route('teacher.sessions.store'), [
            'lesson_schedule_id' => $schedule->getKey(),
        ]);

        $response->assertRedirect(route('teacher.index'))
            ->assertSessionHas('warning', fn ($message): bool => str_contains((string) $message, 'dibuka pukul 06:50 sampai 07:45')
                && str_contains((string) $message, 'Sekarang 06:30'));

        $this->assertSame(0, $schedule->sessions()->count());
    }

    public function test_teacher_cannot_open_a_lesson_that_belongs_to_another_teacher(): void
    {
        $otherTeacher = User::factory()->teacher('Bahasa Jawa')->create(['name' => 'Bu Sari']);
        $schedule = $this->lessonSchedule($this->class, $otherTeacher, $this->lessonHour(1), 1, 'Bahasa Jawa');

        $response = $this->post(route('teacher.sessions.store'), [
            'lesson_schedule_id' => $schedule->getKey(),
        ]);

        $response->assertRedirect(route('teacher.index'))
            ->assertSessionHas('warning', fn ($message): bool => str_contains((string) $message, 'Jadwal ini bukan milik Anda'));

        $this->assertSame(0, $schedule->sessions()->count());
    }

    public function test_teacher_cannot_open_a_lesson_scheduled_for_another_day(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 2, 'Matematika');

        $response = $this->post(route('teacher.sessions.store'), [
            'lesson_schedule_id' => $schedule->getKey(),
        ]);

        $response->assertRedirect(route('teacher.index'))
            ->assertSessionHas('warning', fn ($message): bool => str_contains((string) $message, 'Jadwal ini untuk hari Selasa, hari ini Senin.'));

        $this->assertSame(0, $schedule->sessions()->count());
    }

    public function test_teacher_closes_his_own_meeting_but_not_the_meeting_of_another_teacher(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $session = $this->openLessonSession($schedule, '2026-09-14');

        $this->post(route('teacher.sessions.close', $session))
            ->assertRedirect(route('teacher.index'))
            ->assertSessionHas('success');

        $this->assertFalse($session->fresh()->isOpen());

        $otherTeacher = User::factory()->teacher('Bahasa Jawa')->create();
        $otherClass = SchoolClass::create(['name' => 'XI AKL 1']);
        $otherSchedule = $this->lessonSchedule($otherClass, $otherTeacher, $this->lessonHour(2), 1, 'Bahasa Jawa');
        $otherSession = $this->openLessonSession($otherSchedule, '2026-09-14');

        $this->post(route('teacher.sessions.close', $otherSession))
            ->assertRedirect(route('teacher.index'))
            ->assertSessionHasErrors(['session' => 'Pertemuan itu bukan milik Anda, jadi tidak bisa dibuka dari akun ini.']);

        $this->assertTrue($otherSession->fresh()->isOpen());
    }

    public function test_teacher_reads_the_recap_of_his_own_meeting_only(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $session = $this->openLessonSession($schedule, '2026-09-14');
        User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama']);

        $this->withoutVite()
            ->get(route('teacher.sessions.recap', $session))
            ->assertOk()
            ->assertSee('Rekap')
            ->assertSee('Andi Pratama')
            ->assertSee('Kehadiran Anda')
            ->assertSee('Scan Sidik Jari Anda');

        $otherTeacher = User::factory()->teacher('Bahasa Jawa')->create();
        $otherClass = SchoolClass::create(['name' => 'XI AKL 1']);
        $otherSession = $this->openLessonSession(
            $this->lessonSchedule($otherClass, $otherTeacher, $this->lessonHour(2), 1, 'Bahasa Jawa'),
            '2026-09-14',
        );

        $this->get(route('teacher.sessions.recap', $otherSession))
            ->assertRedirect(route('teacher.index'))
            ->assertSessionHasErrors('session');
    }

    public function test_teacher_sees_the_meeting_he_stands_in_for_and_can_close_it(): void
    {
        $otherTeacher = User::factory()->teacher('Bahasa Jawa')->create(['name' => 'Bu Sari']);
        $schedule = $this->lessonSchedule($this->class, $otherTeacher, $this->lessonHour(2), 1, 'Bahasa Jawa');

        $session = $this->openLessonSession($schedule, '2026-09-14', $this->teacher->getKey());

        $this->withoutVite()
            ->get(route('teacher.index'))
            ->assertOk()
            ->assertSee('Absen Sebagai Guru Pengganti')
            ->assertSee('Guru digantikan')
            ->assertSee('Bu Sari');

        $this->post(route('teacher.sessions.close', $session))
            ->assertRedirect(route('teacher.index'))
            ->assertSessionHas('success');

        $this->assertFalse($session->fresh()->isOpen());
    }

    public function test_teaching_history_only_lists_the_meetings_he_handled(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $this->openLessonSession($schedule, '2026-09-14');

        $otherTeacher = User::factory()->teacher('Bahasa Jawa')->create();
        $otherClass = SchoolClass::create(['name' => 'XI AKL 1']);
        $this->openLessonSession(
            $this->lessonSchedule($otherClass, $otherTeacher, $this->lessonHour(2), 4, 'Bahasa Jawa'),
            '2026-09-17',
        );

        $this->withoutVite()
            ->get(route('teacher.recaps'))
            ->assertOk()
            ->assertSee('Rekap Mengajar')
            ->assertSee('1 pertemuan')
            ->assertSee('Matematika')
            ->assertDontSee('Bahasa Jawa');
    }

    public function test_member_without_the_teacher_role_is_kept_out(): void
    {
        $this->signInAs(User::factory()->student('X TKJ 1')->create());

        $this->get(route('teacher.index'))
            ->assertRedirect(route('student.index'))
            ->assertSessionHasErrors('role');
    }
}
