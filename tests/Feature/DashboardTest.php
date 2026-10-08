<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_attendance_from_todays_lessons(): void
    {
        // Rabu, 16 September 2026.
        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));

        $tkj = SchoolClass::create(['name' => 'X TKJ 1']);
        $akl = SchoolClass::create(['name' => 'XI AKL 1']);
        $teacher = User::factory()->teacher('Matematika')->create(['name' => 'Pak Dedi']);

        $opened = $this->lessonSchedule($tkj, $teacher, $this->lessonHour(1), 3, 'Matematika');
        $this->lessonSchedule($akl, $teacher, $this->lessonHour(2), 3, 'Informatika');
        $this->lessonSchedule($tkj, $teacher, $this->lessonHour(3), 1, 'Hanya Senin');

        $session = $this->openLessonSession($opened);

        $andi = User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama']);
        User::factory()->student('x tkj 1')->create();
        User::factory()->student('XI AKL 1')->create();

        foreach ([$andi, $teacher] as $user) {
            AttendanceLog::create([
                'user_id' => $user->getKey(),
                'device_id' => 'ESP32-KELAS-01',
                'lesson_session_id' => $session->getKey(),
                'scanned_at' => now(),
                'status' => AttendanceLog::StatusPresent,
                'type' => AttendanceLog::TypeIn,
                'source' => AttendanceLog::SourceClass,
            ]);
        }

        $this->signInAsAdmin();

        $response = $this->withoutVite()
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Jam Pelajaran Hari Ini')
            ->assertSeeInOrder(['Jam ke-1', 'X TKJ 1', 'Matematika', 'Absen dibuka', '1 dari 2'])
            ->assertSeeInOrder(['Jam ke-2', 'XI AKL 1', 'Informatika', 'Belum dibuka', '0 dari 1'])
            ->assertDontSee('Hanya Senin')
            ->assertSee('Andi Pratama');

        $stats = $response->viewData('stats');

        $this->assertSame(2, $stats['lessons']);
        $this->assertSame(1, $stats['opened']);
        $this->assertSame(1, $stats['open']);
        $this->assertSame(1, $stats['studentsPresent'], 'Scan guru tidak dihitung sebagai siswa hadir.');
        $this->assertSame(3, $stats['students']);
        $this->assertSame(4, $response->viewData('withoutFingerprint'));
    }
}
