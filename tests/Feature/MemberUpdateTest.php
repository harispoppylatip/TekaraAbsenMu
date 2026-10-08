<?php

namespace Tests\Feature;

use App\Models\EnrollmentSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Halaman anggota hanya terbuka untuk yang sudah masuk, jadi setiap tes di
     * sini dimulai sebagai admin.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();
    }

    public function test_member_table_offers_an_edit_link_per_member(): void
    {
        $member = User::factory()->create();

        $this->withoutVite()
            ->get(route('members.index'))
            ->assertOk()
            ->assertSee('>Ubah</a>', false)
            ->assertSee('edit='.$member->getKey(), false);
    }

    public function test_edit_link_fills_the_form_with_the_member_data(): void
    {
        $member = User::factory()->create([
            'name' => 'Ahmad Siswa',
            'email' => 'ahmad@example.test',
            'identifier_number' => '2411102441077',
            'role' => User::RoleStudent,
            'class_name' => 'X TKJ 1',
        ]);

        $this->withoutVite()
            ->get(route('members.index', ['edit' => $member->getKey()]))
            ->assertOk()
            ->assertSee('Ubah data anggota')
            ->assertSee('action="'.route('members.update', $member).'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('value="2411102441077"', false)
            ->assertSee('value="X TKJ 1"', false)
            ->assertSee('<option value="student" selected>', false)
            ->assertSee('Simpan perubahan')
            ->assertSee('Batal mengubah');
    }

    public function test_member_role_can_be_changed_to_teacher_with_a_subject(): void
    {
        SchoolClass::create(['name' => 'XI TKJ 2']);

        $member = User::factory()->create([
            'identifier_number' => '2411102441078',
            'role' => User::RoleStudent,
            'class_name' => 'X TKJ 1',
        ]);

        $this->put(route('members.update', $member), [
            'name' => $member->name,
            'email' => $member->email,
            'identifier_number' => $member->identifier_number,
            'role' => User::RoleTeacher,
            'subject' => 'Matematika',
        ])
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success');

        $this->assertSame(User::RoleTeacher, $member->fresh()->role);
        $this->assertSame('Matematika', $member->fresh()->subject);
        $this->assertNull($member->fresh()->class_name);
        $this->assertDatabaseMissing('school_classes', ['name' => 'Matematika']);
    }

    public function test_member_subject_is_trimmed_and_ignored_for_students(): void
    {
        $member = User::factory()->create([
            'identifier_number' => '2411102441082',
            'role' => User::RoleTeacher,
            'subject' => 'Fisika',
            'class_name' => null,
        ]);

        // Formulir mengirim kedua kolom (kolom yang tidak aktif hanya disembunyikan),
        // jadi nilai kolom yang tidak sesuai peran harus diabaikan server.
        $this->put(route('members.update', $member), [
            'name' => $member->name,
            'email' => $member->email,
            'identifier_number' => $member->identifier_number,
            'role' => User::RoleStudent,
            'class_name' => '  XII  RPL 4 ',
            'subject' => 'Fisika',
        ])
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success');

        $this->assertSame('XII RPL 4', $member->fresh()->class_name);
        $this->assertNull($member->fresh()->subject);
        $this->assertDatabaseHas('school_classes', ['name' => 'XII RPL 4']);
    }

    public function test_teacher_subject_can_be_emptied_again(): void
    {
        $member = User::factory()->create([
            'identifier_number' => '2411102441083',
            'role' => User::RoleTeacher,
            'subject' => 'Sejarah',
        ]);

        $this->put(route('members.update', $member), [
            'name' => $member->name,
            'email' => $member->email,
            'identifier_number' => $member->identifier_number,
            'role' => User::RoleTeacher,
            'subject' => '   ',
        ])->assertSessionHas('success');

        $this->assertNull($member->fresh()->subject);
    }

    public function test_member_update_registers_a_class_that_is_not_known_yet(): void
    {
        $member = User::factory()->create([
            'identifier_number' => '2411102441079',
            'role' => User::RoleStudent,
            'class_name' => null,
        ]);

        $this->put(route('members.update', $member), [
            'name' => $member->name,
            'email' => $member->email,
            'identifier_number' => $member->identifier_number,
            'role' => User::RoleStudent,
            'class_name' => 'XII RPL 4',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('school_classes', ['name' => 'XII RPL 4']);
        $this->assertSame('XII RPL 4', $member->fresh()->class_name);
    }

    public function test_member_class_can_be_emptied_again(): void
    {
        $member = User::factory()->create([
            'identifier_number' => '2411102441080',
            'role' => User::RoleStudent,
            'class_name' => 'X TKJ 1',
        ]);

        $this->put(route('members.update', $member), [
            'name' => $member->name,
            'email' => $member->email,
            'identifier_number' => $member->identifier_number,
            'role' => User::RoleStudent,
            'class_name' => '',
        ])->assertSessionHas('success');

        $this->assertNull($member->fresh()->class_name);
    }

    public function test_member_update_accepts_its_own_email_and_identifier(): void
    {
        $member = User::factory()->create([
            'email' => 'pemilik@example.test',
            'identifier_number' => '2411102441088',
            'role' => User::RoleStudent,
        ]);

        $this->put(route('members.update', $member), [
            'name' => 'Nama Diperbarui',
            'email' => 'pemilik@example.test',
            'identifier_number' => '2411102441088',
            'role' => User::RoleStudent,
        ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('Nama Diperbarui', $member->fresh()->name);
    }

    public function test_member_update_rejects_email_and_identifier_of_another_member(): void
    {
        $member = User::factory()->create([
            'email' => 'punya.sendiri@example.test',
            'identifier_number' => '2411102441090',
            'role' => User::RoleStudent,
        ]);

        User::factory()->create([
            'email' => 'milik.oranglain@example.test',
            'identifier_number' => '2411102441091',
            'role' => User::RoleTeacher,
        ]);

        $this->from(route('members.index'))
            ->put(route('members.update', $member), [
                'name' => $member->name,
                'email' => 'milik.oranglain@example.test',
                'identifier_number' => '2411102441091',
                'role' => User::RoleStudent,
            ])
            ->assertRedirect(route('members.index', ['edit' => $member->getKey()]))
            ->assertSessionHasErrors(['email', 'identifier_number']);

        $this->assertSame('punya.sendiri@example.test', $member->fresh()->email);
        $this->assertSame('2411102441090', $member->fresh()->identifier_number);
    }

    public function test_member_update_keeps_the_member_when_the_input_is_invalid(): void
    {
        $member = User::factory()->create([
            'name' => 'Nama Asli',
            'role' => User::RoleStudent,
        ]);

        $this->from(route('members.index'))
            ->put(route('members.update', $member), [
                'name' => '',
                'email' => 'bukan-email',
                'identifier_number' => '',
                'role' => 'kepala-sekolah',
            ])
            ->assertSessionHasErrors(['name', 'email', 'identifier_number', 'role']);

        $this->assertSame('Nama Asli', $member->fresh()->name);
        $this->assertSame(User::RoleStudent, $member->fresh()->role);
    }

    public function test_edit_query_string_of_an_unknown_member_falls_back_to_add_mode(): void
    {
        $this->withoutVite()
            ->get(route('members.index', ['edit' => 9999]))
            ->assertOk()
            ->assertSee('Tambah anggota')
            ->assertDontSee('Batal mengubah');
    }

    public function test_member_page_keeps_one_live_region_and_one_field_per_role_while_editing(): void
    {
        $member = User::factory()->create([
            'identifier_number' => '2411102441081',
            'class_name' => 'X TKJ 1',
        ]);

        $content = $this->withoutVite()
            ->get(route('members.index', ['edit' => $member->getKey()]))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($content, 'data-live="members-users"'));
        $this->assertSame(1, substr_count($content, 'data-live-status'));
        $this->assertSame(1, substr_count($content, '<input name="class_name"'));
        $this->assertSame(1, substr_count($content, 'list="class-suggestions"'));
        $this->assertSame(1, substr_count($content, 'id="class-suggestions"'));
        $this->assertSame(1, substr_count($content, '<input name="subject"'));
        $this->assertSame(1, substr_count($content, 'data-study-form'));
        $this->assertSame(1, substr_count($content, 'data-study-field="student"'));
        $this->assertSame(1, substr_count($content, 'data-study-field="teacher"'));
    }

    public function test_member_page_opens_the_subject_field_for_a_teacher(): void
    {
        $teacher = User::factory()->create([
            'identifier_number' => '2411102441084',
            'role' => User::RoleTeacher,
            'subject' => 'Bahasa Indonesia',
        ]);

        $content = $this->withoutVite()
            ->get(route('members.index', ['edit' => $teacher->getKey()]))
            ->assertOk()
            ->assertSee('Mata Pelajaran')
            ->assertSee('value="Bahasa Indonesia"', false)
            ->getContent();

        $this->assertStringContainsString(
            'data-study-field="student" hidden>',
            $content,
        );
        $this->assertStringNotContainsString(
            'data-study-field="teacher" hidden>',
            $content,
        );
    }

    public function test_member_table_shows_the_subject_for_a_teacher(): void
    {
        User::factory()->create([
            'name' => 'Bu Ratna',
            'role' => User::RoleTeacher,
            'subject' => 'Kimia',
        ]);

        $this->withoutVite()
            ->get(route('members.index'))
            ->assertOk()
            ->assertSee('Kelas / Mapel')
            ->assertSee('Kimia');
    }

    public function test_enrollment_form_also_registers_a_class_that_is_typed_in(): void
    {
        $session = $this->readyEnrollmentSession('ESP32-ENROLL-KELAS-01', 'kelas');

        $this->from(route('fingerprints.index'))
            ->post(route('enrollments.store'), [
                'enrollment_session_id' => $session->getKey(),
                'name' => 'Siswa Baru',
                'email' => 'siswa.baru@example.test',
                'identifier_number' => 'SISWA-KELAS-01',
                'role' => User::RoleStudent,
                'class_name' => 'XII AKL 1',
                'finger_position' => 'Jelunjuk Kanan',
            ])
            ->assertRedirect(route('fingerprints.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('school_classes', ['name' => 'XII AKL 1']);
        $this->assertDatabaseHas('users', [
            'identifier_number' => 'SISWA-KELAS-01',
            'class_name' => 'XII AKL 1',
            'role' => User::RoleStudent,
        ]);
    }

    public function test_enrollment_form_stores_a_subject_without_registering_a_class(): void
    {
        $session = $this->readyEnrollmentSession('ESP32-ENROLL-MAPEL-01', 'mapel');

        $this->from(route('fingerprints.index'))
            ->post(route('enrollments.store'), [
                'enrollment_session_id' => $session->getKey(),
                'name' => 'Guru Baru',
                'email' => 'guru.baru@example.test',
                'identifier_number' => 'GURU-MAPEL-01',
                'role' => User::RoleTeacher,
                'subject' => 'Ekonomi',
                'finger_position' => 'Jelunjuk Kanan',
            ])
            ->assertRedirect(route('fingerprints.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'identifier_number' => 'GURU-MAPEL-01',
            'subject' => 'Ekonomi',
            'class_name' => null,
            'role' => User::RoleTeacher,
        ]);
        $this->assertDatabaseMissing('school_classes', ['name' => 'Ekonomi']);
    }

    private function readyEnrollmentSession(string $deviceId, string $suffix): EnrollmentSession
    {
        return EnrollmentSession::create([
            'device_id' => $deviceId,
            'step' => 2,
            'temp_template_1' => 'template-'.$suffix.'-1',
            'temp_template_2' => 'template-'.$suffix.'-2',
            'status' => 'ready',
        ]);
    }

    public function test_fingerprint_page_uses_a_unique_class_suggestion_list_per_session(): void
    {
        foreach ([1, 2] as $index) {
            EnrollmentSession::create([
                'device_id' => 'ESP32-SARAN-0'.$index,
                'step' => 2,
                'temp_template_1' => 'template-saran-'.$index.'-a',
                'temp_template_2' => 'template-saran-'.$index.'-b',
                'status' => 'ready',
            ]);
        }

        $content = $this->withoutVite()
            ->get(route('fingerprints.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($content, 'list="class-suggestions-1"'));
        $this->assertSame(1, substr_count($content, 'id="class-suggestions-1"'));
        $this->assertSame(1, substr_count($content, 'list="class-suggestions-2"'));
        $this->assertSame(1, substr_count($content, 'id="class-suggestions-2"'));
    }
}
