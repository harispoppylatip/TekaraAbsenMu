<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SchoolClass::create(['name' => 'X TKJ 1']);
        SchoolClass::create(['name' => 'XI AKL 1']);

        User::factory()->student('X TKJ 1')->create(['name' => 'Andi Pratama', 'identifier_number' => '25001']);
        User::factory()->student('x tkj 1')->create(['name' => 'Bella Safira', 'identifier_number' => '25002']);
        User::factory()->student('XI AKL 1')->create(['name' => 'Citra Lestari', 'identifier_number' => '26001']);
        User::factory()->teacher('Matematika')->create(['name' => 'Pak Dedi', 'identifier_number' => 'NIP-01']);

        $this->signInAs(User::factory()->admin()->create(['name' => 'Admin Sekolah', 'identifier_number' => 'ADM-001']));
    }

    public function test_page_has_no_duplicate_add_member_button_and_shows_the_filter(): void
    {
        $this->withoutVite()
            ->get(route('members.index'))
            ->assertOk()
            ->assertDontSee('Tambah Anggota')
            ->assertSee('Tambah anggota')
            ->assertSee('Cari nama, NIS, atau email')
            ->assertSeeInOrder(['Semua', '5', 'Siswa', '3', 'Guru', '1', 'Admin', '1'])
            ->assertSee('Menampilkan semua');
    }

    public function test_members_can_be_filtered_by_role(): void
    {
        $this->withoutVite()
            ->get(route('members.index', ['role' => User::RoleTeacher]))
            ->assertOk()
            ->assertSee('Pak Dedi')
            ->assertDontSee('Andi Pratama')
            ->assertDontSee('Citra Lestari')
            ->assertSee('Hapus filter');
    }

    public function test_class_filter_ignores_letter_case_and_updates_role_counts(): void
    {
        $response = $this->withoutVite()
            ->get(route('members.index', ['class_name' => 'X TKJ 1']))
            ->assertOk()
            ->assertSee('Andi Pratama')
            ->assertSee('Bella Safira')
            ->assertDontSee('Citra Lestari');

        $this->assertSame(2, $response->viewData('users')->count());
        $this->assertSame(2, $response->viewData('roleCounts')->get(User::RoleStudent));
        $this->assertNull($response->viewData('roleCounts')->get(User::RoleTeacher));
    }

    public function test_search_matches_name_or_nim(): void
    {
        $this->withoutVite()
            ->get(route('members.index', ['q' => '26001']))
            ->assertOk()
            ->assertSee('Citra Lestari')
            ->assertDontSee('Andi Pratama')
            ->assertSee('Menampilkan');

        $this->withoutVite()
            ->get(route('members.index', ['q' => 'bella']))
            ->assertOk()
            ->assertSee('Bella Safira')
            ->assertDontSee('Citra Lestari');
    }

    public function test_unknown_role_is_ignored(): void
    {
        $response = $this->withoutVite()
            ->get(route('members.index', ['role' => 'kepala-sekolah']))
            ->assertOk();

        $this->assertSame(5, $response->viewData('users')->count());
        $this->assertFalse($response->viewData('hasFilters'));
    }
}
