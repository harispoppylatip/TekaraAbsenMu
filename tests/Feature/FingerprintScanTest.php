<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\EnrollmentSession;
use App\Models\Fingerprint;
use App\Models\FingerprintApiLog;
use App\Models\SchoolClass;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FingerprintScanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Titik api sensor tidak memakai login, tetapi halaman admin yang diuji di
     * sini (dasbor, halaman sidik jari, halaman anggota) sudah di balik masuk,
     * jadi setiap tes dimulai sebagai admin.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();
    }

    public function test_unknown_fingerprint_runs_two_tap_enrollment(): void
    {
        $this->pairedDevice('ESP32_LAB_01');

        $first = $this->postJson('/api/fingerprint/scan', [
            'device_id' => 'ESP32_LAB_01',
            'fingerprint_data' => 'template-one',
        ]);

        $first->assertOk()->assertJsonPath('status', 'next_step');

        $second = $this->postJson('/api/fingerprint/scan', [
            'device_id' => 'ESP32_LAB_01',
            'fingerprint_data' => 'template-one',
        ]);

        $second->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_mismatched_second_tap_resets_enrollment(): void
    {
        $this->pairedDevice('ESP32_LAB_02');

        $this->postJson('/api/fingerprint/scan', ['device_id' => 'ESP32_LAB_02', 'fingerprint_data' => 'one']);

        $response = $this->postJson('/api/fingerprint/scan', ['device_id' => 'ESP32_LAB_02', 'fingerprint_data' => 'two']);

        $response->assertOk()->assertJsonPath('status', 'mismatch');
    }

    public function test_registered_fingerprint_creates_attendance_log(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 06:30:00'));

        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Fingerprint::create(['user_id' => $user->id, 'template' => 'registered-template']);
        $this->pairedDevice('ESP32_CLASS_01');

        $response = $this->postJson('/api/fingerprint/scan', [
            'device_id' => 'ESP32_CLASS_01',
            'fingerprint_data' => 'registered-template',
        ]);

        $response->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('user', $user->name);
        $this->assertDatabaseHas('attendance_logs', ['user_id' => $user->id, 'device_id' => 'ESP32_CLASS_01']);
        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_unmapped_device_and_pending_sessions_can_be_deleted(): void
    {
        $device = Device::create(['device_id' => 'ESP32_REMOVE_01']);
        $session = EnrollmentSession::create([
            'device_id' => $device->device_id,
            'step' => 2,
            'temp_template_1' => 'template-one',
            'status' => 'waiting_tap_2',
        ]);

        $this->delete(route('devices.destroy', $device))->assertRedirect();

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('enrollment_sessions', ['id' => $session->id]);
    }

    public function test_claimed_device_can_be_deleted_without_losing_attendance_history(): void
    {
        $device = $this->pairedDevice('ESP32_KEEP_01');
        $user = User::factory()->create(['identifier_number' => 'NIM-KEEP-01']);

        AttendanceLog::create([
            'user_id' => $user->id,
            'device_id' => $device->device_id,
            'scanned_at' => now(),
            'status' => AttendanceLog::StatusPresent,
            'type' => AttendanceLog::TypeIn,
        ]);

        $this->delete(route('devices.destroy', $device))->assertRedirect();

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'device_id' => 'ESP32_KEEP_01',
        ]);
    }

    public function test_fingerprint_api_registers_template_by_user_identifier(): void
    {
        $user = User::factory()->create([
            'identifier_number' => 'NIM-001',
        ]);

        $this->pairedDevice('ESP32-REGISTER-01');

        $response = $this->postJson('/api/fingerprint/register', [
            'device_id' => 'ESP32-REGISTER-01',
            'user_id' => 'NIM-001',
            'template' => base64_encode(random_bytes(512)),
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user_id', 'NIM-001');
        $this->assertDatabaseHas('fingerprints', ['user_id' => $user->id]);
        $this->assertDatabaseHas('fingerprint_api_logs', [
            'action' => 'register',
            'user_identifier' => 'NIM-001',
            'matched_user_id' => $user->id,
            'result_status' => 'success',
        ]);
    }

    public function test_registering_the_same_finger_twice_updates_the_existing_template(): void
    {
        $user = User::factory()->create([
            'identifier_number' => 'NIM-DEDUPE-01',
        ]);

        $this->pairedDevice('ESP32-DEDUPE-01');

        foreach (['template-pertama', 'template-kedua'] as $template) {
            $this->postJson('/api/fingerprint/register', [
                'device_id' => 'ESP32-DEDUPE-01',
                'user_id' => 'NIM-DEDUPE-01',
                'template' => $template,
            ])->assertCreated();
        }

        $this->assertDatabaseCount('fingerprints', 1);
        $this->assertDatabaseHas('fingerprints', [
            'user_id' => $user->id,
            'finger_position' => Fingerprint::DefaultFingerPosition,
            'template' => 'template-kedua',
        ]);

        $this->getJson('/api/fingerprint/template/NIM-DEDUPE-01?device_id=ESP32-DEDUPE-01')
            ->assertOk()
            ->assertJsonPath('data.template', 'template-kedua');
    }

    public function test_register_keeps_a_separate_template_per_finger_position(): void
    {
        User::factory()->create([
            'identifier_number' => 'NIM-DEDUPE-02',
        ]);

        $this->pairedDevice('ESP32-DEDUPE-02');

        foreach (['Jelunjuk Kanan', 'Jempol Kiri'] as $position) {
            $this->postJson('/api/fingerprint/register', [
                'device_id' => 'ESP32-DEDUPE-02',
                'user_id' => 'NIM-DEDUPE-02',
                'template' => 'template-'.$position,
                'finger_position' => $position,
            ])->assertCreated();
        }

        $this->assertDatabaseCount('fingerprints', 2);
        $this->assertDatabaseHas('fingerprints', [
            'finger_position' => 'Jempol Kiri',
            'template' => 'template-Jempol Kiri',
        ]);
    }

    public function test_dedupe_command_keeps_the_newest_template_per_finger(): void
    {
        $user = User::factory()->create(['identifier_number' => 'NIM-DEDUPE-03']);
        $otherUser = User::factory()->create(['identifier_number' => 'NIM-DEDUPE-04']);

        foreach (['lama-1', 'lama-2', 'baru'] as $template) {
            Fingerprint::create([
                'user_id' => $user->id,
                'template' => $template,
                'finger_position' => Fingerprint::DefaultFingerPosition,
            ]);
        }
        Fingerprint::create([
            'user_id' => $user->id,
            'template' => 'template-jempol',
            'finger_position' => 'Jempol Kiri',
        ]);
        Fingerprint::create([
            'user_id' => $otherUser->id,
            'template' => 'template-pengguna-lain',
        ]);

        $this->artisan('fingerprints:dedupe')->assertExitCode(0);

        $this->assertDatabaseCount('fingerprints', 3);
        $this->assertDatabaseHas('fingerprints', ['template' => 'baru']);
        $this->assertDatabaseMissing('fingerprints', ['template' => 'lama-1']);
        $this->assertDatabaseMissing('fingerprints', ['template' => 'lama-2']);
        $this->assertDatabaseHas('fingerprints', ['template' => 'template-jempol']);
        $this->assertDatabaseHas('fingerprints', ['template' => 'template-pengguna-lain']);
    }

    public function test_fingerprint_api_returns_latest_template_for_user_identifier(): void
    {
        $user = User::factory()->create([
            'identifier_number' => 'NIM-002',
        ]);
        Fingerprint::create([
            'user_id' => $user->id,
            'template' => 'template-for-nim-002',
        ]);
        $this->pairedDevice('ESP32-LOOKUP-01');

        $this->getJson('/api/fingerprint/template/NIM-002?device_id=ESP32-LOOKUP-01')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user_id', 'NIM-002')
            ->assertJsonPath('data.template', 'template-for-nim-002');
        $this->assertDatabaseHas('fingerprint_api_logs', [
            'action' => 'lookup',
            'user_identifier' => 'NIM-002',
            'matched_user_id' => $user->id,
            'result_status' => 'success',
        ]);
    }

    public function test_fingerprint_api_validates_and_returns_missing_template_error(): void
    {
        $this->pairedDevice('ESP32-VALIDATE-01');

        $this->postJson('/api/fingerprint/register', [
            'device_id' => 'ESP32-VALIDATE-01',
            'user_id' => 'NIM-MISSING',
            'template' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'template']);

        $this->getJson('/api/fingerprint/template/NIM-MISSING?device_id=ESP32-VALIDATE-01')
            ->assertNotFound()
            ->assertJsonPath('status', 'error');
    }

    public function test_fingerprint_api_records_sensor_match_result(): void
    {
        $user = User::factory()->create([
            'identifier_number' => 'NIM-005',
        ]);
        $this->pairedDevice('ESP32-MATCH-01');

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-MATCH-01',
            'user_id' => 'NIM-005',
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('status', 'matched')
            ->assertJsonPath('name', $user->name);

        $this->assertDatabaseHas('fingerprint_api_logs', [
            'action' => 'match',
            'device_id' => 'ESP32-MATCH-01',
            'matched_user_id' => $user->id,
            'result_status' => 'matched',
        ]);
    }

    public function test_fingerprint_page_displays_registered_data_and_scan_logs(): void
    {
        $user = User::factory()->create([
            'identifier_number' => 'NIM-003',
        ]);
        Fingerprint::create([
            'user_id' => $user->id,
            'template' => 'registered-template',
            'finger_position' => 'Jelunjuk Kanan',
        ]);
        AttendanceLog::create([
            'user_id' => $user->id,
            'device_id' => 'ESP32-PAGE-01',
            'scanned_at' => now(),
            'status' => 'present',
        ]);

        $this->get('/fingerprints')
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee('NIM-003')
            ->assertSee('ESP32-PAGE-01')
            ->assertSee('Scan Terakhir');
    }

    public function test_scan_creates_audit_log_with_match_metadata(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 06:30:00'));

        $user = User::factory()->create([
            'identifier_number' => 'NIM-004',
        ]);
        Fingerprint::create([
            'user_id' => $user->id,
            'template' => 'AABB00FF',
        ]);
        $this->pairedDevice('ESP32-AUDIT-01');

        $this->postJson('/api/fingerprint/scan', [
            'device_id' => 'ESP32-AUDIT-01',
            'fingerprint_data' => 'AABB00FF',
        ])->assertOk()->assertJsonPath('status', 'success');

        $log = FingerprintApiLog::where('action', 'scan')->latest('id')->firstOrFail();
        $this->assertSame('success', $log->result_status);
        $this->assertSame($user->id, $log->matched_user_id);
        $this->assertSame(8, $log->template_length);
        $this->assertSame(3, $log->template_nonzero_bytes);
        $this->assertSame(hash('sha256', 'AABB00FF'), $log->template_hash);
    }

    public function test_match_api_records_attendance_log_for_matched_scan(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 07:00:00'));

        $this->pairedDevice('ESP32-MATCH-LOG-01');

        $user = User::factory()->create(['identifier_number' => 'NIM-MATCH-LOG-01']);
        Fingerprint::create([
            'user_id' => $user->id,
            'template' => 'template-match-log-01',
        ]);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-MATCH-LOG-01',
            'user_id' => 'NIM-MATCH-LOG-01',
            'matched' => true,
        ])->assertOk()
            ->assertJsonPath('status', 'matched')
            ->assertJsonPath('name', $user->name)
            ->assertJsonStructure(['attendance_id']);

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'device_id' => 'ESP32-MATCH-LOG-01',
            'status' => 'present',
        ]);
    }

    public function test_match_api_marks_attendance_late_inside_the_late_window(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 08:30:00'));

        $this->pairedDevice('ESP32-MATCH-LOG-02');

        $user = User::factory()->create(['identifier_number' => 'NIM-MATCH-LOG-02']);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-MATCH-LOG-02',
            'user_id' => 'NIM-MATCH-LOG-02',
            'matched' => true,
        ])->assertOk();

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'status' => 'late',
        ]);
    }

    public function test_match_api_does_not_record_attendance_when_finger_is_unknown(): void
    {
        User::factory()->create(['identifier_number' => 'NIM-MATCH-LOG-03']);
        $this->pairedDevice('ESP32-MATCH-LOG-03');

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-MATCH-LOG-03',
            'matched' => false,
        ])->assertOk()->assertJsonPath('status', 'no_match');

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_fingerprint_page_lists_attendance_created_by_sensor_match(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 06:30:00'));

        $user = User::factory()->create([
            'name' => 'Pemindai Sidik',
            'identifier_number' => 'NIM-MATCH-LOG-04',
        ]);
        $this->pairedDevice('ESP32-MATCH-LOG-04');

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-MATCH-LOG-04',
            'user_id' => 'NIM-MATCH-LOG-04',
            'matched' => true,
        ])->assertOk();

        $this->withoutVite()
            ->get('/fingerprints')
            ->assertOk()
            ->assertSee('Pemindai Sidik')
            ->assertSee('NIM-MATCH-LOG-04')
            ->assertSee('ESP32-MATCH-LOG-04')
            ->assertDontSee('Belum ada jari yang discan.');
    }

    public function test_member_page_can_register_a_user_for_fingerprint_enrollment(): void
    {
        $schoolClass = SchoolClass::create(['name' => 'X IPA 1']);

        $this->post(route('members.store'), [
            'name' => 'Siswa Baru',
            'email' => 'siswa.baru@example.test',
            'identifier_number' => '2411102441025',
            'role' => 'student',
            'class_name' => $schoolClass->name,
        ])->assertRedirect(route('members.index'));

        $this->assertDatabaseHas('users', [
            'name' => 'Siswa Baru',
            'identifier_number' => '2411102441025',
            'class_name' => 'X IPA 1',
        ]);
    }

    public function test_unpaired_device_is_detected_but_rejected_until_it_is_paired(): void
    {
        User::factory()->create(['identifier_number' => 'NIM-DISCOVER-01']);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-DISCOVER-01',
            'user_id' => 'NIM-DISCOVER-01',
            'matched' => true,
        ])->assertForbidden()
            ->assertJsonPath('message', 'Perangkat belum didaftarkan. Daftarkan perangkat di halaman Alat Sensor sebelum dipakai untuk presensi.')
            ->assertJsonPath('status', 'device_unregistered')
            ->assertJsonPath('display_message', 'Belum terdaftar')
            ->assertJsonPath('display_detail', 'Lapor operator')
            ->assertJsonPath('blocked', true);

        $device = Device::where('device_id', 'ESP32-DISCOVER-01')->firstOrFail();
        $this->assertSame(Device::StatusUnmapped, $device->status);
        $this->assertNotNull($device->last_ping);
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('fingerprint_api_logs', 0);
    }

    public function test_register_and_lookup_reject_devices_that_are_not_paired(): void
    {
        User::factory()->create(['identifier_number' => 'NIM-DISCOVER-02']);

        $this->postJson('/api/fingerprint/register', [
            'device_id' => 'ESP32-DISCOVER-02',
            'user_id' => 'NIM-DISCOVER-02',
            'template' => 'template-discover-02',
        ])->assertForbidden();

        $this->getJson('/api/fingerprint/template/NIM-DISCOVER-02?device_id=ESP32-DISCOVER-03')
            ->assertForbidden();

        $this->assertDatabaseHas('devices', ['device_id' => 'ESP32-DISCOVER-02', 'status' => Device::StatusUnmapped]);
        $this->assertDatabaseHas('devices', ['device_id' => 'ESP32-DISCOVER-03', 'status' => Device::StatusUnmapped]);
        $this->assertDatabaseMissing('fingerprints', ['template' => 'template-discover-02']);
    }

    public function test_dashboard_lists_a_device_that_still_waits_to_be_registered(): void
    {
        User::factory()->create(['identifier_number' => 'NIM-DASHBOARD-01']);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-DASHBOARD-01',
            'user_id' => 'NIM-DASHBOARD-01',
            'matched' => true,
        ])->assertForbidden();

        $this->withoutVite()
            ->get('/')
            ->assertOk()
            ->assertSee('ESP32-DASHBOARD-01')
            ->assertSee('Belum didaftarkan')
            ->assertSee('Terakhir terhubung')
            ->assertSee(route('devices.index'));
    }

    public function test_member_page_rejects_duplicate_identifier(): void
    {
        User::factory()->create(['identifier_number' => '2411102441025']);

        $this->from(route('members.index'))->post(route('members.store'), [
            'name' => 'Duplikat',
            'email' => 'duplikat@example.test',
            'identifier_number' => '2411102441025',
            'role' => 'student',
        ])->assertRedirect(route('members.index'))
            ->assertSessionHasErrors('identifier_number');
    }

    public function test_website_button_queues_enrollment_command_for_student(): void
    {
        $this->pairedDevice('ESP32-ENROLL-01');

        $student = User::factory()->create([
            'role' => 'student',
            'identifier_number' => 'NIM-AUTO-01',
        ]);

        $this->post(route('members.fingerprint.enroll', $student))
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success');

        $this->getJson('/api/fingerprint/command?device_id=ESP32-ENROLL-01')
            ->assertOk()
            ->assertJson(['mode' => 'enroll', 'user_id' => 'NIM-AUTO-01']);

        $this->getJson('/api/fingerprint/command?device_id=ESP32-ENROLL-01')
            ->assertOk()
            ->assertJson(['mode' => 'verify']);
    }

    public function test_member_page_offers_scan_button_to_user_without_template(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'identifier_number' => 'NIM-TEACHER-01',
        ]);

        $this->withoutVite()
            ->get(route('members.index'))
            ->assertOk()
            ->assertSee($teacher->name)
            ->assertSee('Scan sidik jari')
            ->assertSee('Hapus')
            ->assertDontSee('Sudah terdaftar');
    }

    public function test_member_page_marks_user_with_template_as_registered(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'identifier_number' => 'NIM-TEMPLATE-01',
        ]);

        Fingerprint::create([
            'user_id' => $user->id,
            'template' => 'template-terdaftar-01',
            'finger_position' => Fingerprint::DefaultFingerPosition,
        ]);

        $response = $this->withoutVite()->get(route('members.index'));

        $response->assertOk()
            ->assertSee('Sudah terdaftar')
            ->assertSee('Ganti sidik jari');

        // Tombol scan hanya muncul untuk anggota yang templatnya belum ada.
        $this->assertSame(
            User::query()->whereDoesntHave('fingerprints')->count(),
            substr_count($response->getContent(), 'Scan sidik jari'),
        );
    }

    public function test_replace_fingerprint_removes_old_template_and_queues_enrollment_command(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'identifier_number' => 'NIM-GANTI-01',
        ]);

        Fingerprint::create([
            'user_id' => $user->id,
            'template' => 'template-lama-01',
            'finger_position' => Fingerprint::DefaultFingerPosition,
        ]);
        $this->pairedDevice('ESP32-GANTI-01');

        $this->post(route('members.fingerprint.replace', $user))
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('fingerprints', 0);

        $this->getJson('/api/fingerprint/command?device_id=ESP32-GANTI-01')
            ->assertOk()
            ->assertJson(['mode' => 'enroll', 'user_id' => 'NIM-GANTI-01']);

        $this->getJson('/api/fingerprint/command?device_id=ESP32-GANTI-01')
            ->assertOk()
            ->assertJson(['mode' => 'verify']);
    }

    public function test_replace_fingerprint_rejects_user_without_template(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'identifier_number' => 'NIM-GANTI-02',
        ]);

        $this->post(route('members.fingerprint.replace', $user))->assertStatus(422);
    }

    /**
     * Nomor induk adalah identitas yang dikirim ke sensor bersama perintah
     * pendaftaran. Tanpa nomor itu alat menolak perintahnya, jadi admin harus
     * diberi tahu di halaman Anggota alih-alih dibiarkan menunggu tanpa hasil.
     */
    public function test_scan_button_refuses_member_without_identifier_number(): void
    {
        $this->pairedDevice('ESP32-TANPA-NIM-01');

        $member = User::factory()->create([
            'role' => 'admin',
            'name' => 'Admin Sekolah',
            'identifier_number' => null,
        ]);

        $this->post(route('members.fingerprint.enroll', $member))
            ->assertRedirect(route('members.index'))
            ->assertSessionHasErrors('identifier_number');

        $this->getJson('/api/fingerprint/command?device_id=ESP32-TANPA-NIM-01')
            ->assertOk()
            ->assertJson(['mode' => 'verify']);
    }

    /**
     * Template lama tidak boleh terhapus kalau penggantinya tetap tidak bisa
     * dikirim ke sensor.
     */
    public function test_replace_fingerprint_keeps_the_old_template_without_identifier_number(): void
    {
        $this->pairedDevice('ESP32-TANPA-NIM-02');

        $member = User::factory()->create([
            'role' => 'student',
            'identifier_number' => null,
        ]);

        Fingerprint::create([
            'user_id' => $member->id,
            'template' => 'template-tanpa-nomor-induk',
            'finger_position' => Fingerprint::DefaultFingerPosition,
        ]);

        $this->post(route('members.fingerprint.replace', $member))
            ->assertRedirect(route('members.index'))
            ->assertSessionHasErrors('identifier_number');

        $this->assertDatabaseCount('fingerprints', 1);
    }

    public function test_seeded_admin_can_receive_an_enrollment_command(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->pairedDevice('ESP32-ADMIN-01');

        $admin = User::where('email', 'admin@tekara.my.id')->firstOrFail();

        $this->assertNotNull($admin->identifier_number);

        $this->post(route('members.fingerprint.enroll', $admin))
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success');

        $this->getJson('/api/fingerprint/command?device_id=ESP32-ADMIN-01')
            ->assertOk()
            ->assertJson(['mode' => 'enroll', 'user_id' => $admin->identifier_number]);
    }

    public function test_member_can_be_deleted_with_fingerprints_and_attendance(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'identifier_number' => 'NIM-HAPUS-01',
        ]);

        Fingerprint::create([
            'user_id' => $user->id,
            'template' => 'template-hapus-01',
            'finger_position' => Fingerprint::DefaultFingerPosition,
        ]);
        AttendanceLog::create([
            'user_id' => $user->id,
            'device_id' => 'esp32-hapus',
            'scanned_at' => now(),
            'status' => 'present',
        ]);

        $this->from(route('members.index'))
            ->delete(route('members.destroy', $user))
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseCount('fingerprints', 0);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_deleting_member_cancels_waiting_enrollment_command(): void
    {
        $this->pairedDevice('ESP32-HAPUS-01');

        $user = User::factory()->create([
            'role' => 'student',
            'identifier_number' => 'NIM-HAPUS-02',
        ]);

        $this->post(route('members.fingerprint.enroll', $user))->assertRedirect(route('members.index'));

        $this->delete(route('members.destroy', $user))->assertRedirect(route('members.index'));

        $this->getJson('/api/fingerprint/command?device_id=ESP32-HAPUS-01')
            ->assertOk()
            ->assertJson(['mode' => 'verify']);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_website_button_queues_enrollment_command_for_teacher(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'identifier_number' => 'NIM-TEACHER-02',
        ]);
        $this->pairedDevice('ESP32-TEACHER-01');

        $this->post(route('members.fingerprint.enroll', $teacher))
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success');

        $this->getJson('/api/fingerprint/command?device_id=ESP32-TEACHER-01')
            ->assertOk()
            ->assertJson(['mode' => 'enroll', 'user_id' => 'NIM-TEACHER-02']);
    }

    public function test_website_button_rejects_user_that_already_has_template(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'identifier_number' => 'NIM-TEMPLATE-02',
        ]);

        Fingerprint::create([
            'user_id' => $user->id,
            'template' => 'template-terdaftar-02',
            'finger_position' => Fingerprint::DefaultFingerPosition,
        ]);

        $this->post(route('members.fingerprint.enroll', $user))->assertStatus(422);
    }

    public function test_device_can_fetch_registered_user_identifiers_and_report_no_match(): void
    {
        $user = User::factory()->create([
            'identifier_number' => 'NIM-AUTO-02',
        ]);
        Fingerprint::create(['user_id' => $user->id, 'template' => 'template-auto-02']);
        $this->pairedDevice('ESP32-AUTO-01');

        $this->getJson('/api/fingerprint/users?device_id=ESP32-AUTO-01')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', 'NIM-AUTO-02');

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-AUTO-01',
            'matched' => false,
        ])->assertOk()->assertJsonPath('status', 'no_match');

        $this->assertDatabaseHas('fingerprint_api_logs', [
            'action' => 'match',
            'device_id' => 'ESP32-AUTO-01',
            'result_status' => 'no_match',
        ]);
    }

    /**
     * Perangkat yang sudah didaftarkan dan siap dipakai untuk presensi.
     */
    private function pairedDevice(string $deviceId, string $status = Device::StatusActive): Device
    {
        return Device::create([
            'device_id' => $deviceId,
            'name' => 'Sensor '.$deviceId,
            'location' => 'Lab Komputer',
            'status' => $status,
        ]);
    }

    public function test_every_device_endpoint_rejects_an_unpaired_device(): void
    {
        User::factory()->create(['identifier_number' => 'NIM-BELUM-01']);

        $responses = [
            'scan' => $this->postJson('/api/fingerprint/scan', ['device_id' => 'ESP32-BELUM-01', 'fingerprint_data' => 'x']),
            'register' => $this->postJson('/api/fingerprint/register', ['device_id' => 'ESP32-BELUM-01', 'user_id' => 'NIM-BELUM-01', 'template' => 'x']),
            'template' => $this->getJson('/api/fingerprint/template/NIM-BELUM-01?device_id=ESP32-BELUM-01'),
            'match' => $this->postJson('/api/fingerprint/match', ['device_id' => 'ESP32-BELUM-01', 'user_id' => 'NIM-BELUM-01', 'matched' => true]),
            'command' => $this->getJson('/api/fingerprint/command?device_id=ESP32-BELUM-01'),
            'templates' => $this->getJson('/api/fingerprint/templates?device_id=ESP32-BELUM-01'),
            'users' => $this->getJson('/api/fingerprint/users?device_id=ESP32-BELUM-01'),
        ];

        foreach ($responses as $endpoint => $response) {
            $response->assertForbidden();
            $this->assertSame(
                'Perangkat belum didaftarkan. Daftarkan perangkat di halaman Alat Sensor sebelum dipakai untuk presensi.',
                $response->json('message'),
                "Endpoint {$endpoint} harus menolak perangkat yang belum didaftarkan.",
            );
            $this->assertSame('Belum terdaftar', $response->json('display_message'), "Endpoint {$endpoint} harus mengirim pesan untuk layar sensor.");
            $this->assertSame('Lapor operator', $response->json('display_detail'), "Endpoint {$endpoint} harus mengirim baris kedua pesan layar sensor.");
            $this->assertSame('device_unregistered', $response->json('status'), "Endpoint {$endpoint} harus menandai status penolakannya.");
            $this->assertTrue($response->json('blocked'), "Endpoint {$endpoint} harus menandai balasannya sebagai penolakan.");
        }

        $this->assertDatabaseCount('fingerprints', 0);
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('enrollment_sessions', 0);
        $this->assertDatabaseHas('devices', ['device_id' => 'ESP32-BELUM-01', 'status' => Device::StatusUnmapped]);
        $this->assertNotNull(Device::where('device_id', 'ESP32-BELUM-01')->value('last_ping'));
    }

    public function test_claiming_a_device_from_the_device_page_enables_the_device_api(): void
    {
        $device = Device::create(['device_id' => 'ESP32-CLAIM-01']);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-CLAIM-01',
            'matched' => false,
        ])->assertForbidden();

        $this->from(route('devices.index'))
            ->put(route('devices.link', $device), [
                'name' => 'Sensor Lab',
                'location' => 'Lab Komputer',
            ])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'name' => 'Sensor Lab',
            'status' => Device::StatusActive,
        ]);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-CLAIM-01',
            'matched' => false,
        ])->assertOk()->assertJsonPath('status', 'no_match');
    }

    public function test_inactive_device_is_rejected_with_its_own_message(): void
    {
        $this->pairedDevice('ESP32-MATI-01', Device::StatusInactive);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-MATI-01',
            'matched' => false,
        ])->assertForbidden()
            ->assertJsonPath('message', 'Perangkat sedang tidak aktif. Nyalakan perangkat di halaman Alat Sensor sebelum dipakai untuk presensi.')
            ->assertJsonPath('status', 'device_inactive')
            ->assertJsonPath('display_message', 'Sensor dimatikan')
            ->assertJsonPath('display_detail', 'Lapor operator')
            ->assertJsonPath('device_id', 'ESP32-MATI-01')
            ->assertJsonPath('device_status', Device::StatusInactive)
            ->assertJsonPath('device_status_label', 'Tidak aktif')
            ->assertJsonPath('blocked', true);

        $this->assertDatabaseCount('fingerprint_api_logs', 0);
    }

    /**
     * Sensor mengirim permintaan tanpa header Accept, jadi penolakan harus tetap
     * berupa JSON berisi pesan yang bisa ditampilkan di layar alat.
     */
    public function test_device_rejection_reaches_the_sensor_on_a_plain_request(): void
    {
        $this->pairedDevice('ESP32-TANPA-ACCEPT-01', Device::StatusInactive);

        $response = $this->post('/api/fingerprint/scan', [
            'device_id' => 'ESP32-TANPA-ACCEPT-01',
            'fingerprint_data' => 'template-sensor',
        ]);

        $response->assertForbidden()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('display_message', 'Sensor dimatikan')
            ->assertJsonPath('display_detail', 'Lapor operator')
            ->assertJsonPath('device_name', 'Sensor ESP32-TANPA-ACCEPT-01');

        $this->assertStringNotContainsString('<html', (string) $response->getContent());
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('enrollment_sessions', 0);
    }

    /**
     * Permintaan sensor yang datanya tidak lengkap juga harus bisa ditampilkan
     * di layar alat, bukan hanya mengembalikan daftar error validasi.
     */
    public function test_incomplete_sensor_request_answers_with_a_readable_message(): void
    {
        $this->pairedDevice('ESP32-KURANG-DATA-01');

        $this->post('/api/fingerprint/scan', ['device_id' => 'ESP32-KURANG-DATA-01'])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('status', 'invalid_request')
            ->assertJsonPath('display_message', 'Data alat kurang')
            ->assertJsonPath('display_detail', 'Lapor operator')
            ->assertJsonPath('blocked', true)
            ->assertJsonValidationErrors(['fingerprint_data']);
    }

    /**
     * Firmware mencetak `display_message` dan `display_detail` apa adanya sebagai
     * dua baris layar alat, sedangkan LCD alat hanya 16 kolom. Teks yang lebih
     * panjang akan terpotong di tengah kata, jadi batas itu dikunci di sini.
     */
    public function test_sensor_display_lines_fit_the_device_screen(): void
    {
        $this->pairedDevice('ESP32-LAYAR-01', Device::StatusInactive);
        $this->pairedDevice('ESP32-LAYAR-02');

        $refusals = [
            'perangkat belum didaftarkan' => $this->getJson('/api/fingerprint/command?device_id=ESP32-LAYAR-BARU')->assertForbidden(),
            'perangkat dimatikan' => $this->getJson('/api/fingerprint/command?device_id=ESP32-LAYAR-01')->assertForbidden(),
            'device_id kosong' => $this->getJson('/api/fingerprint/command')->assertStatus(422),
            'data tidak lengkap' => $this->post('/api/fingerprint/scan', ['device_id' => 'ESP32-LAYAR-02'])->assertStatus(422),
        ];

        foreach ($refusals as $case => $response) {
            foreach (['display_message', 'display_detail'] as $field) {
                $line = $response->json($field);

                $this->assertIsString($line, "Balasan {$case} harus menyertakan {$field}.");
                $this->assertNotSame('', trim($line), "{$field} balasan {$case} tidak boleh kosong.");
                $this->assertLessThanOrEqual(16, mb_strlen($line), "{$field} balasan {$case} tidak muat di layar alat.");
            }
        }
    }

    public function test_device_endpoint_requires_device_id(): void
    {
        $this->postJson('/api/fingerprint/match', ['matched' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'device_id wajib dikirim.')
            ->assertJsonPath('status', 'device_id_missing')
            ->assertJsonPath('display_message', 'ID sensor kosong')
            ->assertJsonPath('display_detail', 'Lapor operator')
            ->assertJsonPath('blocked', true);

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_enrollment_button_asks_the_admin_to_pair_a_device_first(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'identifier_number' => 'NIM-NODEV-01',
        ]);
        Device::create(['device_id' => 'ESP32-NODEV-01']);

        $this->from(route('members.index'))
            ->post(route('members.fingerprint.enroll', $student))
            ->assertRedirect(route('members.index'))
            ->assertSessionHasErrors('device');

        $this->assertNull(Cache::get('fingerprint.command.ESP32-NODEV-01'));
        $this->assertSame(0, Fingerprint::count());
        $this->assertSame(0, EnrollmentSession::count());
    }

    public function test_enrollment_command_reaches_every_paired_device_only(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'identifier_number' => 'NIM-MULTI-01',
        ]);
        $this->pairedDevice('ESP32-MULTI-01');
        $this->pairedDevice('ESP32-MULTI-02');
        $this->pairedDevice('ESP32-MULTI-03', Device::StatusInactive);
        Device::create(['device_id' => 'ESP32-MULTI-04']);

        $this->post(route('members.fingerprint.enroll', $student))
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success');

        $command = ['mode' => 'enroll', 'user_id' => 'NIM-MULTI-01'];

        $this->assertSame($command, Cache::get('fingerprint.command.ESP32-MULTI-01'));
        $this->assertSame($command, Cache::get('fingerprint.command.ESP32-MULTI-02'));
        $this->assertNull(Cache::get('fingerprint.command.ESP32-MULTI-03'));
        $this->assertNull(Cache::get('fingerprint.command.ESP32-MULTI-04'));
    }

    public function test_member_page_lists_the_paired_devices_that_receive_commands(): void
    {
        $this->pairedDevice('ESP32-HINT-01');
        Device::create(['device_id' => 'ESP32-HINT-02']);

        $content = $this->withoutVite()->get(route('members.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Perintah pendaftaran dikirim ke perangkat yang sudah didaftarkan', $content);
        $this->assertStringContainsString('Sensor ESP32-HINT-01', $content);
        $this->assertStringNotContainsString('Sensor ESP32-HINT-02', $content);
    }

    public function test_member_page_asks_the_admin_to_register_a_device_when_none_is_usable(): void
    {
        Device::create(['device_id' => 'ESP32-HINT-03']);

        $this->withoutVite()
            ->get(route('members.index'))
            ->assertOk()
            ->assertSee('Belum ada perangkat yang didaftarkan');
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

    public function test_dashboard_marks_regions_for_automatic_sync(): void
    {
        $content = $this->withoutVite()->get(route('dashboard'))->assertOk()->getContent();

        $this->assertLiveRegions($content, [
            'dashboard-stats',
            'dashboard-enrollments',
            'dashboard-devices',
            'dashboard-attendance-log',
        ]);
    }

    public function test_fingerprint_page_marks_regions_for_automatic_sync(): void
    {
        $content = $this->withoutVite()->get(route('fingerprints.index'))->assertOk()->getContent();

        $this->assertLiveRegions($content, [
            'fingerprints-ready',
            'fingerprints-pending',
            'fingerprints-registered',
            'fingerprints-scan-log',
            'fingerprints-api-log',
        ]);
    }

    public function test_member_page_marks_regions_for_automatic_sync(): void
    {
        $content = $this->withoutVite()->get(route('members.index'))->assertOk()->getContent();

        $this->assertLiveRegions($content, ['members-users']);
    }

    public function test_settings_page_marks_regions_for_automatic_sync(): void
    {
        $content = $this->withoutVite()->get(route('settings.index'))->assertOk()->getContent();

        $this->assertLiveRegions($content, ['settings-timezone', 'settings-logs']);
    }

    public function test_script_assets_include_live_sync_module(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertMatchesRegularExpression('/from\s+[\'"]\.\/live-sync[\'"]/', $appJs);
        $this->assertStringContainsString('startLiveRefresh', $appJs);

        $this->assertFileExists(resource_path('js/live-sync.js'));
    }
}
