<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\LessonSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LessonSessionTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();

        $this->class = SchoolClass::create(['name' => 'X TKJ 1']);
        $this->teacher = User::factory()->teacher('Matematika')->create(['name' => 'Pak Dedi']);
    }

    public function test_page_shows_the_meetings_of_the_selected_date(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $this->openLessonSession($schedule, '2026-09-14');

        $this->withoutVite()
            ->get(route('sessions.index', ['date' => '2026-09-14']))
            ->assertOk()
            ->assertSee('Sesi Absen')
            ->assertSee('Sesi 14/09/2026')
            ->assertSee('Buka Absen')
            ->assertSee('Cari Sesi')
            ->assertSee('Matematika')
            ->assertSee('X TKJ 1');
    }

    public function test_page_lists_weekday_schedules_before_their_session_is_created(): void
    {
        $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 3, 'IPAS');

        $this->withoutVite()
            ->get(route('sessions.index', ['date' => '2026-09-16']))
            ->assertOk()
            ->assertSee('IPAS')
            ->assertSee('Pak Dedi')
            ->assertSee('Belum dibuka')
            ->assertSee('Jadwalkan');
    }

    public function test_page_filters_the_meetings_by_status(): void
    {
        $openSchedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $closedSchedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(2), 1, 'Bahasa Jawa');

        $this->openLessonSession($openSchedule, '2026-09-14')
            ->update(['opened_at' => '2026-09-14 07:05:00']);

        $this->openLessonSession($closedSchedule, '2026-09-14')
            ->update(['opened_at' => '2026-09-14 08:05:00', 'closed_at' => '2026-09-14 08:50:00']);

        // Waktu buka tiap sesi dipakai sebagai penanda baris mana yang muncul,
        // sebab daftar jadwal di form Buka Absen memuat semua nama pelajaran.
        $this->withoutVite()
            ->get(route('sessions.index', ['date' => '2026-09-14', 'status' => 'closed']))
            ->assertOk()
            ->assertSee('dibuka 08:05')
            ->assertDontSee('dibuka 07:05');

        $this->withoutVite()
            ->get(route('sessions.index', ['date' => '2026-09-14', 'status' => 'open']))
            ->assertOk()
            ->assertSee('dibuka 07:05')
            ->assertDontSee('dibuka 08:05');
    }

    public function test_a_broken_date_filter_falls_back_to_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00'));

        $this->withoutVite()
            ->get(route('sessions.index', ['date' => 'besok']))
            ->assertOk()
            ->assertSee('Sesi 14/09/2026');

        Carbon::setTestNow();
    }

    public function test_admin_can_open_a_meeting_for_any_date(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');

        $this->post(route('sessions.store'), [
            'lesson_schedule_id' => $schedule->getKey(),
            'date' => '2026-09-14',
        ])->assertRedirect(route('sessions.index', ['date' => '2026-09-14']))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('lesson_sessions', 1);

        $session = LessonSession::query()->sole();

        $this->assertSame($schedule->getKey(), $session->lesson_schedule_id);
        $this->assertSame('2026-09-14', $session->date->toDateString());
        $this->assertTrue($session->isOpen());
    }

    public function test_a_session_without_substitute_belongs_to_the_schedule_teacher_and_can_be_closed_by_him(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 08:10:00'));
        $schedule = $this->lessonSchedule(
            $this->class,
            $this->teacher,
            $this->lessonHour(1, '08:00', '08:45'),
            1,
            'Matematika',
        );

        $this->post(route('sessions.store'), [
            'lesson_schedule_id' => $schedule->getKey(),
            'date' => '2026-09-14',
        ])->assertSessionHas('success');

        $session = LessonSession::query()->sole();
        $this->assertSame($this->teacher->getKey(), $session->teacherUserId());
        $this->assertFalse($session->isSubstituted());

        $this->signInAs($this->teacher);
        $this->post(route('teacher.sessions.close', $session))
            ->assertRedirect(route('teacher.index'))
            ->assertSessionHas('success');

        $this->assertFalse($session->fresh()->isOpen());
        Carbon::setTestNow();
    }

    public function test_admin_schedules_a_future_session_and_it_opens_at_the_lesson_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00'));
        $schedule = $this->lessonSchedule(
            $this->class,
            $this->teacher,
            $this->lessonHour(7, '13:00', '13:45'),
            3,
            'IPAS',
        );

        $this->post(route('sessions.store'), [
            'lesson_schedule_id' => $schedule->getKey(),
            'date' => '2026-10-07',
        ])->assertRedirect(route('sessions.index', ['date' => '2026-10-07']))
            ->assertSessionHas('success', fn ($message): bool => str_contains((string) $message, 'dijadwalkan'));

        $session = LessonSession::query()->sole();
        $this->assertTrue($session->isScheduled());
        $this->assertFalse($session->isOpen());
        $this->assertSame('2026-10-07 13:00:00', $session->scheduled_at->toDateTimeString());

        $activated = app(LessonSessionService::class)->activateScheduledSessions(
            Carbon::parse('2026-10-07 13:00:00'),
        );

        $this->assertSame(1, $activated);
        $this->assertTrue($session->fresh()->isOpen());
        $this->assertNull($session->fresh()->scheduled_at);
        Carbon::setTestNow();
    }

    public function test_schedule_teacher_can_start_a_pre_scheduled_session_at_the_lesson_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 08:10:00'));
        $schedule = $this->lessonSchedule(
            $this->class,
            $this->teacher,
            $this->lessonHour(1, '08:00', '08:45'),
            1,
            'Matematika',
        );
        $session = LessonSession::create([
            'lesson_schedule_id' => $schedule->getKey(),
            'date' => '2026-09-14',
            'scheduled_at' => '2026-09-14 08:00:00',
        ]);

        $this->signInAs($this->teacher);
        $this->post(route('teacher.sessions.store'), [
            'lesson_schedule_id' => $schedule->getKey(),
        ])->assertRedirect(route('teacher.index'))
            ->assertSessionHas('success');

        $started = $session->fresh();
        $this->assertTrue($started->isOpen());
        $this->assertSame($this->teacher->getKey(), $started->opened_by);
        $this->assertNull($started->scheduled_at);
        Carbon::setTestNow();
    }

    public function test_admin_can_point_a_substitute_teacher_but_only_a_teacher(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $substitute = User::factory()->teacher('Fisika')->create(['name' => 'Bu Sari']);
        $student = User::factory()->student('X TKJ 1')->create();

        $this->from(route('sessions.index'))
            ->post(route('sessions.store'), [
                'lesson_schedule_id' => $schedule->getKey(),
                'date' => '2026-09-14',
                'substitute_user_id' => $student->getKey(),
            ])
            ->assertRedirect(route('sessions.index'))
            ->assertSessionHasErrors('substitute_user_id');

        $this->post(route('sessions.store'), [
            'lesson_schedule_id' => $schedule->getKey(),
            'date' => '2026-09-14',
            'substitute_user_id' => $substitute->getKey(),
            'note' => 'Guru utama sedang dinas luar.',
        ])->assertRedirect(route('sessions.index', ['date' => '2026-09-14']))
            ->assertSessionHas('success');

        $session = LessonSession::query()->sole();

        $this->assertSame($substitute->getKey(), $session->substitute_user_id);
        $this->assertSame('Guru utama sedang dinas luar.', $session->note);
        $this->assertSame(auth()->id(), $session->opened_by);
    }

    public function test_opening_the_same_meeting_twice_is_refused(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $this->post(route('sessions.store'), [
            'lesson_schedule_id' => $schedule->getKey(),
            'date' => '2026-09-14',
        ]);

        $this->from(route('sessions.index'))
            ->post(route('sessions.store'), [
                'lesson_schedule_id' => $schedule->getKey(),
                'date' => '2026-09-14',
            ])
            ->assertRedirect(route('sessions.index', ['date' => '2026-09-14']))
            ->assertSessionHasErrors('session');

        $this->assertSame(1, LessonSession::count());
    }

    public function test_admin_can_close_and_reopen_a_meeting(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $session = $this->openLessonSession($schedule, '2026-09-14');

        $this->post(route('sessions.close', $session))
            ->assertRedirect(route('sessions.index', ['date' => '2026-09-14']))
            ->assertSessionHas('success');

        $this->assertNotNull($session->fresh()->closed_at);

        // Menutup sesi yang sudah tertutup hanya memberi peringatan.
        $this->post(route('sessions.close', $session))
            ->assertSessionHas('warning');

        // Sesi yang masih terbuka juga tidak perlu dibuka ulang.
        $this->post(route('sessions.reopen', $session))
            ->assertRedirect(route('sessions.index', ['date' => '2026-09-14']))
            ->assertSessionHas('success');

        $this->assertNull($session->fresh()->closed_at);

        $this->post(route('sessions.reopen', $session))
            ->assertSessionHas('warning');

        $this->assertNull($session->fresh()->closed_at);
    }

    public function test_a_meeting_note_can_be_corrected(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $session = $this->openLessonSession($schedule, '2026-09-14');

        $this->put(route('sessions.update', $session), ['note' => 'Kelas digabung dengan XI TKJ 1.'])
            ->assertRedirect(route('sessions.index', ['date' => '2026-09-14']))
            ->assertSessionHas('success');

        $this->assertSame('Kelas digabung dengan XI TKJ 1.', $session->fresh()->note);

        $this->put(route('sessions.update', $session), ['note' => 'Kelas digabung dengan XI TKJ 1.'])
            ->assertSessionHas('warning');
    }

    public function test_recap_lists_who_already_scanned(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $session = $this->openLessonSession($schedule, '2026-09-14');

        $present = User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama']);
        User::factory()->student('X TKJ 1')->create(['name' => 'Bella Safira']);
        User::factory()->student('X TKJ 1')->count(1)->create();

        $sensor = Device::create([
            'device_id' => 'ESP32-SESI-01',
            'name' => 'Sensor X TKJ 1',
            'location' => 'Ruang kelas',
            'status' => Device::StatusActive,
            'serves_gate' => false,
        ]);

        AttendanceLog::create([
            'user_id' => $present->getKey(),
            'device_id' => $sensor->getKey(),
            'lesson_session_id' => $session->getKey(),
            'scanned_at' => now(),
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeIn,
            'source' => AttendanceLog::SourceClass,
        ]);

        $this->withoutVite()
            ->get(route('sessions.show', $session))
            ->assertOk()
            ->assertSee('1 dari')
            ->assertSee('3 siswa hadir')
            ->assertSee('Andi Pratama')
            ->assertSee('Bella Safira')
            ->assertSee('Scan Guru Pengajar');
    }

    public function test_deleting_a_meeting_keeps_the_attendance_logs(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $session = $this->openLessonSession($schedule, '2026-09-14');

        $student = User::factory()->student('X TKJ 1')->create();

        $sensor = Device::create([
            'device_id' => 'ESP32-SESI-02',
            'name' => 'Sensor X TKJ 1',
            'location' => 'Ruang kelas',
            'status' => Device::StatusActive,
            'serves_gate' => false,
        ]);

        $log = AttendanceLog::create([
            'user_id' => $student->getKey(),
            'device_id' => $sensor->getKey(),
            'lesson_session_id' => $session->getKey(),
            'scanned_at' => now(),
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeIn,
            'source' => AttendanceLog::SourceClass,
        ]);

        $this->delete(route('sessions.destroy', $session))
            ->assertRedirect(route('sessions.index', ['date' => '2026-09-14']))
            ->assertSessionHas('warning');

        $this->assertDatabaseMissing('lesson_sessions', ['id' => $session->getKey()]);
        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log->getKey(),
            'lesson_session_id' => null,
        ]);
        $this->assertSame(0, LessonSession::count());
    }
}
