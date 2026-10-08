<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DefaultPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();
    }

    public function test_default_password_follows_the_role(): void
    {
        $this->assertSame('tekara123', User::DefaultPassword);
        $this->assertSame('12345', User::defaultPasswordFor(User::RoleStudent, '12345'));
        $this->assertSame('12345', User::defaultPasswordFor(User::RoleStudent, ' 12345 '));
        $this->assertSame('tekara123', User::defaultPasswordFor(User::RoleStudent, null));
        $this->assertSame('tekara123', User::defaultPasswordFor(User::RoleStudent, '   '));
        $this->assertSame('tekara123', User::defaultPasswordFor(User::RoleTeacher, 'NIP-1'));
        $this->assertSame('tekara123', User::defaultPasswordFor(User::RoleAdmin, 'ADM-1'));
    }

    public function test_admin_adds_a_student_with_his_identifier_number_as_password(): void
    {
        $this->post(route('members.store'), [
            'name' => 'Andi Pratama',
            'email' => 'andi@tekara.test',
            'identifier_number' => '2425001',
            'role' => User::RoleStudent,
            'class_name' => 'X TKJ 1',
        ])->assertRedirect(route('members.index'))
            ->assertSessionHas('success', fn ($message): bool => str_contains((string) $message, '2425001 (nomor induk)'));

        $member = User::query()->where('email', 'andi@tekara.test')->sole();

        $this->assertTrue(Hash::check('2425001', $member->password));
        $this->assertTrue($member->must_change_password);
        $this->assertNull($member->password_changed_at);
        $this->assertFalse($member->hasChangedPassword());
        $this->assertSame(User::StatusActive, $member->status);
    }

    public function test_admin_adds_a_teacher_with_the_shared_default_password(): void
    {
        $this->post(route('members.store'), [
            'name' => 'Pak Dedi',
            'email' => 'dedi@tekara.test',
            'identifier_number' => 'NIP-1975',
            'role' => User::RoleTeacher,
            'subject' => 'Matematika',
        ])->assertRedirect(route('members.index'))
            ->assertSessionHas('success', fn ($message): bool => str_contains((string) $message, User::DefaultPassword));

        $member = User::query()->where('email', 'dedi@tekara.test')->sole();

        $this->assertTrue(Hash::check(User::DefaultPassword, $member->password));
        $this->assertTrue($member->must_change_password);
        $this->assertSame('Matematika', $member->subject);
    }

    public function test_resetting_a_password_forces_the_member_to_change_it_again(): void
    {
        $member = User::factory()->student('X TKJ 1')
            ->withPassword('rahasia-sendiri')
            ->create(['identifier_number' => '2425002']);

        $this->assertTrue($member->hasChangedPassword());

        $this->put(route('members.password.reset', $member))
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success', fn ($message): bool => str_contains((string) $message, '2425002 (nomor induk)'));

        $member->refresh();

        $this->assertTrue(Hash::check('2425002', $member->password));
        $this->assertTrue($member->must_change_password);
        $this->assertNull($member->password_changed_at);
    }

    public function test_new_member_signs_in_with_the_default_password_then_must_change_it(): void
    {
        $this->post(route('members.store'), [
            'name' => 'Andi Pratama',
            'email' => 'andi@tekara.test',
            'identifier_number' => '2425001',
            'role' => User::RoleStudent,
            'class_name' => 'X TKJ 1',
        ]);

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->post(route('login.attempt'), [
            'email' => 'andi@tekara.test',
            'password' => '2425001',
        ])->assertRedirect(route('student.index'));

        $this->assertAuthenticated();

        // Halaman perannya masih ditahan sampai kata sandi bawaan diganti.
        $this->get(route('student.index'))
            ->assertRedirect(route('password.edit'))
            ->assertSessionHasErrors('password');

        $this->withoutVite()
            ->get(route('password.edit'))
            ->assertOk()
            ->assertSee('Ganti kata sandi dulu');

        $this->put(route('password.update'), [
            'current_password' => '2425001',
            'password' => 'rahasia-baru-2026',
            'password_confirmation' => 'rahasia-baru-2026',
        ])->assertRedirect(route('student.index'));

        $this->get(route('student.index'))->assertOk();

        $this->assertTrue(User::query()->where('email', 'andi@tekara.test')->sole()->hasChangedPassword());
    }

    public function test_wrong_default_password_is_still_refused(): void
    {
        User::factory()->student('X TKJ 1')
            ->mustChangePassword()
            ->create(['email' => 'andi@tekara.test', 'identifier_number' => '2425001']);

        $this->post(route('logout'));

        $this->post(route('login.attempt'), [
            'email' => 'andi@tekara.test',
            'password' => '2425000',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
