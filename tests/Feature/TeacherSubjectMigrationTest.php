<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherSubjectMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tabel jam mengajar sudah dihapus oleh migrasi sesudahnya, jadi tes
        // harus menyiapkannya lagi supaya data lama bisa dibuat lebih dulu.
        $this->legacyTeachingSchedules()->up();
    }

    /**
     * Migrasi dijalankan manual supaya data lama bisa disiapkan lebih dulu.
     * Tipe kembaliannya `object` karena kelas `Migration` tidak punya `up()`.
     */
    private function migration(): object
    {
        return require database_path('migrations/2026_09_13_000010_relocate_teacher_class_to_subject.php');
    }

    /**
     * Tabel warisan yang tidak dipakai lagi oleh aplikasi, hanya oleh migrasi
     * pemindahan jadwal mengajar ke mata pelajaran.
     */
    private function legacyTeachingSchedules(): object
    {
        return require database_path('migrations/2026_09_11_140121_create_teaching_schedules_table.php');
    }

    public function test_teacher_class_value_moves_to_the_subject_column(): void
    {
        $teacher = User::factory()->create([
            'role' => User::RoleTeacher,
            'class_name' => 'guru pjok',
            'subject' => null,
        ]);

        $this->migration()->up();

        $this->assertSame('guru pjok', $teacher->fresh()->subject);
        $this->assertNull($teacher->fresh()->class_name);
    }

    public function test_existing_subject_is_kept_and_student_classes_are_untouched(): void
    {
        $teacher = User::factory()->create([
            'role' => User::RoleTeacher,
            'class_name' => 'X TKJ 1',
            'subject' => 'Matematika',
        ]);

        $student = User::factory()->create([
            'role' => User::RoleStudent,
            'class_name' => 'X TKJ 1',
        ]);

        $this->migration()->up();

        $this->assertSame('Matematika', $teacher->fresh()->subject);
        $this->assertNull($teacher->fresh()->class_name);
        $this->assertSame('X TKJ 1', $student->fresh()->class_name);
    }

    public function test_teacher_without_class_is_left_alone(): void
    {
        $teacher = User::factory()->create([
            'role' => User::RoleTeacher,
            'class_name' => null,
            'subject' => 'Kimia',
        ]);

        $this->migration()->up();

        $this->assertSame('Kimia', $teacher->fresh()->subject);
        $this->assertNull($teacher->fresh()->class_name);
    }

    public function test_only_classes_that_are_not_used_anywhere_are_removed(): void
    {
        SchoolClass::create(['name' => 'asda']);

        $withMember = SchoolClass::create(['name' => 'Lab']);
        $withDevice = SchoolClass::create(['name' => 'X 21']);
        $withSchedule = SchoolClass::create(['name' => 'XII AKL 1']);

        User::factory()->create(['role' => User::RoleStudent, 'class_name' => $withMember->name]);

        $device = Device::create([
            'device_id' => 'ESP32-MIGRASI-01',
            'name' => 'Sensor Migrasi',
            'status' => Device::StatusActive,
        ]);
        $device->schoolClasses()->attach($withDevice);

        $teacher = User::factory()->create(['role' => User::RoleTeacher]);

        DB::table('teaching_schedules')->insert([
            'user_id' => $teacher->getKey(),
            'school_class_id' => $withSchedule->getKey(),
            'starts_at' => '07:00',
            'ends_at' => '08:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertDatabaseMissing('school_classes', ['name' => 'asda']);
        $this->assertDatabaseHas('school_classes', ['name' => 'Lab']);
        $this->assertDatabaseHas('school_classes', ['name' => 'X 21']);
        $this->assertDatabaseHas('school_classes', ['name' => 'XII AKL 1']);
    }
}
