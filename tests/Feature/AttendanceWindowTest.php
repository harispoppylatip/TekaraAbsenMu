<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceWindow;
use App\Models\Device;
use App\Models\Fingerprint;
use App\Models\LessonHour;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttendanceScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceWindowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Halaman presensi dan pengaturan jendela absen hanya terbuka untuk yang
     * sudah masuk, jadi setiap tes di sini dimulai sebagai admin.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();
    }

    public function test_attendance_page_lists_the_configured_windows_and_device_services(): void
    {
        $this->pairedDevice('ESP32-ABSEN-01');

        $response = $this->withoutVite()->get(route('attendance.index'))->assertOk();

        $response->assertSee('Jam Presensi')
            ->assertSee('Perangkat Presensi')
            ->assertSee('Kelas yang dilayani')
            ->assertSee('ESP32-ABSEN-01')
            ->assertSee('06:00')
            ->assertSee('07:30')
            ->assertSee('15:00')
            ->assertSee('sensor di gerbang sekolah')
            ->assertSee(route('devices.index'))
            ->assertDontSee('Tambah Perangkat')
            ->assertDontSee('Atur layanan')
            ->assertDontSee('Untaut');

        $this->assertCount(3, $response->viewData('windows'));
        $this->assertCount(1, $response->viewData('devices'));
    }

    public function test_device_that_is_not_registered_yet_is_flagged_on_the_attendance_page(): void
    {
        $device = Device::create(['device_id' => 'ESP32-BELUM-01']);

        $response = $this->withoutVite()->get(route('attendance.index'))->assertOk();

        $response->assertSee('ESP32-BELUM-01')
            ->assertSee('Belum didaftarkan')
            ->assertSee('row-untied')
            ->assertDontSee('Tautkan');

        $this->assertCount(1, $response->viewData('devices'));
        $this->assertSame(Device::StatusUnmapped, $device->status);
    }

    public function test_unlinked_devices_are_listed_before_linked_ones(): void
    {
        $this->pairedDevice('ESP32-AAA-01');
        Device::create(['device_id' => 'ESP32-ZZZ-01']);

        $devices = $this->withoutVite()->get(route('attendance.index'))
            ->assertOk()
            ->viewData('devices');

        $this->assertSame(
            ['ESP32-ZZZ-01', 'Sensor ESP32-AAA-01'],
            $devices->map(fn (Device $device) => $device->label())->all(),
        );
    }

    public function test_attendance_page_marks_regions_for_automatic_sync(): void
    {
        $content = $this->withoutVite()->get(route('attendance.index'))->assertOk()->getContent();

        $this->assertLiveRegions($content, ['attendance-stats', 'attendance-log', 'class-log']);
    }

    public function test_class_sensor_records_a_scan_outside_the_attendance_windows(): void
    {
        $device = $this->classSensor('ESP32-KELAS-07', 'X TKJ 1');
        $student = $this->studentOf('NIM-KELAS-JAM-01', 'X TKJ 1');

        // 12:00 tidak masuk jam presensi masuk maupun pulang.
        $this->travelTo(Carbon::parse('2026-09-10 12:00:00'));

        $session = $this->openLessonFor('X TKJ 1', $this->lessonHour(1));

        $response = $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $student->identifier_number,
            'matched' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('attendance_recorded', true)
            ->assertJsonPath('attendance_label', 'Hadir Kelas')
            ->assertJsonPath('display_detail', 'Jam ke-1 12:00');

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $student->id,
            'device_id' => $device->device_id,
            'source' => AttendanceLog::SourceClass,
            'type' => 'in',
            'lesson_session_id' => $session->id,
        ]);
    }

    public function test_class_sensor_answers_a_repeated_scan_as_already_recorded(): void
    {
        $device = $this->classSensor('ESP32-KELAS-08', 'X TKJ 1');
        $student = $this->studentOf('NIM-KELAS-JAM-02', 'X TKJ 1');

        $this->travelTo(Carbon::parse('2026-09-10 12:00:00'));

        $this->openLessonFor('X TKJ 1', $this->lessonHour(1));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $student->identifier_number,
            'matched' => true,
        ])->assertOk()->assertJsonPath('attendance_recorded', true);

        $second = $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $student->identifier_number,
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Sudah absen');

        $this->assertSame(1, AttendanceLog::count());
        $this->assertStringContainsString('sudah tercatat pukul 12:00', $second->json('message'));
    }

    public function test_class_sensor_records_the_teacher_once_per_lesson(): void
    {
        $this->classSensor('ESP32-KELAS-09', 'X TKJ 1');
        $teacher = $this->teacher('NIP-DUA-JAM-01');

        $this->travelTo(Carbon::parse('2026-09-10 07:10:00'));

        $this->openLessonFor('X TKJ 1', $this->lessonHour(1, '06:30', '08:30'), $teacher);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-KELAS-09',
            'user_id' => $teacher->identifier_number,
            'matched' => true,
        ])->assertOk()->assertJsonPath('attendance_recorded', true);

        // Scan berulang di jam pelajaran yang sama tidak menambah catatan.
        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-KELAS-09',
            'user_id' => $teacher->identifier_number,
            'matched' => true,
        ])->assertOk()->assertJsonPath('attendance_recorded', false);

        // Jam pelajaran berikutnya punya daftarnya sendiri, jadi scan lagi
        // tetap tercatat sebagai bukti hadir jam kedua.
        $this->travelTo(Carbon::parse('2026-09-10 10:10:00'));

        $this->openLessonFor('X TKJ 1', $this->lessonHour(2, '10:00', '11:30'), $teacher);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-KELAS-09',
            'user_id' => $teacher->identifier_number,
            'matched' => true,
        ])->assertOk()->assertJsonPath('attendance_recorded', true);

        $this->assertSame(2, AttendanceLog::count());
    }

    public function test_class_sensor_without_a_selected_class_cannot_record(): void
    {
        $device = $this->classSensor('ESP32-KELAS-10');
        $student = $this->studentOf('NIM-KELAS-KOSONG-01', 'X TKJ 1');

        $this->travelTo(Carbon::parse('2026-09-10 12:00:00'));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $student->identifier_number,
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Sensor belum diatur');

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_gate_sensor_restricted_to_a_class_still_obeys_the_windows(): void
    {
        $schoolClass = SchoolClass::create(['name' => 'X TKJ 1']);
        $device = $this->pairedDevice('ESP32-GERBANG-01');
        $device->schoolClasses()->attach($schoolClass->id);

        $student = $this->studentOf('NIM-GERBANG-KELAS-01', 'X TKJ 1');

        $this->travelTo(Carbon::parse('2026-09-10 12:00:00'));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $student->identifier_number,
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Bukan jam absen');

        $this->travelTo(Carbon::parse('2026-09-10 06:30:00'));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $student->identifier_number,
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('attendance_recorded', true)
            ->assertJsonPath('attendance_label', 'Hadir');

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $student->id,
            'source' => AttendanceLog::SourceGate,
        ]);
    }

    public function test_class_scans_are_reported_separately_from_gate_attendance(): void
    {
        $gate = $this->pairedDevice('ESP32-GERBANG-02');
        $classDevice = $this->classSensor('ESP32-KELAS-11', 'X TKJ 1');

        $gateStudent = $this->studentOf('NIM-PISAH-01', 'X TKJ 1');
        $classStudent = $this->studentOf('NIM-PISAH-02', 'X TKJ 1');

        $this->travelTo(Carbon::parse('2026-09-10 06:30:00'));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $gate->device_id,
            'user_id' => $gateStudent->identifier_number,
            'matched' => true,
        ])->assertOk()->assertJsonPath('attendance_recorded', true);

        $this->travelTo(Carbon::parse('2026-09-10 09:30:00'));

        $this->openLessonFor('X TKJ 1', $this->lessonHour(1));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $classDevice->device_id,
            'user_id' => $classStudent->identifier_number,
            'matched' => true,
        ])->assertOk()->assertJsonPath('attendance_recorded', true);

        $response = $this->withoutVite()->get(route('attendance.index'))->assertOk();
        $stats = $response->viewData('stats');

        $this->assertSame(1, $stats['hadir']);
        $this->assertSame(1, $stats['scans']);
        $this->assertSame(1, $stats['classScans']);
        $this->assertSame(1, $stats['classRecorded']);
        $this->assertCount(1, $response->viewData('logs'));
        $this->assertCount(1, $response->viewData('classLogs'));

        $response->assertSee('Scan Kelas Hari Ini')
            ->assertSee('ESP32-KELAS-11')
            ->assertSee($classStudent->name);

        $this->assertSame(
            [AttendanceLog::SourceGate],
            $response->viewData('logs')->pluck('source')->unique()->values()->all(),
        );
        $this->assertSame(
            [AttendanceLog::SourceClass],
            $response->viewData('classLogs')->pluck('source')->unique()->values()->all(),
        );

        // Dashboard melaporkan kehadiran di jam pelajaran, jadi hanya siswa yang
        // scan di sensor kelas yang dihitung hadir.
        $dashboardStats = $this->withoutVite()->get(route('dashboard'))->assertOk()->viewData('stats');

        $this->assertSame(1, $dashboardStats['studentsPresent']);
        $this->assertSame(1, $dashboardStats['open']);
    }

    public function test_admin_can_add_a_attendance_window(): void
    {
        $this->post(route('attendance.windows.store'), [
            'kind' => AttendanceWindow::KindCheckOut,
            'starts_at' => '16:30',
            'ends_at' => '17:30',
        ])->assertRedirect(route('attendance.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_windows', [
            'kind' => AttendanceWindow::KindCheckOut,
            'starts_at' => '16:30',
            'ends_at' => '17:30',
        ]);
    }

    public function test_attendance_window_cannot_overlap_an_existing_range(): void
    {
        $this->post(route('attendance.windows.store'), [
            'kind' => AttendanceWindow::KindPresent,
            'starts_at' => '07:00',
            'ends_at' => '08:00',
        ])->assertSessionHasErrors('ends_at');

        $this->assertSame(3, AttendanceWindow::query()->count());
    }

    public function test_attendance_window_requires_the_end_after_the_start(): void
    {
        $this->post(route('attendance.windows.store'), [
            'kind' => AttendanceWindow::KindPresent,
            'starts_at' => '10:00',
            'ends_at' => '09:00',
        ])->assertSessionHasErrors('ends_at');

        $this->assertSame(3, AttendanceWindow::query()->count());
    }

    public function test_admin_can_change_an_attendance_window(): void
    {
        $window = AttendanceWindow::query()->ordered()->firstOrFail();

        $this->put(route('attendance.windows.update', $window), [
            'kind' => AttendanceWindow::KindPresent,
            'starts_at' => '06:00',
            'ends_at' => '07:00',
        ])->assertRedirect(route('attendance.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_windows', [
            'id' => $window->id,
            'starts_at' => '06:00',
            'ends_at' => '07:00',
        ]);
    }

    public function test_unchanged_attendance_window_reports_that_nothing_changed(): void
    {
        $window = AttendanceWindow::query()->ordered()->firstOrFail();

        $this->put(route('attendance.windows.update', $window), [
            'kind' => $window->kind,
            'starts_at' => $window->starts_at,
            'ends_at' => $window->ends_at,
        ])->assertRedirect(route('attendance.index'))->assertSessionHas('warning');
    }

    public function test_admin_can_remove_an_attendance_window(): void
    {
        $window = AttendanceWindow::query()->ordered()->firstOrFail();

        $this->delete(route('attendance.windows.destroy', $window))
            ->assertRedirect(route('attendance.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('attendance_windows', ['id' => $window->id]);
    }

    public function test_the_last_attendance_window_cannot_be_removed(): void
    {
        $window = AttendanceWindow::query()->ordered()->firstOrFail();
        AttendanceWindow::query()->whereKeyNot($window->id)->delete();

        $this->delete(route('attendance.windows.destroy', $window))
            ->assertRedirect(route('attendance.index'))
            ->assertSessionHasErrors('window');

        $this->assertDatabaseHas('attendance_windows', ['id' => $window->id]);
    }

    public function test_scan_outside_every_window_is_rejected_with_a_device_message(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 12:00:00'));

        [$user, $device] = $this->readyUser('NIM-LUAR-01', 'ESP32-ABSEN-04');

        $response = $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Bukan jam absen')
            ->assertJsonPath('display_detail', $user->name);

        $this->assertStringContainsString('Scan di luar jam presensi', $response->json('message'));
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_second_scan_in_the_same_window_is_answered_as_already_recorded(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 06:30:00'));

        [$user, $device] = $this->readyUser('NIM-DUA-01', 'ESP32-ABSEN-05');

        $first = $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ])->assertOk()->assertJsonPath('attendance_recorded', true)->assertJsonPath('attendance_label', 'Hadir');

        $this->assertSame('in', $first->json('attendance_type'));

        $second = $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ])->assertOk()->assertJsonPath('attendance_recorded', false)->assertJsonPath('display_message', 'Sudah absen');

        $this->assertStringContainsString('Anda sudah absen masuk pukul 06:30', $second->json('message'));
        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_check_out_scan_is_recorded_separately_from_check_in(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 06:30:00'));

        [$user, $device] = $this->readyUser('NIM-PULANG-01', 'ESP32-ABSEN-06');

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ])->assertOk()->assertJsonPath('attendance_label', 'Hadir');

        $this->travelTo(Carbon::parse('2026-09-10 15:30:00'));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('attendance_recorded', true)
            ->assertJsonPath('attendance_type', 'out')
            ->assertJsonPath('attendance_label', 'Pulang')
            ->assertJsonPath('display_detail', 'Pulang 15:30');

        $this->assertSame(2, AttendanceLog::count());
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $user->id, 'type' => 'out']);
    }

    public function test_sensor_linked_to_another_class_rejects_the_scan(): void
    {
        $device = $this->classSensor('ESP32-ABSEN-07', 'X TKJ 1');

        $this->travelTo(Carbon::parse('2026-09-10 06:30:00'));

        // Kelas X TKJ 1 sedang mengikuti pelajaran, jadi alatnya memang siap
        // menerima scan; yang ditolak di sini adalah kelas yang tidak cocok.
        $this->openLessonFor('X TKJ 1', $this->lessonHour(1));

        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'identifier_number' => 'NIM-SALAH-01',
            'class_name' => 'X TKJ 2',
        ]);
        Fingerprint::create(['user_id' => $user->id, 'template' => 'template-NIM-SALAH-01']);

        $response = $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Bukan kelas ini');

        $this->assertStringContainsString('Sensor ini hanya untuk kelas X TKJ 1', $response->json('message'));
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_sensor_linked_to_a_class_accepts_that_class(): void
    {
        $device = $this->classSensor('ESP32-ABSEN-11', 'X TKJ 1');

        // Scan sore hari untuk memastikan sensor kelas tidak terikat jam presensi.
        $this->travelTo(Carbon::parse('2026-09-10 14:00:00'));

        $this->openLessonFor('X TKJ 1', $this->lessonHour(1));

        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'identifier_number' => 'NIM-BENAR-01',
            'class_name' => 'X TKJ 1',
        ]);
        Fingerprint::create(['user_id' => $user->id, 'template' => 'template-NIM-BENAR-01']);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('attendance_recorded', true)
            ->assertJsonPath('attendance_type', 'in');

        $this->assertDatabaseHas('attendance_logs', ['user_id' => $user->id, 'type' => 'in']);
    }

    public function test_sensor_linked_to_a_class_accepts_the_teacher_during_his_lesson(): void
    {
        $this->classSensor('ESP32-ABSEN-12', 'X TKJ 1');

        $teacher = $this->teacher('NIP-MENGAJAR-01');

        $this->travelTo(Carbon::parse('2026-09-10 07:00:00'));

        $this->openLessonFor('X TKJ 1', $this->lessonHour(1, '06:30', '08:30'), $teacher);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-ABSEN-12',
            'user_id' => $teacher->identifier_number,
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('attendance_recorded', true)
            ->assertJsonPath('attendance_type', 'in');

        $this->assertDatabaseHas('attendance_logs', ['user_id' => $teacher->id, 'type' => 'in']);
    }

    public function test_sensor_linked_to_a_class_rejects_the_teacher_outside_his_lesson(): void
    {
        $this->classSensor('ESP32-ABSEN-13', 'X TKJ 1');

        $teacher = $this->teacher('NIP-MENGAJAR-02');

        $this->travelTo(Carbon::parse('2026-09-10 07:00:00'));

        // Guru lain yang sedang mengajar di kelas itu, bukan dia.
        $this->openLessonFor('X TKJ 1', $this->lessonHour(1, '06:30', '08:30'));

        $response = $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-ABSEN-13',
            'user_id' => $teacher->identifier_number,
            'matched' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Di luar jadwal');

        $this->assertStringContainsString('Anda tidak mengajar di kelas itu pada jam ini', $response->json('message'));
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_class_sensor_waits_until_the_teacher_opens_the_lesson(): void
    {
        $this->classSensor('ESP32-ABSEN-14', 'X TKJ 1');

        $teacher = $this->teacher('NIP-MENGAJAR-03');

        $this->travelTo(Carbon::parse('2026-09-10 07:00:00'));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-ABSEN-14',
            'user_id' => $teacher->identifier_number,
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Belum waktunya');

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_student_without_a_class_cannot_use_a_class_sensor(): void
    {
        $device = $this->classSensor('ESP32-ABSEN-15', 'X TKJ 1');

        $student = $this->studentOf('NIM-TANPA-KELAS-01', null);

        $this->travelTo(Carbon::parse('2026-09-10 07:00:00'));

        $this->openLessonFor('X TKJ 1', $this->lessonHour(1));

        $response = $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $student->identifier_number,
            'matched' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('attendance_recorded', false)
            ->assertJsonPath('display_message', 'Bukan kelas ini');

        $this->assertStringContainsString('Kelas Anda belum diisi', $response->json('message'));
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_sensor_without_a_class_link_still_accepts_every_class(): void
    {
        $device = $this->pairedDevice('ESP32-ABSEN-16');

        $student = $this->studentOf('NIM-UMUM-01', 'X TKJ 9');

        $this->travelTo(Carbon::parse('2026-09-10 07:00:00'));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $student->identifier_number,
            'matched' => true,
        ])->assertOk()->assertJsonPath('attendance_recorded', true);

        $this->assertDatabaseHas('attendance_logs', ['user_id' => $student->id]);
    }

    public function test_scan_is_still_recorded_when_no_window_is_configured(): void
    {
        AttendanceWindow::query()->delete();

        $this->travelTo(Carbon::parse('2026-09-10 07:00:00'));

        [$user, $device] = $this->readyUser('NIM-KOSONG-01', 'ESP32-ABSEN-08');

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('attendance_recorded', true)
            ->assertJsonPath('attendance_label', 'Hadir');

        $this->assertDatabaseHas('attendance_logs', ['user_id' => $user->id, 'status' => 'present']);
    }

    public function test_attendance_stats_separate_check_in_and_check_out(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 06:30:00'));

        [$user, $device] = $this->readyUser('NIM-STAT-01', 'ESP32-ABSEN-09');

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ])->assertOk();

        $afterCheckIn = $this->get(route('attendance.index'))->viewData('stats');

        $this->assertSame(1, $afterCheckIn['hadir']);
        $this->assertSame(0, $afterCheckIn['terlambat']);
        $this->assertSame(0, $afterCheckIn['pulang']);
        $this->assertSame(1, $afterCheckIn['belumPulang']);
        $this->assertSame(1, $afterCheckIn['scans']);

        $this->travelTo(Carbon::parse('2026-09-10 15:30:00'));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => $device->device_id,
            'user_id' => $user->identifier_number,
            'matched' => true,
        ])->assertOk();

        $afterCheckOut = $this->get(route('attendance.index'))->viewData('stats');

        $this->assertSame(1, $afterCheckOut['hadir']);
        $this->assertSame(1, $afterCheckOut['pulang']);
        $this->assertSame(0, $afterCheckOut['belumPulang']);
        $this->assertSame(2, $afterCheckOut['scans']);

        $this->withoutVite()->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Pulang');
    }

    /**
     * Pengguna aktif dengan sidik jari terdaftar dan perangkat yang sudah didaftarkan.
     *
     * @return array{0: User, 1: Device}
     */
    private function readyUser(string $identifierNumber, string $deviceId): array
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'identifier_number' => $identifierNumber,
        ]);

        Fingerprint::create(['user_id' => $user->id, 'template' => 'template-'.$identifierNumber]);

        return [$user, $this->pairedDevice($deviceId)];
    }

    /**
     * Perangkat yang sudah didaftarkan dan aktif, jadi sudah boleh mengirim scan.
     */
    private function pairedDevice(string $deviceId): Device
    {
        return Device::create([
            'device_id' => $deviceId,
            'name' => 'Sensor '.$deviceId,
            'location' => 'Lab Komputer',
            'status' => Device::StatusActive,
        ]);
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
            $schoolClass = SchoolClass::firstOrCreate(['name' => $className]);
            $device->schoolClasses()->attach($schoolClass->id);
        }

        return $device->refresh();
    }

    /**
     * Buka satu sesi absen untuk sebuah kelas pada waktu yang sedang dipakai
     * tes. Sejak absen kelas hanya tercatat kalau gurunya sudah membuka sesi,
     * sensor kelas perlu keadaan ini lebih dulu. Gurunya dibuat sendiri kecuali
     * tesnya memang perlu guru tertentu.
     */
    private function openLessonFor(string $className, LessonHour $lessonHour, ?User $teacher = null): LessonSession
    {
        $schoolClass = SchoolClass::firstOrCreate(['name' => $className]);
        $teacher ??= $this->registeredUser(
            'NIP-'.$lessonHour->number.'-'.Str::slug($className),
            User::RoleTeacher,
            null,
        );

        $schedule = $this->lessonSchedule(
            $schoolClass,
            $teacher,
            $lessonHour,
            Carbon::now()->dayOfWeekIso,
        );

        return $this->openLessonSession($schedule);
    }

    /**
     * Siswa aktif dengan sidik jari terdaftar, lengkap dengan kelasnya.
     */
    private function studentOf(string $identifierNumber, ?string $className): User
    {
        return $this->registeredUser($identifierNumber, User::RoleStudent, $className);
    }

    /**
     * Guru aktif dengan sidik jari terdaftar.
     */
    private function teacher(string $identifierNumber): User
    {
        return $this->registeredUser($identifierNumber, User::RoleTeacher, null);
    }

    private function registeredUser(string $identifierNumber, string $role, ?string $className): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'identifier_number' => $identifierNumber,
            'class_name' => $className,
        ]);

        Fingerprint::create(['user_id' => $user->id, 'template' => 'template-'.$identifierNumber]);

        return $user;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function assertLiveRegions(string $content, array $keys): void
    {
        foreach ($keys as $key) {
            $this->assertSame(
                1,
                substr_count($content, 'data-live="'.$key.'"'),
                "Bagian live {$key} harus muncul tepat sekali.",
            );
        }

        $this->assertStringContainsString('data-live-status', $content);
    }

    /**
     * Proses berjalan lama seperti `fingerprint:mqtt-listen` memakai satu
     * instance service sepanjang hidupnya. Instance itu wajib membaca jam
     * terbaru dari database, bukan hasil pembacaan pertama yang disimpan di
     * memori, supaya perubahan jam presensi langsung terbaca perangkat.
     */
    public function test_schedule_service_reads_window_changes_without_restarting_the_process(): void
    {
        $schedule = app(AttendanceScheduleService::class);
        $at = Carbon::parse('2026-09-10 12:30:00');

        // Jam terlambat bawaan berakhir 09:00, jadi 12:30 masih di luar jam absen.
        $this->assertNull($schedule->windowAt($at));

        AttendanceWindow::query()
            ->where('kind', AttendanceWindow::KindLate)
            ->update(['ends_at' => '13:00']);

        $this->assertSame(AttendanceWindow::KindLate, $schedule->windowAt($at)?->kind);
        $this->assertStringContainsString('07:30 - 13:00 Terlambat', $schedule->summary());
    }
}
