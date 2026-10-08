<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_open_for_guests(): void
    {
        $this->withoutVite()
            ->get(route('login'))
            ->assertOk()
            ->assertSee('Masuk ke akun')
            ->assertSee('Masuk');
    }

    public function test_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_admin_lands_on_the_dashboard_after_login(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@tekara.my.id',
            'password' => 'rahasia123',
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'admin@tekara.my.id',
            'password' => 'rahasia123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_teacher_lands_on_the_teaching_page_after_login(): void
    {
        $teacher = User::factory()->teacher()->create([
            'email' => 'guru@tekara.my.id',
            'password' => 'rahasia123',
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'guru@tekara.my.id',
            'password' => 'rahasia123',
        ])->assertRedirect(route('teacher.index'));

        $this->assertAuthenticatedAs($teacher);
    }

    public function test_student_lands_on_his_own_schedule_page_after_login(): void
    {
        $student = User::factory()->student('X TKJ 1')->create([
            'email' => 'siswa@tekara.my.id',
            'password' => 'rahasia123',
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'siswa@tekara.my.id',
            'password' => 'rahasia123',
        ])->assertRedirect(route('student.index'));

        $this->assertAuthenticatedAs($student);
    }

    public function test_wrong_password_keeps_the_guest_out(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@tekara.my.id',
            'password' => 'rahasia123',
        ]);

        $this->from(route('login'))
            ->post(route('login.attempt'), [
                'email' => 'admin@tekara.my.id',
                'password' => 'salah-sekali',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_unknown_email_is_rejected_with_the_same_message(): void
    {
        $this->from(route('login'))
            ->post(route('login.attempt'), [
                'email' => 'tidak-ada@tekara.my.id',
                'password' => 'rahasia123',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi tidak sesuai.']);

        $this->assertGuest();
    }

    public function test_inactive_account_cannot_sign_in(): void
    {
        User::factory()->admin()->inactive()->create([
            'email' => 'admin@tekara.my.id',
            'password' => 'rahasia123',
        ]);

        $this->from(route('login'))
            ->post(route('login.attempt'), [
                'email' => 'admin@tekara.my.id',
                'password' => 'rahasia123',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Akun ini sedang tidak aktif. Hubungi admin sekolah.']);

        $this->assertGuest();
    }

    public function test_signed_in_user_is_sent_to_his_own_page_instead_of_the_form(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->signInAs($teacher);

        $this->get(route('login'))->assertRedirect(route('teacher.index'));
    }

    public function test_logout_returns_to_the_login_page(): void
    {
        $this->signInAs(User::factory()->admin()->create());

        $this->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', 'Anda sudah keluar dari aplikasi.');

        $this->assertGuest();
    }

    public function test_each_role_is_kept_out_of_the_other_pages(): void
    {
        $this->signInAs(User::factory()->teacher()->create());

        $this->get(route('dashboard'))
            ->assertRedirect(route('teacher.index'))
            ->assertSessionHasErrors('role');

        $this->get(route('student.index'))->assertRedirect(route('teacher.index'));

        $this->signInAs(User::factory()->student('X TKJ 1')->create());

        $this->get(route('teacher.index'))->assertRedirect(route('student.index'));

        $this->signInAs(User::factory()->admin()->create());

        $this->get(route('teacher.index'))->assertRedirect(route('dashboard'));
    }
}
