<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudentPortalTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // Senin, 14 September 2026.
        $this->travelTo(Carbon::parse('2026-09-14 07:05:00'));

        $this->class = SchoolClass::create(['name' => 'X TKJ 1']);
        $this->teacher = User::factory()->teacher('Matematika')->create(['name' => 'Pak Dedi']);
    }

    public function test_student_sees_the_schedule_of_his_class(): void
    {
        $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');

        $student = User::factory()->student('x tkj 1')->create(['name' => 'Andi Pratama']);

        $this->signInAs($student);

        $this->withoutVite()
            ->get(route('student.index'))
            ->assertOk()
            ->assertSee('Jadwal Pelajaran Saya')
            ->assertSee('Andi Pratama')
            ->assertSee('X TKJ 1')
            ->assertSee('Jadwal Pelajaran Hari Ini')
            ->assertSee('Jam ke-1')
            ->assertSee('Matematika')
            ->assertSee('Pak Dedi')
            ->assertSee('Jadwal Seminggu')
            ->assertSee('Kehadiran Saya');
    }

    public function test_student_without_a_class_is_asked_to_contact_the_admin(): void
    {
        $student = User::factory()->student()->create(['name' => 'Bella Safira']);

        $this->assertNull($student->class_name);

        $this->signInAs($student);

        $this->withoutVite()
            ->get(route('student.index'))
            ->assertOk()
            ->assertSee('Kelas Belum Ditetapkan')
            ->assertSee('Akun Anda belum masuk kelas mana pun')
            ->assertDontSee('Jadwal Pelajaran Hari Ini')
            ->assertDontSee('Kehadiran Saya');
    }

    public function test_todays_lesson_waits_until_the_teacher_opens_the_meeting(): void
    {
        $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');

        $this->signInAs(User::factory()->student('X TKJ 1')->create());

        $this->withoutVite()
            ->get(route('student.index'))
            ->assertOk()
            ->assertSee('Absen belum dibuka')
            ->assertSee('Tunggu guru membuka absen di kelas ini.');
    }

    public function test_a_meeting_of_another_class_is_not_shown(): void
    {
        $otherClass = SchoolClass::create(['name' => 'XI AKL 1']);
        $otherTeacher = User::factory()->teacher('Bahasa Jawa')->create(['name' => 'Bu Sari']);

        $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $otherSchedule = $this->lessonSchedule($otherClass, $otherTeacher, $this->lessonHour(2), 1, 'Bahasa Jawa');
        $this->openLessonSession($otherSchedule, '2026-09-14');

        $this->signInAs(User::factory()->student('X TKJ 1')->create());

        $this->withoutVite()
            ->get(route('student.index'))
            ->assertOk()
            ->assertSee('Matematika')
            ->assertDontSee('Bahasa Jawa')
            ->assertDontSee('XI AKL 1');
    }

    public function test_record_counts_only_his_own_scan(): void
    {
        $hourOne = $this->lessonHour(1);
        $hourTwo = $this->lessonHour(2);

        $first = $this->lessonSchedule($this->class, $this->teacher, $hourOne, 1, 'Matematika');
        $second = $this->lessonSchedule($this->class, $this->teacher, $hourTwo, 1, 'Bahasa Jawa');

        $sessionOne = $this->openLessonSession($first, '2026-09-14');
        $sessionTwo = $this->openLessonSession($second, '2026-09-14');

        $student = User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama']);
        $classmate = User::factory()->student('X TKJ 1')->create(['name' => 'Bella Safira']);

        // Andi hanya scan di jam ke-1, sedangkan teman sekelasnya scan di jam
        // ke-2. Catatan teman sekelas tidak boleh ikut dihitung sebagai hadir.
        $this->scanIn($sessionOne, $student, 'ESP32-SISWA-01');
        $this->scanIn($sessionTwo, $classmate, 'ESP32-SISWA-01');

        $this->signInAs($student);

        $response = $this->withoutVite()->get(route('student.index'))->assertOk();

        $response->assertSee('Sudah absen')
            ->assertSee('1 hadir dari')
            ->assertSee('2 pertemuan');

        $content = $response->getContent();

        $this->assertSame(1, substr_count($content, 'chip-success">Hadir'));
        $this->assertSame(1, substr_count($content, 'chip-danger">Belum hadir'));
    }

    public function test_student_cannot_reach_the_teacher_or_admin_pages(): void
    {
        $this->signInAs(User::factory()->student('X TKJ 1')->create());

        $this->get(route('teacher.index'))
            ->assertRedirect(route('student.index'))
            ->assertSessionHasErrors('role');

        $this->get(route('dashboard'))
            ->assertRedirect(route('student.index'))
            ->assertSessionHasErrors('role');

        $this->followingRedirects()
            ->get(route('teacher.index'))
            ->assertOk()
            ->assertSee('Halaman itu untuk Guru. Anda masuk sebagai Siswa.');
    }

    public function test_student_sees_only_his_gate_logs_with_date_filter(): void
    {
        $student = User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama']);
        $otherStudent = User::factory()->student('X TKJ 1')->create(['name' => 'Bella Safira']);
        $device = Device::create([
            'device_id' => 'ESP32-GATE-01',
            'name' => 'Gerbang Utama',
            'status' => Device::StatusActive,
            'serves_gate' => true,
        ]);

        AttendanceLog::create([
            'user_id' => $student->getKey(),
            'device_id' => $device->device_id,
            'scanned_at' => '2026-09-14 07:10:00',
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeIn,
            'source' => AttendanceLog::SourceGate,
        ]);
        AttendanceLog::create([
            'user_id' => $student->getKey(),
            'device_id' => $device->device_id,
            'scanned_at' => '2026-09-15 15:30:00',
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeOut,
            'source' => AttendanceLog::SourceGate,
        ]);
        AttendanceLog::create([
            'user_id' => $otherStudent->getKey(),
            'device_id' => $device->device_id,
            'scanned_at' => '2026-09-14 07:15:00',
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeIn,
            'source' => AttendanceLog::SourceGate,
        ]);

        $this->signInAs($student);

        $this->withoutVite()
            ->get(route('student.gate-attendance', ['dari' => '2026-09-14', 'sampai' => '2026-09-14']))
            ->assertOk()
            ->assertSee('Datang dan Pulang')
            ->assertSee('Gerbang Utama')
            ->assertSee('07:10:00')
            ->assertDontSee('15:30:00')
            ->assertDontSee('Bella Safira');
    }

    public function test_gate_attendance_page_highlights_only_its_menu(): void
    {
        $student = User::factory()->student('X TKJ 1')->create();

        $this->signInAs($student);

        $content = $this->withoutVite()
            ->get(route('student.gate-attendance'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($content, 'nav-link active'));
        $this->assertStringContainsString('>Datang dan Pulang</span>', $content);
        $this->assertStringContainsString('>Akademik</p>', $content);
    }

    public function test_gate_history_names_the_columns_and_does_not_repeat_pulang(): void
    {
        $student = User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama']);
        $device = Device::create([
            'device_id' => 'ESP32-GATE-01',
            'name' => 'Gerbang Utama',
            'status' => Device::StatusActive,
            'serves_gate' => true,
        ]);

        AttendanceLog::create([
            'user_id' => $student->getKey(),
            'device_id' => $device->device_id,
            'scanned_at' => '2026-09-14 15:30:00',
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeOut,
            'source' => AttendanceLog::SourceGate,
        ]);

        $this->signInAs($student);

        $content = $this->withoutVite()
            ->get(route('student.gate-attendance', ['dari' => '2026-09-14', 'sampai' => '2026-09-14']))
            ->assertOk()
            ->assertSee('Jenis Absen')
            ->assertSee('Keterangan')
            ->assertSee('Tercatat')
            ->assertSee('Belum ada scan masuk')
            ->getContent();

        // Kolom jenis absen dan kolom keterangan tidak boleh sama-sama berbunyi
        // "Pulang", karena siswa membacanya seperti catatan yang tertulis dua kali.
        $this->assertSame(1, substr_count($content, 'chip-info">Pulang'));
        $this->assertSame(1, substr_count($content, 'chip-info">Tercatat'));
    }

    public function test_schedule_page_points_students_to_the_gate_history(): void
    {
        $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');

        $this->signInAs(User::factory()->student('X TKJ 1')->create());

        $this->withoutVite()
            ->get(route('student.index'))
            ->assertOk()
            ->assertSee('Catatan datang dan pulang dari sensor gerbang')
            ->assertSee('href="'.route('student.gate-attendance').'">Datang dan Pulang</a>', false);
    }

    private function scanIn(LessonSession $session, User $student, string $deviceId): AttendanceLog
    {
        $sensor = Device::firstOrCreate(
            ['device_id' => $deviceId],
            [
                'name' => 'Sensor kelas '.$deviceId,
                'location' => 'Ruang kelas',
                'status' => Device::StatusActive,
                'serves_gate' => false,
            ],
        );

        return AttendanceLog::create([
            'user_id' => $student->getKey(),
            'device_id' => $sensor->getKey(),
            'lesson_session_id' => $session->getKey(),
            'scanned_at' => now(),
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeIn,
            'source' => AttendanceLog::SourceClass,
        ]);
    }
}
