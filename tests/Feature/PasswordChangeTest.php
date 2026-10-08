<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_with_the_default_password_is_locked_to_the_password_page(): void
    {
        $this->signInAs(User::factory()->teacher()->mustChangePassword()->create());

        $this->get(route('dashboard'))
            ->assertRedirect(route('password.edit'))
            ->assertSessionHasErrors(['password' => 'Kata sandi Anda masih yang bawaan. Ganti dulu supaya akun ini aman.']);

        $this->get(route('teacher.index'))->assertRedirect(route('password.edit'));
        $this->get(route('account.index'))->assertRedirect(route('password.edit'));
    }

    public function test_password_page_explains_that_the_change_is_mandatory(): void
    {
        $this->signInAs(User::factory()->student('X TKJ 1')->mustChangePassword()->create());

        $this->withoutVite()
            ->get(route('password.edit'))
            ->assertOk()
            ->assertSee('Ganti kata sandi dulu')
            ->assertSee('Simpan Kata Sandi');
    }

    public function test_member_who_changed_his_password_can_open_the_other_pages(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->signInAs($teacher);

        $this->withoutVite()
            ->get(route('password.edit'))
            ->assertOk()
            ->assertSee('Ubah kata sandi');
    }

    public function test_changing_the_password_unlocks_the_account(): void
    {
        $student = User::factory()->student('X TKJ 1')->withPassword('tekara123')->mustChangePassword()->create();

        $this->signInAs($student);

        $this->put(route('password.update'), [
            'current_password' => 'tekara123',
            'password' => 'sandibaru123',
            'password_confirmation' => 'sandibaru123',
        ])->assertRedirect(route('student.index'))
            ->assertSessionHas('success');

        $student->refresh();

        $this->assertFalse((bool) $student->must_change_password);
        $this->assertNotNull($student->password_changed_at);
        $this->assertTrue(Hash::check('sandibaru123', (string) $student->password));

        // Halaman perannya sekarang terbuka.
        $this->withoutVite()->get(route('student.index'))->assertOk();
    }

    public function test_wrong_current_password_is_refused(): void
    {
        $student = User::factory()->student('X TKJ 1')->withPassword('tekara123')->create();

        $this->signInAs($student);

        $this->from(route('password.edit'))
            ->put(route('password.update'), [
                'current_password' => 'bukan-ini',
                'password' => 'sandibaru123',
                'password_confirmation' => 'sandibaru123',
            ])
            ->assertRedirect(route('password.edit'))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('tekara123', (string) $student->fresh()->password));
    }

    public function test_new_password_must_be_confirmed_and_long_enough(): void
    {
        $student = User::factory()->student('X TKJ 1')->withPassword('tekara123')->create();

        $this->signInAs($student);

        $this->from(route('password.edit'))
            ->put(route('password.update'), [
                'current_password' => 'tekara123',
                'password' => 'pendek',
                'password_confirmation' => 'beda-sama-sekali',
            ])
            ->assertRedirect(route('password.edit'))
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('tekara123', (string) $student->fresh()->password));
    }

    public function test_new_password_must_differ_from_the_current_one(): void
    {
        $student = User::factory()->student('X TKJ 1')->withPassword('tekara123')->create();

        $this->signInAs($student);

        $this->from(route('password.edit'))
            ->put(route('password.update'), [
                'current_password' => 'tekara123',
                'password' => 'tekara123',
                'password_confirmation' => 'tekara123',
            ])
            ->assertRedirect(route('password.edit'))
            ->assertSessionHasErrors('password');
    }
}
