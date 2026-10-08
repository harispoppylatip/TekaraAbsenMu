<?php

namespace Tests\Feature;

use App\Models\FaceEmbedding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Halaman yang menyegarkan diri sendiri setiap beberapa detik memakai sesi yang
 * sama, sehingga pesan sekali-tampil bisa habis dimakan permintaan penyegaran
 * sebelum pengguna membacanya.
 */
class LiveRefreshFlashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_polling_request_does_not_swallow_the_result_message(): void
    {
        $this->fakeEncoding();

        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create(['identifier_number' => '25011']);

        $this->actingAs($admin)
            ->from(route('face.index'))
            ->post(route('face.photos.store'), [
                'user_id' => $student->id,
                'photos' => [$this->photo()],
            ])
            ->assertSessionHas('success');

        // Penyegaran otomatis datang lebih dulu, tepat seperti di browser.
        $this->withHeader('X-Live-Refresh', '1')
            ->get(route('face.index'))
            ->assertOk();

        $this->flushHeaders()
            ->get(route('face.index'))
            ->assertSee('1 foto wajah', false)
            ->assertSee($student->name, false);
    }

    public function test_a_plain_page_load_still_clears_the_result_message(): void
    {
        $this->fakeEncoding();

        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create(['identifier_number' => '25012']);

        $this->actingAs($admin)
            ->from(route('face.index'))
            ->post(route('face.photos.store'), [
                'user_id' => $student->id,
                'photos' => [$this->photo()],
            ]);

        // Tampil sekali di halaman berikutnya, lalu hilang seperti biasa.
        $this->get(route('face.index'))->assertSee('1 foto wajah', false);
        $this->get(route('face.index'))->assertDontSee('1 foto wajah', false);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('wajah.jpg', str_repeat('wajah', 400));
    }

    private function fakeEncoding(): void
    {
        Http::fake([
            '*/encode' => Http::response([
                'status' => 'success',
                'encoding' => array_fill(0, FaceEmbedding::Dimensions, 0.42),
                'face_count' => 1,
                'model' => FaceEmbedding::DefaultModel,
                'message' => 'Wajah terbaca.',
            ]),
        ]);
    }
}
