<?php

namespace Tests\Feature;

use App\Http\Controllers\FaceDataController;
use App\Models\FaceEmbedding;
use App\Models\User;
use App\Services\FaceEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pendaftaran wajah dari halaman web: satu anggota sekaligus, impor massal dari
 * folder foto, dan foto pertama yang ikut saat anggota baru ditambahkan.
 *
 * Layanan wajah Python tidak dijalankan di dalam tes. Yang diuji adalah cara
 * aplikasi memperlakukan balasan layanan itu, jadi balasannya dipalsukan dengan
 * bentuk yang sama seperti aslinya.
 */
class FaceUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_uploads_a_photo_for_a_member_from_the_website(): void
    {
        $this->fakeEncoding($this->embedding(0.25));
        $student = User::factory()->student()->create(['name' => 'Andi Pratama', 'identifier_number' => '25001']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('face.photos.store'), [
                'user_id' => $student->id,
                'photos' => [$this->photo(), $this->photo()],
            ])
            ->assertRedirect(route('face.index'))
            ->assertSessionHas('success', '2 foto wajah Andi Pratama tersimpan, total 2 foto.');

        $this->assertSame(2, FaceEmbedding::query()->where('user_id', $student->id)->count());
        $this->assertSame(
            ['wajah.jpg', 'wajah.jpg'],
            FaceEmbedding::query()->where('user_id', $student->id)->orderBy('id')->pluck('label')->all(),
        );
    }

    public function test_the_page_reports_every_photo_that_was_checked(): void
    {
        // Foto pertama terbaca, foto kedua tidak ada wajahnya.
        Http::fake([
            '*/encode' => Http::sequence()
                ->push(['status' => 'success', 'encoding' => $this->embedding(0.25), 'face_count' => 1])
                ->push(['status' => 'no_face', 'encoding' => null, 'face_count' => 0]),
        ]);

        $student = User::factory()->student()->create(['name' => 'Andi Pratama', 'identifier_number' => '25002']);

        $this->withoutVite()
            ->actingAs(User::factory()->admin()->create())
            ->followingRedirects()
            ->post(route('face.photos.store'), [
                'user_id' => $student->id,
                'photos' => [
                    $this->photo(),
                    UploadedFile::fake()->createWithContent('kabur.jpg', str_repeat('kabur', 400)),
                ],
            ])
            ->assertOk()
            ->assertSee('Hasil pendaftaran foto')
            ->assertSee('Andi Pratama')
            ->assertSee('kabur.jpg')
            ->assertSee('Wajah tidak terlihat')
            ->assertSee('1 foto dilewati');

        $this->assertSame(1, FaceEmbedding::query()->where('user_id', $student->id)->count());
    }

    public function test_a_photo_with_more_than_one_person_is_not_used(): void
    {
        Http::fake([
            '*/encode' => Http::response([
                'status' => 'success',
                'encoding' => $this->embedding(0.25),
                'face_count' => 2,
            ]),
        ]);

        $student = User::factory()->student()->create(['name' => 'Andi Pratama', 'identifier_number' => '25003']);

        $this->withoutVite()
            ->actingAs(User::factory()->admin()->create())
            ->followingRedirects()
            ->post(route('face.photos.store'), [
                'user_id' => $student->id,
                'photos' => [$this->photo()],
            ])
            ->assertOk()
            ->assertSee('Tidak ada foto wajah Andi Pratama yang bisa disimpan.')
            ->assertSee('lebih dari satu wajah');

        $this->assertDatabaseCount('face_embeddings', 0);
    }

    public function test_the_oldest_photo_is_dropped_at_the_photo_limit(): void
    {
        $this->fakeEncoding($this->embedding(0.25));
        $student = User::factory()->student()->create(['identifier_number' => '25004']);

        foreach (range(1, 19) as $index) {
            FaceEmbedding::create([
                'user_id' => $student->id,
                'embedding' => $this->embedding(0.1),
                'label' => 'lama-'.$index,
            ]);
        }

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('face.photos.store'), [
                'user_id' => $student->id,
                'photos' => [$this->photo(), $this->photo(), $this->photo()],
            ])
            ->assertRedirect(route('face.index'));

        $labels = FaceEmbedding::query()->where('user_id', $student->id)->orderBy('id')->pluck('label')->all();

        $this->assertCount(FaceEnrollmentService::MaxPhotosPerUser, $labels);
        $this->assertNotContains('lama-1', $labels);
        $this->assertNotContains('lama-2', $labels);
        $this->assertContains('lama-3', $labels);
    }

    public function test_upload_needs_a_member_and_a_photo(): void
    {
        $this->from(route('face.index'))
            ->actingAs(User::factory()->admin()->create())
            ->post(route('face.photos.store'), [])
            ->assertRedirect(route('face.index'))
            ->assertSessionHasErrors(['user_id', 'photos']);
    }

    public function test_upload_refuses_more_photos_than_the_per_submit_limit(): void
    {
        $this->fakeEncoding($this->embedding(0.30));
        $student = User::factory()->student()->create(['identifier_number' => '25009']);
        $admin = User::factory()->admin()->create();

        $tooMany = array_fill(0, FaceDataController::MaxPhotosPerSubmit + 1, $this->photo());

        $this->followingRedirects()
            ->from(route('face.index'))
            ->actingAs($admin)
            ->post(route('face.photos.store'), [
                'user_id' => $student->id,
                'photos' => $tooMany,
            ])
            ->assertSee('belasan detik, jadi kirim bertahap', false);

        $this->assertDatabaseCount('face_embeddings', 0);
    }

    public function test_the_per_submit_limit_stays_small_because_one_photo_takes_a_while(): void
    {
        $this->assertLessThanOrEqual(3, FaceDataController::MaxPhotosPerSubmit);
    }

    public function test_upload_refuses_a_file_that_is_not_a_photo(): void
    {
        $student = User::factory()->student()->create(['identifier_number' => '25005']);

        $this->from(route('face.index'))
            ->actingAs(User::factory()->admin()->create())
            ->post(route('face.photos.store'), [
                'user_id' => $student->id,
                'photos' => [UploadedFile::fake()->createWithContent('catatan.txt', 'bukan foto')],
            ])
            ->assertRedirect(route('face.index'))
            ->assertSessionHasErrors('photos.0');
    }

    public function test_upload_says_so_when_the_face_service_is_off(): void
    {
        Http::fake(fn () => throw new ConnectionException('layanan wajah mati'));

        $student = User::factory()->student()->create(['name' => 'Andi Pratama', 'identifier_number' => '25006']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('face.photos.store'), [
                'user_id' => $student->id,
                'photos' => [$this->photo()],
            ])
            ->assertRedirect(route('face.index'))
            ->assertSessionHas('warning', 'Tidak ada foto wajah Andi Pratama yang bisa disimpan.');

        $this->assertDatabaseCount('face_embeddings', 0);
    }

    public function test_photo_upload_is_closed_for_a_teacher(): void
    {
        $teacher = User::factory()->teacher()->create();
        $student = User::factory()->student()->create(['identifier_number' => '25007']);

        $this->actingAs($teacher)
            ->post(route('face.photos.store'), [
                'user_id' => $student->id,
                'photos' => [$this->photo()],
            ])
            ->assertRedirect(route($teacher->homeRoute()))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseCount('face_embeddings', 0);
    }

    public function test_folder_import_matches_the_folder_name_to_the_identifier(): void
    {
        $this->fakeEncoding($this->embedding(0.25));
        $student = User::factory()->student()->create(['name' => 'Andi Pratama', 'identifier_number' => '25001']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('face.photos.upload'), [
                'photo' => $this->photo(),
                'relative' => 'foto/25001/depan.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('status', FaceEnrollmentService::StatusSaved)
            ->assertJsonPath('identifier', '25001')
            ->assertJsonPath('name', 'Andi Pratama')
            ->assertJsonPath('total', 1);

        // Jalur berkas dari Windows memakai garis miring terbalik, dan itu pun
        // harus terbaca sebagai nama folder yang sama.
        $this->actingAs($admin)
            ->postJson(route('face.photos.upload'), [
                'photo' => $this->photo(),
                'relative' => 'foto\\25001\\serong.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('status', FaceEnrollmentService::StatusSaved)
            ->assertJsonPath('total', 2);

        $this->assertSame(2, FaceEmbedding::query()->where('user_id', $student->id)->count());
    }

    public function test_folder_import_reports_a_folder_that_is_not_an_identifier(): void
    {
        $this->fakeEncoding($this->embedding(0.25));

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('face.photos.upload'), [
                'photo' => $this->photo(),
                'relative' => 'foto/99999/depan.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'unknown_identifier')
            ->assertJsonPath('identifier', '99999')
            ->assertJsonPath('message', 'Folder 99999 tidak cocok dengan nomor induk anggota mana pun.');

        $this->assertDatabaseCount('face_embeddings', 0);
    }

    public function test_folder_import_skips_a_file_outside_an_identifier_folder(): void
    {
        $this->fakeEncoding($this->embedding(0.25));

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('face.photos.upload'), [
                'photo' => $this->photo(),
                'relative' => 'depan.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'unknown_identifier')
            ->assertJsonPath('message', 'Berkas ini tidak berada di dalam folder bernama nomor induk anggota, jadi dilewati.');

        $this->assertDatabaseCount('face_embeddings', 0);
    }

    public function test_a_new_member_can_be_added_together_with_a_face_photo(): void
    {
        $this->fakeEncoding($this->embedding(0.4));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('members.store'), [
                'name' => 'Andi Pratama',
                'identifier_number' => '25101',
                'email' => 'andi@tekara.test',
                'role' => User::RoleStudent,
                'class_name' => 'X TKJ 1',
                'photo' => $this->photo(),
            ])
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'tersimpan dari foto'))
            ->assertSessionMissing('warning');

        $user = User::query()->where('identifier_number', '25101')->firstOrFail();

        $this->assertSame(1, $user->faceEmbeddings()->count());
    }

    public function test_a_new_member_is_still_saved_when_the_photo_cannot_be_used(): void
    {
        Http::fake([
            '*/encode' => Http::response(['status' => 'no_face', 'encoding' => null, 'face_count' => 0]),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('members.store'), [
                'name' => 'Andi Pratama',
                'identifier_number' => '25102',
                'email' => 'andi2@tekara.test',
                'role' => User::RoleStudent,
                'class_name' => 'X TKJ 1',
                'photo' => $this->photo(),
            ])
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'Anggota sudah tersimpan'));

        $this->assertDatabaseHas('users', ['identifier_number' => '25102']);
        $this->assertDatabaseCount('face_embeddings', 0);
    }

    /**
     * Angka pembanding dengan satu nilai yang sama untuk seluruh 128 kolom.
     *
     * @return array<int, float>
     */
    private function embedding(float $value): array
    {
        return array_fill(0, FaceEmbedding::Dimensions, $value);
    }

    /**
     * Foto palsu yang isinya benar benar ada, karena pengunggah membaca byte
     * fotonya lebih dulu sebelum bertanya ke layanan wajah.
     */
    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('wajah.jpg', str_repeat('wajah', 400));
    }

    /**
     * @param  array<int, float>  $embedding
     */
    private function fakeEncoding(array $embedding): void
    {
        Http::fake([
            '*/encode' => Http::response([
                'status' => 'success',
                'encoding' => $embedding,
                'face_count' => 1,
            ]),
        ]);
    }
}
