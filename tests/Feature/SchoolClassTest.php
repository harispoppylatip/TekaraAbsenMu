<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolClassTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Halaman kelas hanya terbuka untuk yang sudah masuk, jadi setiap tes di
     * sini dimulai sebagai admin.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();
    }

    public function test_classes_page_lists_registered_classes_with_member_counts(): void
    {
        $schoolClass = SchoolClass::create(['name' => 'X TKJ 1']);

        User::factory()->count(2)->create(['class_name' => $schoolClass->name]);

        $this->withoutVite()
            ->get(route('classes.index'))
            ->assertOk()
            ->assertSee('X TKJ 1')
            ->assertSee('2 anggota');
    }

    public function test_class_can_be_added(): void
    {
        $this->from(route('classes.index'))
            ->post(route('classes.store'), ['name' => 'XI RPL 2'])
            ->assertRedirect(route('classes.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('school_classes', ['name' => 'XI RPL 2']);
    }

    public function test_class_name_is_trimmed_before_saving(): void
    {
        $this->post(route('classes.store'), ['name' => '  XII   IPS 3  '])
            ->assertRedirect(route('classes.index'));

        $this->assertDatabaseHas('school_classes', ['name' => 'XII IPS 3']);
    }

    public function test_duplicate_class_name_is_rejected_regardless_of_letter_case(): void
    {
        SchoolClass::create(['name' => 'X TKJ 1']);

        $this->from(route('classes.index'))
            ->post(route('classes.store'), ['name' => 'x tkj 1'])
            ->assertRedirect(route('classes.index'))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('school_classes', 1);
    }

    public function test_renaming_a_class_updates_every_member(): void
    {
        $schoolClass = SchoolClass::create(['name' => 'X TKJ 1']);
        $members = User::factory()->count(2)->create(['class_name' => 'X TKJ 1']);

        $this->from(route('classes.index'))
            ->put(route('classes.update', $schoolClass), ['name' => 'X TKJ 3'])
            ->assertRedirect(route('classes.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('school_classes', ['name' => 'X TKJ 3']);
        $this->assertDatabaseMissing('school_classes', ['name' => 'X TKJ 1']);
        $this->assertDatabaseMissing('users', ['class_name' => 'X TKJ 1']);

        foreach ($members as $member) {
            $this->assertSame('X TKJ 3', $member->fresh()->class_name);
        }
    }

    public function test_renaming_a_class_is_rejected_when_the_name_is_already_registered(): void
    {
        $schoolClass = SchoolClass::create(['name' => 'X TKJ 1']);

        SchoolClass::create(['name' => 'X TKJ 2']);

        $this->from(route('classes.index'))
            ->put(route('classes.update', $schoolClass), ['name' => 'X TKJ 2'])
            ->assertRedirect(route('classes.index'))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('school_classes', ['name' => 'X TKJ 1']);
    }

    public function test_renaming_a_class_to_the_same_name_reports_no_change(): void
    {
        $schoolClass = SchoolClass::create(['name' => 'X TKJ 1']);

        User::factory()->create(['class_name' => 'X TKJ 1']);

        $this->from(route('classes.index'))
            ->put(route('classes.update', $schoolClass), ['name' => 'X TKJ 1'])
            ->assertRedirect(route('classes.index'))
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('users', ['class_name' => 'X TKJ 1']);
    }

    public function test_class_that_still_has_members_cannot_be_deleted(): void
    {
        $schoolClass = SchoolClass::create(['name' => 'X TKJ 1']);
        $member = User::factory()->create(['class_name' => 'X TKJ 1']);

        $this->from(route('classes.index'))
            ->delete(route('classes.destroy', $schoolClass))
            ->assertRedirect(route('classes.index'))
            ->assertSessionHasErrors('class_name');

        $this->assertDatabaseHas('school_classes', ['name' => 'X TKJ 1']);
        $this->assertSame('X TKJ 1', $member->fresh()->class_name);
    }

    public function test_unused_class_can_be_deleted(): void
    {
        $schoolClass = SchoolClass::create(['name' => 'X TKJ 1']);

        $this->from(route('classes.index'))
            ->delete(route('classes.destroy', $schoolClass))
            ->assertRedirect(route('classes.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('school_classes', 0);
    }

    public function test_classes_page_marks_the_class_list_for_automatic_sync(): void
    {
        SchoolClass::create(['name' => 'X TKJ 1']);

        $content = $this->withoutVite()->get(route('classes.index'))->assertOk()->getContent();

        $this->assertLiveRegions($content, ['classes-list']);
    }

    public function test_classes_page_shows_the_sensors_and_lesson_schedules_of_a_class(): void
    {
        $schoolClass = SchoolClass::create(['name' => 'X TKJ 1']);
        $device = $this->pairedDevice('ESP32-KELAS-06');
        $device->update(['serves_gate' => false]);
        $device->schoolClasses()->attach($schoolClass->id);

        $teacher = User::factory()->teacher('Matematika')->create(['name' => 'Pak Dedi']);
        $this->lessonSchedule($schoolClass, $teacher, $this->lessonHour(2), 1, 'Matematika');

        $this->withoutVite()
            ->get(route('classes.index'))
            ->assertOk()
            ->assertSee('Sensor kelas')
            ->assertSee('Jadwal pelajaran')
            ->assertSee('Sensor ESP32-KELAS-06')
            ->assertSee('Matematika')
            ->assertSee('Pak Dedi')
            ->assertSee('Senin')
            ->assertSee('Jam ke-2')
            ->assertSee('1 jadwal')
            // Pilihan sensor dipindah ke halaman Presensi, jadi halaman kelas
            // hanya menampilkan hasilnya tanpa formulir tautan.
            ->assertDontSee('Tautkan sensor')
            ->assertSee(route('attendance.index'))
            ->assertSee(route('schedules.index', ['class' => $schoolClass->getKey()]));
    }

    public function test_class_page_asks_for_a_schedule_when_the_class_has_none(): void
    {
        SchoolClass::create(['name' => 'X TKJ 1']);

        $this->withoutVite()
            ->get(route('classes.index'))
            ->assertOk()
            ->assertSee('Belum ada pelajaran yang dijadwalkan di kelas ini.');
    }

    private function pairedDevice(string $deviceId): Device
    {
        return Device::create([
            'device_id' => $deviceId,
            'name' => 'Sensor '.$deviceId,
            'status' => Device::StatusActive,
        ]);
    }

    public function test_member_page_suggests_the_registered_classes_and_still_allows_free_text(): void
    {
        SchoolClass::create(['name' => 'X TKJ 1']);

        $content = $this->withoutVite()
            ->get(route('members.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<input name="class_name" list="class-suggestions"', $content);
        $this->assertStringContainsString('<datalist id="class-suggestions">', $content);
        $this->assertStringContainsString('<option value="X TKJ 1"></option>', $content);
    }

    public function test_member_registration_creates_the_class_that_is_typed_in(): void
    {
        $this->from(route('members.index'))
            ->post(route('members.store'), [
                'name' => 'Siswa Tanpa Kelas Terdaftar',
                'email' => 'siswa.tanpa.kelas@example.test',
                'identifier_number' => '2411102441099',
                'role' => 'student',
                'class_name' => '  XI  RPL 3 ',
            ])
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('school_classes', ['name' => 'XI RPL 3']);
        $this->assertDatabaseHas('users', [
            'identifier_number' => '2411102441099',
            'class_name' => 'XI RPL 3',
        ]);
    }

    public function test_member_registration_reuses_a_class_that_only_differs_in_letter_case(): void
    {
        SchoolClass::create(['name' => 'X TKJ 1']);

        $this->post(route('members.store'), [
            'name' => 'Siswa Kelas Sama',
            'email' => 'siswa.kelas.sama@example.test',
            'identifier_number' => '2411102441100',
            'role' => 'student',
            'class_name' => 'x tkj 1',
        ])->assertSessionHas('success');

        $this->assertSame(1, SchoolClass::whereRaw('LOWER(name) = ?', ['x tkj 1'])->count());
        $this->assertDatabaseHas('users', [
            'identifier_number' => '2411102441100',
            'class_name' => 'X TKJ 1',
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
