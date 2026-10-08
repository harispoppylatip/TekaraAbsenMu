<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_update_identity_and_class_is_synchronized(): void
    {
        $student = User::factory()->student('25001')->create([
            'email' => 'student@example.test',
            'class_name' => 'X TKJ 1',
        ]);
        $this->signInAs($student);

        $this->put(route('account.update'), [
            'name' => 'Siswa Baru',
            'email' => 'student.baru@example.test',
            'identifier_number' => '25002',
            'phone_number' => '081234567890',
            'class_name' => '  XII  RPL 4 ',
        ])->assertRedirect(route('account.index'))->assertSessionHas('success');

        $updated = $student->fresh();
        $this->assertSame('Siswa Baru', $updated->name);
        $this->assertSame('student.baru@example.test', $updated->email);
        $this->assertSame('25002', $updated->identifier_number);
        $this->assertSame('081234567890', $updated->phone_number);
        $this->assertSame('XII RPL 4', $updated->class_name);
        $this->assertDatabaseHas('school_classes', ['name' => 'XII RPL 4']);
        $this->assertSame(User::RoleStudent, $updated->role);
    }

    public function test_teacher_can_update_subject_without_creating_a_class(): void
    {
        $teacher = User::factory()->teacher('Matematika')->create([
            'email' => 'teacher@example.test',
            'identifier_number' => 'NIP-25001',
        ]);
        $this->signInAs($teacher);

        $this->put(route('account.update'), [
            'name' => $teacher->name,
            'email' => $teacher->email,
            'identifier_number' => $teacher->identifier_number,
            'subject' => '  Informatika  ',
        ])->assertRedirect(route('account.index'))->assertSessionHas('success');

        $this->assertSame('Informatika', $teacher->fresh()->subject);
        $this->assertNull($teacher->fresh()->class_name);
        $this->assertDatabaseMissing('school_classes', ['name' => 'Informatika']);
    }

    public function test_account_form_does_not_allow_role_changes(): void
    {
        $student = User::factory()->student('25003')->create();
        $this->signInAs($student);

        $this->put(route('account.update'), [
            'name' => $student->name,
            'email' => $student->email,
            'identifier_number' => $student->identifier_number,
            'role' => User::RoleAdmin,
            'class_name' => 'X TKJ 1',
        ])->assertRedirect(route('account.index'))->assertSessionHas('success');

        $this->assertSame(User::RoleStudent, $student->fresh()->role);
    }

    public function test_account_page_shows_edit_fields_for_the_current_user(): void
    {
        $student = User::factory()->student('25004')->create(['class_name' => 'X TKJ 1']);
        SchoolClass::create(['name' => 'X TKJ 1']);
        $this->signInAs($student);

        $this->withoutVite()
            ->get(route('account.index'))
            ->assertOk()
            ->assertSee('Ubah Identitas')
            ->assertSee('action="'.route('account.update').'"', false)
            ->assertSee('name="class_name"', false)
            ->assertSee('Peran')
            ->assertSee('Siswa');
    }

    public function test_duplicate_identity_values_are_rejected(): void
    {
        $student = User::factory()->student('25005')->create(['email' => 'mine@example.test']);
        User::factory()->teacher('Matematika')->create([
            'email' => 'other@example.test',
            'identifier_number' => '25006',
        ]);
        $this->signInAs($student);

        $this->put(route('account.update'), [
            'name' => $student->name,
            'email' => 'other@example.test',
            'identifier_number' => '25006',
            'class_name' => 'X TKJ 1',
        ])->assertRedirect(route('account.index'))->assertSessionHasErrors(['email', 'identifier_number']);

        $this->assertSame('mine@example.test', $student->fresh()->email);
    }
}
