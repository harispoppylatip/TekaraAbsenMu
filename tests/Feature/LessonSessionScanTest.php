<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\LessonHour;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sisi sesi jam pelajaran pada sensor kelas: sesi yang sudah ditutup, guru
 * pengganti, dan siswa yang kelasnya belum dibuka. Perilaku dasar sensor kelas
 * ada di AttendanceWindowTest.
 */
class LessonSessionScanTest extends TestCase
{
    use RefreshDatabase;

    private const ScanTime = '2026-09-14 12:00:00';

    public function test_scan_into_a_meeting_the_teacher_already_closed_is_refused(): void
    {
        $device = $this->classSensor('ESP32-TUTUP-01', 'X TKJ 1');
        $student = $this->studentOf('NIM-TUTUP-01', 'X TKJ 1');

        $this->travelTo(Carbon::parse(self::ScanTime));

        $session = $this->openLessonFor('X TKJ 1', $this->lessonHour(1));
        $session->update(['closed_at' => now()]);

        $this->scan($device, $student)
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Belum waktunya');

        $this->assertSame(0, $session->logs()->count());
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_teacher_standing_in_for_another_teacher_records_his_own_attendance(): void
    {
        $device = $this->classSensor('ESP32-GANTI-01', 'X TKJ 1');

        $class = SchoolClass::firstOrCreate(['name' => 'X TKJ 1']);
        $owner = $this->teacherOf('NIP-GANTI-01', 'Bahasa Jawa');
        $substitute = $this->teacherOf('NIP-GANTI-02', 'Matematika');

        $this->travelTo(Carbon::parse(self::ScanTime));

        $schedule = $this->lessonSchedule($class, $owner, $this->lessonHour(1), 1, 'Bahasa Jawa');

        $session = $this->openLessonSession($schedule, '2026-09-14', $substitute->getKey());

        $this->scan($device, $substitute)
            ->assertJsonPath('attendance_recorded', true)
            ->assertJsonPath('attendance_label', 'Hadir Kelas');

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $substitute->getKey(),
            'lesson_session_id' => $session->getKey(),
            'source' => AttendanceLog::SourceClass,
        ]);

        // Guru pengampu aslinya tidak lagi memegang pertemuan itu karena sudah
        // digantikan, jadi sensornya menolak dia.
        $this->scan($device, $owner)
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Di luar jadwal');

        $this->assertSame(1, $session->logs()->count());
    }

    public function test_student_waits_while_the_sensor_only_serves_another_class_meeting(): void
    {
        $device = $this->classSensor('ESP32-BEDA-01', 'X TKJ 1', 'XI AKL 1');
        $student = $this->studentOf('NIM-BEDA-01', 'X TKJ 1');
        $otherStudent = $this->studentOf('NIM-BEDA-02', 'XI AKL 1');

        $this->travelTo(Carbon::parse(self::ScanTime));

        // Yang dibuka hanya jam pelajaran XI AKL 1.
        $this->openLessonFor('XI AKL 1', $this->lessonHour(1));

        $response = $this->scan($device, $student)
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Belum dibuka');

        $this->assertStringContainsString(
            'Jam pelajaran kelas X TKJ 1 belum dibuka guru, yang sedang terbuka kelas XI AKL 1.',
            (string) $response->json('message'),
        );

        $this->scan($device, $otherStudent)
            ->assertJsonPath('attendance_recorded', true)
            ->assertJsonPath('attendance_label', 'Hadir Kelas');

        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_admin_does_not_use_a_class_sensor_to_mark_attendance(): void
    {
        $device = $this->classSensor('ESP32-ADMIN-01', 'X TKJ 1');
        $admin = User::factory()->admin()->create(['identifier_number' => 'ADM-KELAS-01']);

        $this->travelTo(Carbon::parse(self::ScanTime));

        $this->openLessonFor('X TKJ 1', $this->lessonHour(1));

        $this->scan($device, $admin)
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Tidak diizinkan');

        $this->assertSame(0, AttendanceLog::count());
    }

    private function scan(Device $device, User $user): TestResponse
    {
        return $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ])->assertOk();
    }

    /**
     * Sensor kelas: tidak melayani gerbang, hanya kelas yang dipilih.
     */
    private function classSensor(string $deviceId, string ...$classNames): Device
    {
        $device = Device::create([
            'device_id' => $deviceId,
            'name' => 'Sensor '.$deviceId,
            'location' => 'Ruang kelas',
            'status' => Device::StatusActive,
            'serves_gate' => false,
        ]);

        foreach ($classNames as $className) {
            $device->schoolClasses()->attach(SchoolClass::firstOrCreate(['name' => $className])->id);
        }

        return $device->refresh();
    }

    private function studentOf(string $identifierNumber, ?string $className): User
    {
        return User::factory()->student($className)->create([
            'name' => 'Siswa '.$identifierNumber,
            'identifier_number' => $identifierNumber,
        ]);
    }

    private function teacherOf(string $identifierNumber, string $subject): User
    {
        return User::factory()->teacher($subject)->create([
            'name' => 'Guru '.$identifierNumber,
            'identifier_number' => $identifierNumber,
        ]);
    }

    /**
     * Buka satu sesi absen untuk sebuah kelas pada waktu yang sedang dipakai
     * tes, sama seperti alur guru membuka absennya sendiri.
     */
    private function openLessonFor(string $className, LessonHour $lessonHour): LessonSession
    {
        $class = SchoolClass::firstOrCreate(['name' => $className]);
        $teacher = $this->teacherOf('NIP-'.$lessonHour->number.'-'.$class->getKey(), 'Matematika');

        return $this->openLessonSession(
            $this->lessonSchedule($class, $teacher, $lessonHour, Carbon::now()->dayOfWeekIso),
        );
    }
}
