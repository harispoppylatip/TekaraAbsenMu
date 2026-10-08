<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman Data Pengguna sudah digabung ke Anggota. Alamat lamanya tetap
 * diteruskan supaya tautan dan penanda lama tidak rusak.
 */
class UserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();
    }

    public function test_old_directory_address_redirects_to_members(): void
    {
        $this->get(route('directory.index'))
            ->assertStatus(301)
            ->assertRedirect(route('members.index'));
    }

    public function test_old_directory_filters_are_carried_over(): void
    {
        $this->get(route('directory.index', [
            'q' => ' budi ',
            'role' => User::RoleTeacher,
            'class_name' => 'X TKJ 1',
            'fingerprint' => 'missing',
            'sort' => 'name',
        ]))->assertRedirect(route('members.index', [
            'q' => 'budi',
            'role' => User::RoleTeacher,
            'class_name' => 'X TKJ 1',
            'fingerprint' => 'pending',
        ]));
    }

    public function test_unknown_old_filter_values_are_dropped(): void
    {
        $this->get(route('directory.index', ['role' => 'kepala', 'fingerprint' => 'entah']))
            ->assertRedirect(route('members.index'));
    }

    public function test_navigation_no_longer_lists_the_directory_page(): void
    {
        $this->withoutVite()
            ->get(route('members.index'))
            ->assertOk()
            ->assertDontSee('Data Pengguna')
            ->assertDontSee(route('directory.index'), false);
    }

    public function test_members_page_searches_email_too(): void
    {
        User::factory()->student()->create(['name' => 'Siti Aminah', 'email' => 'siti.khusus@sekolah.test']);
        User::factory()->student()->create(['name' => 'Rina Lestari']);

        $this->withoutVite()
            ->get(route('members.index', ['q' => 'siti.khusus']))
            ->assertOk()
            ->assertSee('Siti Aminah')
            ->assertDontSee('Rina Lestari');
    }
}
