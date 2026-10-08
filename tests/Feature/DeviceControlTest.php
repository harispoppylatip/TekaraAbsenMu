<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\EnrollmentSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pusat kontrol perangkat sensor: perangkat baru terbaca belum boleh dipakai
 * sebelum didaftarkan, lalu bisa diganti nama, diatur layanannya, dimatikan,
 * didaftarkan ulang, atau dihapus dari halaman Perangkat.
 */
class DeviceControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Halaman perangkat hanya terbuka untuk yang sudah masuk, jadi setiap tes
     * di sini dimulai sebagai admin.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();
    }

    public function test_device_page_lists_pending_and_registered_devices(): void
    {
        Device::create(['device_id' => 'ESP32-BARU-01']);
        $this->pairedDevice('ESP32-LAMA-01');

        $response = $this->withoutVite()->get(route('devices.index'))->assertOk();

        $response->assertSee('Pusat kontrol sensor')
            ->assertSee('Alat Baru Terhubung')
            ->assertSee('Alat Terdaftar')
            ->assertSee('ESP32-BARU-01')
            ->assertSee('Belum didaftarkan')
            ->assertSee('Belum terdaftar')
            ->assertSee('Daftarkan')
            ->assertSee('ESP32-LAMA-01')
            ->assertSee('Layanan: sensor pulang masuk')
            ->assertSee('Sensor dimatikan')
            ->assertSee('Simpan data')
            ->assertSee('Atur layanan')
            ->assertSee('Matikan alat')
            ->assertSee('Batalkan pendaftaran')
            ->assertSee('Hapus alat');

        $this->assertCount(1, $response->viewData('pendingDevices'));
        $this->assertCount(1, $response->viewData('registeredDevices'));
    }

    public function test_device_page_summarises_every_device_state(): void
    {
        $this->pairedDevice('ESP32-AKTIF-01');
        $this->pairedDevice('ESP32-MATI-01', Device::StatusInactive);
        Device::create(['device_id' => 'ESP32-BARU-02']);

        $stats = $this->withoutVite()->get(route('devices.index'))->assertOk()->viewData('stats');

        $this->assertSame([
            'total' => 3,
            'active' => 1,
            'inactive' => 1,
            'pending' => 1,
        ], $stats);
    }

    public function test_device_page_marks_regions_for_automatic_sync(): void
    {
        $content = $this->withoutVite()->get(route('devices.index'))->assertOk()->getContent();

        $this->assertLiveRegions($content, ['devices-stats', 'devices-pending', 'devices-registered']);
    }

    public function test_newly_detected_device_must_be_registered_before_it_can_be_used(): void
    {
        $device = Device::create(['device_id' => 'ESP32-BARU-03']);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-BARU-03',
            'matched' => false,
        ])->assertForbidden();

        $this->from(route('devices.index'))
            ->put(route('devices.link', $device), [
                'name' => 'Sensor Gerbang',
                'location' => 'Gerbang depan',
            ])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'berhasil didaftarkan'));

        $device->refresh();

        $this->assertSame(Device::StatusActive, $device->status);
        $this->assertSame('Sensor Gerbang', $device->name);
        $this->assertSame('Gerbang depan', $device->location);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-BARU-03',
            'matched' => false,
        ])->assertOk()->assertJsonPath('status', 'no_match');
    }

    public function test_device_token_given_during_registration_is_stored_as_a_hash(): void
    {
        $device = Device::create(['device_id' => 'ESP32-BARU-04']);

        $this->put(route('devices.link', $device), ['device_token' => 'rahasia-perangkat-01'])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success');

        $device->refresh();

        $this->assertNotNull($device->token_hash);
        $this->assertNotSame('rahasia-perangkat-01', $device->token_hash);
        $this->assertTrue(Hash::check('rahasia-perangkat-01', $device->token_hash));
    }

    public function test_registering_a_device_with_a_short_token_is_rejected(): void
    {
        $device = Device::create(['device_id' => 'ESP32-BARU-05']);

        $this->from(route('devices.index'))
            ->put(route('devices.link', $device), ['device_token' => 'pendek'])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHasErrors('device_token');

        $this->assertSame(Device::StatusUnmapped, $device->refresh()->status);
    }

    public function test_registering_a_device_that_is_already_registered_changes_nothing(): void
    {
        $device = $this->pairedDevice('ESP32-LAMA-02');

        $this->put(route('devices.link', $device), ['name' => 'Nama Baru'])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'sudah didaftarkan'));

        $device->refresh();

        $this->assertSame('Sensor ESP32-LAMA-02', $device->name);
        $this->assertSame(Device::StatusActive, $device->status);
    }

    public function test_device_name_and_location_can_be_edited_without_touching_its_power_state(): void
    {
        $device = $this->pairedDevice('ESP32-MATI-02', Device::StatusInactive);

        $this->put(route('devices.update', $device), [
            'name' => 'Sensor Kelas X TKJ 1',
            'location' => 'Ruang X TKJ 1',
            'status' => Device::StatusActive,
        ])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'berhasil disimpan'));

        $device->refresh();

        $this->assertSame('Sensor Kelas X TKJ 1', $device->name);
        $this->assertSame('Ruang X TKJ 1', $device->location);
        $this->assertSame(Device::StatusInactive, $device->status);
    }

    public function test_editing_a_device_requires_a_name(): void
    {
        $device = $this->pairedDevice('ESP32-LAMA-03');

        $this->from(route('devices.index'))
            ->put(route('devices.update', $device), ['name' => ''])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHasErrors('name');

        $this->assertSame('Sensor ESP32-LAMA-03', $device->refresh()->name);
    }

    public function test_registered_device_can_be_powered_off_and_back_on(): void
    {
        $device = $this->pairedDevice('ESP32-AKTIF-02');

        $this->put(route('devices.status', $device), ['status' => Device::StatusInactive])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'dimatikan'));

        $this->assertSame(Device::StatusInactive, $device->refresh()->status);

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-AKTIF-02',
            'matched' => false,
        ])->assertForbidden()
            ->assertJsonPath('message', 'Perangkat sedang tidak aktif. Nyalakan perangkat di halaman Alat Sensor sebelum dipakai untuk presensi.')
            ->assertJsonPath('display_message', 'Sensor dimatikan');

        $this->put(route('devices.status', $device), ['status' => Device::StatusActive])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'dinyalakan'));

        $this->postJson('/api/fingerprint/match', [
            'device_id' => 'ESP32-AKTIF-02',
            'matched' => false,
        ])->assertOk()->assertJsonPath('status', 'no_match');
    }

    public function test_powering_off_a_device_that_is_already_off_changes_nothing(): void
    {
        $device = $this->pairedDevice('ESP32-MATI-03', Device::StatusInactive);

        $this->put(route('devices.status', $device), ['status' => Device::StatusInactive])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'sudah dimatikan'));
    }

    public function test_device_that_is_not_registered_yet_cannot_be_powered_on(): void
    {
        $device = Device::create(['device_id' => 'ESP32-BARU-06']);

        $this->put(route('devices.status', $device), ['status' => Device::StatusActive])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHasErrors('status');

        $this->assertSame(Device::StatusUnmapped, $device->refresh()->status);
    }

    public function test_power_action_rejects_an_unknown_status(): void
    {
        $device = $this->pairedDevice('ESP32-AKTIF-03');

        $this->from(route('devices.index'))
            ->put(route('devices.status', $device), ['status' => 'tidur'])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHasErrors('status');

        $this->assertSame(Device::StatusActive, $device->refresh()->status);
    }

    public function test_device_registration_can_be_cancelled_without_deleting_the_device(): void
    {
        $device = $this->pairedDevice('ESP32-LAMA-04');

        EnrollmentSession::create(['device_id' => $device->device_id, 'status' => 'waiting_tap_1']);
        EnrollmentSession::create(['device_id' => $device->device_id, 'status' => 'completed']);

        $this->delete(route('devices.unlink', $device))
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'dibatalkan'));

        $this->assertSame(Device::StatusUnmapped, $device->refresh()->status);
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('enrollment_sessions', ['status' => 'waiting_tap_1']);
        $this->assertDatabaseHas('enrollment_sessions', ['status' => 'completed']);
    }

    public function test_cancelling_a_registration_that_never_happened_changes_nothing(): void
    {
        $device = Device::create(['device_id' => 'ESP32-BARU-07']);

        $this->delete(route('devices.unlink', $device))
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'belum didaftarkan'));

        $this->assertSame(Device::StatusUnmapped, $device->refresh()->status);
    }

    public function test_device_can_be_deleted_while_its_attendance_history_survives(): void
    {
        $user = User::factory()->create(['identifier_number' => 'NIM-HAPUS-01']);
        $device = $this->pairedDevice('ESP32-HAPUS-02');

        AttendanceLog::create([
            'user_id' => $user->id,
            'device_id' => $device->device_id,
            'scanned_at' => now(),
            'status' => 'present',
        ]);
        EnrollmentSession::create(['device_id' => $device->device_id, 'status' => 'ready']);

        $this->delete(route('devices.destroy', $device))
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'berhasil dihapus'));

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('enrollment_sessions', ['device_id' => 'ESP32-HAPUS-02']);
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_device_services_can_be_set_to_a_class_only(): void
    {
        $schoolClass = SchoolClass::create(['name' => 'X TKJ 1']);
        $device = $this->pairedDevice('ESP32-LAYAN-01');

        $this->from(route('devices.index'))
            ->put(route('devices.services', $device), ['services' => [(string) $schoolClass->id]])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('device_school_class', [
            'device_id' => $device->id,
            'school_class_id' => $schoolClass->id,
        ]);

        $device->refresh();

        $this->assertFalse($device->isGate());
        $this->assertSame(['X TKJ 1'], $device->classNames());
    }

    public function test_device_services_can_serve_the_gate_and_a_class_at_once(): void
    {
        $first = SchoolClass::create(['name' => 'X TKJ 1']);
        $second = SchoolClass::create(['name' => 'X TKJ 2']);
        $device = $this->pairedDevice('ESP32-LAYAN-02');

        $this->from(route('devices.index'))
            ->put(route('devices.services', $device), [
                'services' => ['gate', (string) $first->id, (string) $second->id],
            ])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success');

        $device->refresh();

        $this->assertTrue($device->isGate());
        $this->assertSame(['X TKJ 1', 'X TKJ 2'], $device->classNames());
        $this->assertSame('sensor pulang masuk, X TKJ 1, X TKJ 2', $device->serviceSummary());
    }

    public function test_device_services_can_be_cleared_from_the_gate(): void
    {
        $device = $this->pairedDevice('ESP32-LAYAN-03');

        $this->from(route('devices.index'))
            ->put(route('devices.services', $device))
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success');

        $device->refresh();

        $this->assertFalse($device->isGate());
        $this->assertSame('belum ada layanan', $device->serviceSummary());
    }

    public function test_unchanged_device_services_report_that_nothing_changed(): void
    {
        $device = $this->pairedDevice('ESP32-LAYAN-04');

        $this->from(route('devices.index'))
            ->put(route('devices.services', $device), ['services' => ['gate']])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('warning');
    }

    public function test_device_services_reject_a_class_that_no_longer_exists(): void
    {
        $device = $this->pairedDevice('ESP32-LAYAN-05');

        $this->from(route('devices.index'))
            ->put(route('devices.services', $device), ['services' => ['999']])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHasErrors('services');

        $this->assertTrue($device->refresh()->isGate());
    }

    public function test_attendance_page_points_the_admin_to_the_device_page(): void
    {
        $this->pairedDevice('ESP32-AKTIF-04');

        $this->withoutVite()
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSee(route('devices.index'))
            ->assertSee('Kelola')
            ->assertDontSee('Atur layanan')
            ->assertDontSee('Untaut');
    }

    /**
     * Perangkat yang sudah didaftarkan, jadi sudah boleh dipakai untuk presensi.
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
}
