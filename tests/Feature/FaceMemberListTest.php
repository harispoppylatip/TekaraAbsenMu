<?php

namespace Tests\Feature;

use App\Http\Controllers\FaceDataController;
use App\Models\FaceEmbedding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Daftar wajah terdaftar di halaman Data Wajah.
 *
 * Daftar itu berbentuk kartu: yang tampil hanya nama, nomor induk, dan jumlah
 * foto, sedangkan rincian fotonya baru muncul setelah kartunya dibuka. Begitu
 * anggotanya lebih dari sepuluh, sisanya dipindah ke halaman berikutnya supaya
 * daftarnya tidak memanjang tanpa ujung.
 */
class FaceMemberListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pemeriksaan layanan wajah tidak diuji di sini, jadi balasannya
        // dipalsukan supaya halaman bisa dibuka tanpa layanan Python berjalan.
        Http::fake(['*/health' => Http::response(['status' => 'ok', 'model' => 'dlib-resnet-v1'])]);
        $this->withoutVite();
    }

    public function test_only_members_that_have_photos_get_a_card(): void
    {
        $enrolled = User::factory()->student()->create(['name' => 'Andi Pratama', 'identifier_number' => '25001']);
        User::factory()->student()->create(['name' => 'Budi Santoso', 'identifier_number' => '25002']);

        $this->givePhotos($enrolled, 2);

        $response = $this->actingAs($this->admin())->get(route('face.index'))->assertOk();

        $registered = $response->viewData('registered');

        $this->assertInstanceOf(LengthAwarePaginator::class, $registered);
        $this->assertSame(['Andi Pratama'], $registered->pluck('name')->all());
        $this->assertSame(1, $registered->total());

        $response->assertSee('<details class="face-card">', false);
        $response->assertSee('<span class="face-card-identity">', false);
        $response->assertSee('NIS 25001');
        $response->assertSee('2 foto');
    }

    public function test_the_photo_details_are_behind_a_click(): void
    {
        $student = User::factory()->student()->create(['name' => 'Andi Pratama', 'identifier_number' => '25001']);

        $this->givePhotos($student, 2);

        $response = $this->actingAs($this->admin())->get(route('face.index'))->assertOk();

        // Keterangan pembuka dan penutup kartu, plus dua tombol hapus yang
        // berbeda: satu untuk satu foto, satu untuk seluruh foto anggota.
        $response->assertSee('Lihat detail');
        $response->assertSee('Tutup');
        $response->assertSee('25001-1.jpg');
        $response->assertSee('25001-2.jpg');
        $response->assertSee('Hapus foto');
        $response->assertSee('Hapus semua foto');
    }

    public function test_the_list_is_split_into_pages_of_ten_members(): void
    {
        foreach (range(1, 11) as $index) {
            $member = User::factory()->student()->create([
                'name' => 'Anggota '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'identifier_number' => '260'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);

            $this->givePhotos($member);
        }

        $firstPage = $this->actingAs($this->admin())->get(route('face.index'))->assertOk();
        $first = $firstPage->viewData('registered');

        $this->assertSame(FaceDataController::MembersPerPage, $first->count());
        $this->assertSame(11, $first->total());
        $this->assertTrue($first->hasMorePages());
        $this->assertSame('Anggota 01', $first->pluck('name')->first());
        $firstPage->assertSee('Halaman 1 dari 2');

        $secondPage = $this->actingAs($this->admin())->get(route('face.index', ['page' => 2]))->assertOk();
        $second = $secondPage->viewData('registered');

        $this->assertSame(['Anggota 11'], $second->pluck('name')->all());
        $this->assertFalse($second->hasMorePages());
        $secondPage->assertSee('Halaman 2 dari 2');
    }

    public function test_ten_members_still_fit_on_a_single_page(): void
    {
        foreach (range(1, 10) as $index) {
            $member = User::factory()->student()->create([
                'name' => 'Anggota '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'identifier_number' => '270'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);

            $this->givePhotos($member);
        }

        $response = $this->actingAs($this->admin())->get(route('face.index'))->assertOk();

        $this->assertSame(10, $response->viewData('registered')->count());
        $this->assertFalse($response->viewData('registered')->hasPages());
        $response->assertDontSee('Halaman 1 dari');
    }

    public function test_the_search_result_is_paged_and_keeps_the_search_term(): void
    {
        foreach (range(1, 11) as $index) {
            $member = User::factory()->student()->create([
                'name' => 'Andi Pratama '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'identifier_number' => '280'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);

            $this->givePhotos($member);
        }

        $other = User::factory()->student()->create(['name' => 'Budi Santoso', 'identifier_number' => '28999']);
        $this->givePhotos($other);

        $response = $this->actingAs($this->admin())->get(route('face.index', ['q' => 'Andi']))->assertOk();

        $this->assertSame(11, $response->viewData('registered')->total());
        $this->assertSame(10, $response->viewData('registered')->count());

        // Tautan halaman berikutnya harus membawa kata pencariannya, supaya
        // halaman dua tetap berisi hasil yang sama.
        $response->assertSee('q=Andi', false);
        $response->assertSee('page=2', false);

        $this->actingAs($this->admin())
            ->get(route('face.index', ['q' => '28999']))
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertDontSee('Halaman 1 dari');
    }

    public function test_an_empty_list_says_where_to_enroll(): void
    {
        User::factory()->student()->create(['name' => 'Andi Pratama', 'identifier_number' => '25001']);

        $response = $this->actingAs($this->admin())->get(route('face.index'))->assertOk();

        $this->assertSame(0, $response->viewData('registered')->total());
        $response->assertSee('Belum ada wajah terdaftar');
        $response->assertSee('0 anggota');
        $response->assertDontSee('<details class="face-card">', false);
    }

    public function test_a_search_without_a_match_explains_itself(): void
    {
        $student = User::factory()->student()->create(['name' => 'Andi Pratama', 'identifier_number' => '25001']);
        $this->givePhotos($student);

        $this->actingAs($this->admin())
            ->get(route('face.index', ['q' => 'Nama Yang Tidak Ada']))
            ->assertOk()
            ->assertSee('Tidak ada wajah yang cocok dengan pencarian itu.')
            ->assertDontSee('<details class="face-card">', false);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * Foto wajah milik satu anggota, dengan nama berkas yang gampang dikenali.
     */
    private function givePhotos(User $member, int $count = 1): void
    {
        foreach (range(1, $count) as $index) {
            FaceEmbedding::create([
                'user_id' => $member->id,
                'embedding' => array_fill(0, FaceEmbedding::Dimensions, 0.1),
                'label' => $member->identifier_number.'-'.$index.'.jpg',
            ]);
        }
    }
}
