<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\LessonSchedule;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonScheduleTest extends TestCase
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

    public function test_page_shows_the_form_and_the_registered_schedules(): void
    {
        $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');

        $this->withoutVite()
            ->get(route('schedules.index'))
            ->assertOk()
            ->assertSee('Jadwal Pelajaran')
            ->assertSee('X TKJ 1')
            ->assertSee('Matematika')
            ->assertSee('Pak Dedi')
            ->assertSee('Senin')
            ->assertSee('Jam ke-1');
    }

    public function test_selected_class_is_drawn_as_a_weekly_grid(): void
    {
        $hour = $this->lessonHour(1);
        $otherClass = SchoolClass::create(['name' => 'XI AKL 1']);
        $otherTeacher = User::factory()->teacher('Informatika')->create(['name' => 'Bu Sari']);

        $this->lessonSchedule($this->class, $this->teacher, $hour, 1, 'Matematika');
        $this->lessonSchedule($otherClass, $otherTeacher, $hour, 1, 'Informatika');

        $this->withoutVite()
            ->get(route('schedules.index', ['class' => $this->class->getKey()]))
            ->assertOk()
            ->assertSee('Kisi jadwal kelas')
            ->assertSee('Matematika')
            ->assertSee('Pak Dedi')
            // Jadwal kelas lain tidak ikut tampil di kisi maupun daftarnya.
            ->assertDontSee('Informatika');
    }

    public function test_admin_can_add_a_schedule(): void
    {
        $hour = $this->lessonHour(2);

        $this->post(route('schedules.store'), [
            'school_class_id' => $this->class->getKey(),
            'user_id' => $this->teacher->getKey(),
            'day' => 1,
            'lesson_hour_id' => $hour->getKey(),
            'subject' => '  Matematika   Dasar ',
        ])->assertRedirect(route('schedules.index', ['class' => $this->class->getKey()]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('lesson_schedules', [
            'school_class_id' => $this->class->getKey(),
            'user_id' => $this->teacher->getKey(),
            'day' => 1,
            'lesson_hour_id' => $hour->getKey(),
            'subject' => 'Matematika Dasar',
        ]);
    }

    public function test_a_class_cannot_have_two_lessons_in_the_same_hour(): void
    {
        $hour = $this->lessonHour(1);
        $this->lessonSchedule($this->class, $this->teacher, $hour, 2, 'Matematika');

        $anotherTeacher = User::factory()->teacher('Informatika')->create();

        $this->from(route('schedules.index'))
            ->post(route('schedules.store'), [
                'school_class_id' => $this->class->getKey(),
                'user_id' => $anotherTeacher->getKey(),
                'day' => 2,
                'lesson_hour_id' => $hour->getKey(),
                'subject' => 'Informatika',
            ])
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHasErrors('lesson_hour_id');

        $this->assertSame(1, LessonSchedule::count());
    }

    public function test_a_teacher_cannot_teach_two_classes_in_the_same_hour(): void
    {
        $hour = $this->lessonHour(1);
        $this->lessonSchedule($this->class, $this->teacher, $hour, 3, 'Matematika');

        $anotherClass = SchoolClass::create(['name' => 'XI AKL 1']);

        $this->from(route('schedules.index'))
            ->post(route('schedules.store'), [
                'school_class_id' => $anotherClass->getKey(),
                'user_id' => $this->teacher->getKey(),
                'day' => 3,
                'lesson_hour_id' => $hour->getKey(),
                'subject' => 'Matematika',
            ])
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHasErrors('lesson_hour_id');

        $this->assertSame(1, LessonSchedule::count());
    }

    public function test_same_hour_is_fine_for_another_class_with_another_teacher(): void
    {
        $hour = $this->lessonHour(1);
        $anotherClass = SchoolClass::create(['name' => 'XI AKL 1']);
        $anotherTeacher = User::factory()->teacher('Informatika')->create();

        $this->lessonSchedule($this->class, $this->teacher, $hour, 4, 'Matematika');

        $this->post(route('schedules.store'), [
            'school_class_id' => $anotherClass->getKey(),
            'user_id' => $anotherTeacher->getKey(),
            'day' => 4,
            'lesson_hour_id' => $hour->getKey(),
            'subject' => 'Informatika',
        ])->assertRedirect(route('schedules.index', ['class' => $anotherClass->getKey()]))
            ->assertSessionHas('success');

        $this->assertSame(2, LessonSchedule::count());
    }

    public function test_saturday_and_members_without_the_teacher_role_are_refused(): void
    {
        $hour = $this->lessonHour(1);
        $student = User::factory()->student('X TKJ 1')->create();

        $this->from(route('schedules.index'))
            ->post(route('schedules.store'), [
                'school_class_id' => $this->class->getKey(),
                'user_id' => $student->getKey(),
                'day' => 1,
                'lesson_hour_id' => $hour->getKey(),
                'subject' => 'Matematika',
            ])
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHasErrors('user_id');

        $this->from(route('schedules.index'))
            ->post(route('schedules.store'), [
                'school_class_id' => $this->class->getKey(),
                'user_id' => $this->teacher->getKey(),
                'day' => 6,
                'lesson_hour_id' => $hour->getKey(),
                'subject' => 'Matematika',
            ])
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHasErrors('day');

        $this->assertSame(0, LessonSchedule::count());
    }

    public function test_schedule_can_be_moved_to_another_hour(): void
    {
        $first = $this->lessonHour(1);
        $second = $this->lessonHour(2);
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $first, 1, 'Matematika');

        $this->put(route('schedules.update', $schedule), [
            'school_class_id' => $this->class->getKey(),
            'user_id' => $this->teacher->getKey(),
            'day' => 2,
            'lesson_hour_id' => $second->getKey(),
            'subject' => 'Matematika Lanjutan',
        ])->assertRedirect(route('schedules.index', ['class' => $this->class->getKey()]))
            ->assertSessionHas('success');

        $schedule->refresh();

        $this->assertSame(2, $schedule->day);
        $this->assertSame($second->getKey(), $schedule->lesson_hour_id);
        $this->assertSame('Matematika Lanjutan', $schedule->subject);
    }

    public function test_saving_the_same_schedule_reports_that_nothing_changed(): void
    {
        $hour = $this->lessonHour(1);
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $hour, 1, 'Matematika');

        $this->put(route('schedules.update', $schedule), [
            'school_class_id' => $this->class->getKey(),
            'user_id' => $this->teacher->getKey(),
            'day' => 1,
            'lesson_hour_id' => $hour->getKey(),
            'subject' => 'Matematika',
        ])->assertRedirect(route('schedules.index', ['class' => $this->class->getKey()]))
            ->assertSessionHas('warning', 'Jadwal ini masih sama, tidak ada yang diperbarui.');
    }

    public function test_deleting_a_schedule_keeps_the_attendance_logs_of_its_meetings(): void
    {
        $hour = $this->lessonHour(1);
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $hour, 1, 'Matematika');
        $session = $this->openLessonSession($schedule);

        $student = User::factory()->student('X TKJ 1')->create();
        $sensor = Device::create([
            'device_id' => 'ESP32-JADWAL-01',
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

        $this->delete(route('schedules.destroy', $schedule))
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHas('warning');

        $this->assertDatabaseMissing('lesson_schedules', ['id' => $schedule->getKey()]);
        $this->assertDatabaseMissing('lesson_sessions', ['id' => $session->getKey()]);

        // Catatan presensinya tetap ada, hanya tautan jam pelajarannya dikosongkan.
        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log->getKey(),
            'lesson_session_id' => null,
        ]);
    }
}
