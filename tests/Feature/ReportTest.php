<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // Rabu, 16 September 2026. Senin di rentang 7 sampai 16 September
        // jatuh pada tanggal 7 dan 14.
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00'));

        $this->class = SchoolClass::create(['name' => 'X TKJ 1']);
        $this->teacher = User::factory()->teacher('Matematika')->create(['name' => 'Pak Dedi']);
    }

    public function test_guests_and_non_admins_cannot_open_the_report(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));

        $this->signInAs($this->teacher);

        $this->get(route('reports.index'))->assertRedirect(route('teacher.index'));
        $this->get(route('reports.export'))->assertRedirect(route('teacher.index'));
    }

    public function test_navigation_links_to_the_report_page(): void
    {
        $this->signInAsAdmin();

        $this->withoutVite()
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('reports.index'), false);
    }

    public function test_student_report_counts_only_opened_meetings_of_the_students_class(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $first = $this->openLessonSession($schedule, '2026-09-07');
        $second = $this->openLessonSession($schedule, '2026-09-14');
        $outside = $this->openLessonSession($schedule, '2026-08-31');

        $andi = User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama']);
        $bella = User::factory()->student('x tkj 1')->create(['name' => 'Bella Safira']);
        $citra = User::factory()->student('XI AKL 1')->create(['name' => 'Citra Lestari']);

        $this->scanIn($first, $andi);
        $this->scanIn($second, $andi);
        $this->scanIn($first, $bella);
        $this->scanIn($outside, $bella);

        $this->signInAsAdmin();

        $this->withoutVite()
            ->get(route('reports.index', ['jenis' => 'siswa', 'dari' => '2026-09-07', 'sampai' => '2026-09-16']))
            ->assertOk()
            ->assertSee('Kehadiran Siswa')
            ->assertSee('07/09/2026 sampai 16/09/2026')
            ->assertSeeInOrder(['Andi Pratama', 'X TKJ 1', '2', '2', '0', '100%'])
            ->assertSeeInOrder(['Bella Safira', 'x tkj 1', '2', '1', '1', '50%'])
            ->assertSeeInOrder(['Citra Lestari', 'XI AKL 1', '0', '0', '0', '-']);
    }

    public function test_student_report_can_be_narrowed_to_one_class(): void
    {
        User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama']);
        User::factory()->student('XI AKL 1')->create(['name' => 'Citra Lestari']);

        $this->signInAsAdmin();

        $this->withoutVite()
            ->get(route('reports.index', ['kelas' => $this->class->getKey()]))
            ->assertOk()
            ->assertSee('Andi Pratama')
            ->assertDontSee('Citra Lestari');
    }

    public function test_teacher_report_shows_teacher_scans_and_schedules_without_a_session(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $session = $this->openLessonSession($schedule, '2026-09-14');

        $andi = User::factory()->student('X TKJ 1')->create();
        User::factory()->student('X TKJ 1')->create();

        $this->scanIn($session, $this->teacher);
        $this->scanIn($session, $andi);

        $this->signInAsAdmin();

        $response = $this->withoutVite()
            ->get(route('reports.index', ['jenis' => 'guru', 'dari' => '2026-09-07', 'sampai' => '2026-09-16']))
            ->assertOk()
            ->assertSee('Kehadiran Guru Mengajar')
            ->assertSee('Pak Dedi')
            ->assertSee('1 dari 1 pertemuan')
            // Sel tabel "Siswa hadir" boleh terpotong baris di berkas blade, jadi
            // dicocokkan sebagai teks halaman yang spasi putihnya sudah dirapikan.
            ->assertSeeText('1 dari 2')
            ->assertSee('50%');

        $row = $response->viewData('report')['rows']->first();

        $this->assertSame(1, $row['meetings']);
        $this->assertSame(1, $row['scanned']);
        $this->assertSame(1, $row['missed'], 'Senin 7 September tidak pernah dibuka absennya.');
        $this->assertSame(1, $row['studentsPresent']);
        $this->assertSame(2, $row['studentsExpected']);
    }

    public function test_gate_report_counts_each_day_once(): void
    {
        $andi = User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama', 'status' => User::StatusActive]);

        $this->gateScan($andi, '2026-09-14 06:50:00');
        $this->gateScan($andi, '2026-09-14 06:52:00');
        $this->gateScan($andi, '2026-09-14 14:00:00', AttendanceLog::TypeOut);
        $this->gateScan($andi, '2026-09-15 08:20:00', status: AttendanceLog::StatusLate);

        $this->signInAsAdmin();

        $response = $this->withoutVite()
            ->get(route('reports.index', ['jenis' => 'gerbang', 'dari' => '2026-09-14', 'sampai' => '2026-09-16']))
            ->assertOk()
            ->assertSee('Absen Gerbang')
            ->assertSee('Masuk')
            ->assertSee('Pulang')
            ->assertSee('2 dari 3');

        $row = $response->viewData('report')['rows']->firstWhere('user.id', $andi->getKey());

        $this->assertSame(3, $row['schoolDays']);
        $this->assertSame(2, $row['days']);
        $this->assertSame(1, $row['onTime']);
        $this->assertSame(1, $row['late']);
        $this->assertSame(1, $row['checkOut']);
        $this->assertSame(1, $row['absent']);
        $this->assertSame(67, $row['rate']);
    }

    public function test_report_can_be_downloaded_as_csv_for_excel(): void
    {
        $schedule = $this->lessonSchedule($this->class, $this->teacher, $this->lessonHour(1), 1, 'Matematika');
        $session = $this->openLessonSession($schedule, '2026-09-14');
        $andi = User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama', 'identifier_number' => '25001']);
        $this->scanIn($session, $andi);

        $this->signInAsAdmin();

        $response = $this->get(route('reports.export', [
            'jenis' => 'siswa',
            'dari' => '2026-09-01',
            'sampai' => '2026-09-16',
            'kelas' => $this->class->getKey(),
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('laporan-siswa-2026-09-01-sampai-2026-09-16-x-tkj-1.csv');

        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Nama;"Nomor Induk";Kelas;Pertemuan;Hadir;"Tidak Hadir";Kehadiran', $content);
        $this->assertStringContainsString('"Andi Pratama";25001;"X TKJ 1";1;1;0;100%', $content);
    }

    public function test_reversed_or_invalid_dates_fall_back_without_an_error(): void
    {
        $this->signInAsAdmin();

        $this->withoutVite()
            ->get(route('reports.index', ['dari' => '2026-09-16', 'sampai' => '2026-09-01']))
            ->assertOk()
            ->assertSee('Tanggal awal lebih akhir dari tanggal akhir, jadi keduanya ditukar.')
            ->assertSee('01/09/2026 sampai 16/09/2026');

        $this->withoutVite()
            ->get(route('reports.index', ['dari' => '2026-02-31', 'sampai' => 'kemarin', 'jenis' => 'asal']))
            ->assertOk()
            ->assertSee('Kehadiran Siswa')
            ->assertSee('01/09/2026 sampai 16/09/2026');
    }

    private function scanIn(LessonSession $session, User $user): AttendanceLog
    {
        return AttendanceLog::create([
            'user_id' => $user->getKey(),
            'device_id' => 'ESP32-KELAS-01',
            'lesson_session_id' => $session->getKey(),
            'scanned_at' => $session->date->copy()->setTime(7, 5),
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeIn,
            'source' => AttendanceLog::SourceClass,
        ]);
    }

    private function gateScan(
        User $user,
        string $scannedAt,
        string $type = AttendanceLog::TypeIn,
        string $status = AttendanceLog::StatusPresent,
    ): AttendanceLog {
        return AttendanceLog::create([
            'user_id' => $user->getKey(),
            'device_id' => 'ESP32-GERBANG-01',
            'scanned_at' => Carbon::parse($scannedAt),
            'status' => $status,
            'type' => $type,
            'source' => AttendanceLog::SourceGate,
        ]);
    }
}
