<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceWindow;
use App\Models\Device;
use App\Models\FaceEmbedding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Absen wajah: pendaftaran sidik wajah lewat API, pengenalan wajah dari kamera
 * petugas, dan halaman pengelolaan wajah.
 *
 * Layanan wajah Python tidak dijalankan di dalam tes. Yang diuji di sini adalah
 * cara aplikasi memperlakukan balasan layanan itu, jadi balasannya dipalsukan
 * dengan bentuk yang sama persis seperti aslinya.
 */
class FaceAttendanceTest extends TestCase
{
    use RefreshDatabase;

    /** Id perangkat kamera wajah, sama dengan bawaan halaman Alat Sensor. */
    private const DeviceId = 'kamera-wajah';

    /** Jam pulang yang dipakai tes supaya hasilnya tidak bergantung jam asli. */
    private const CheckOutAt = '2026-10-08 13:30:00';

    public function test_registration_api_stores_an_embedding_for_a_registered_device(): void
    {
        $this->pairedDevice(self::DeviceId);
        $student = User::factory()->student()->create(['identifier_number' => 'NIS-1001']);

        $this->postJson('/api/face/register', [
            'device_id' => self::DeviceId,
            'user_id' => 'NIS-1001',
            'embedding' => $this->embedding(0.25),
            'label' => 'depan',
        ])->assertCreated()->assertJsonPath('status', 'success')->assertJsonPath('data.total', 1);

        $this->assertDatabaseHas('face_embeddings', [
            'user_id' => $student->id,
            'label' => 'depan',
            'model' => FaceEmbedding::DefaultModel,
        ]);
    }

    public function test_registration_api_replaces_older_photos_only_when_asked(): void
    {
        $this->pairedDevice(self::DeviceId);
        $student = User::factory()->student()->create(['identifier_number' => 'NIS-1002']);

        // Dua kali kirim tanpa replace berarti dua sudut wajah tersimpan.
        $this->registerEmbedding('NIS-1002', 'depan');
        $this->registerEmbedding('NIS-1002', 'kiri');

        $this->assertSame(2, FaceEmbedding::query()->where('user_id', $student->id)->count());

        // Kirim ulang dengan replace berarti wajah lama diganti, bukan ditumpuk.
        $this->registerEmbedding('NIS-1002', 'depan', replace: true);

        $this->assertSame(1, FaceEmbedding::query()->where('user_id', $student->id)->count());
        $this->assertSame('depan', FaceEmbedding::query()->where('user_id', $student->id)->value('label'));
    }

    public function test_registration_api_rejects_a_device_that_is_not_registered(): void
    {
        User::factory()->student()->create(['identifier_number' => 'NIS-1003']);

        $response = $this->postJson('/api/face/register', [
            'device_id' => 'kamera-belum-didaftarkan',
            'user_id' => 'NIS-1003',
            'embedding' => $this->embedding(0.25),
        ]);

        $response->assertForbidden()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('display_message', 'Belum terdaftar');

        $this->assertDatabaseCount('face_embeddings', 0);
    }

    public function test_registration_api_rejects_an_embedding_that_is_not_the_right_size(): void
    {
        $this->pairedDevice(self::DeviceId);
        User::factory()->student()->create(['identifier_number' => 'NIS-1004']);

        $this->postJson('/api/face/register', [
            'device_id' => self::DeviceId,
            'user_id' => 'NIS-1004',
            'embedding' => [0.1, 0.2, 0.3],
        ])->assertStatus(422)->assertJsonValidationErrors('embedding');
    }

    public function test_registration_api_rejects_an_unknown_identifier(): void
    {
        $this->pairedDevice(self::DeviceId);

        $this->postJson('/api/face/register', [
            'device_id' => self::DeviceId,
            'user_id' => 'NIS-TIDAK-ADA',
            'embedding' => $this->embedding(0.25),
        ])->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_face_scan_records_check_out_during_the_pulang_window(): void
    {
        $this->travelTo(Carbon::parse(self::CheckOutAt));
        $this->pairedDevice(self::DeviceId);
        $this->attendanceWindows();

        $student = User::factory()->student()->create(['identifier_number' => 'NIS-2001']);
        FaceEmbedding::create(['user_id' => $student->id, 'embedding' => $this->embedding(0.5), 'label' => 'depan']);

        $this->fakeEncoding($this->embedding(0.5));

        $response = $this->actingAs($this->cameraOperator())
            ->postJson(route('face.attendance.scan'), ['image' => $this->photo()]);

        $response->assertOk()
            ->assertJsonPath('identified', true)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('name', $student->name)
            ->assertJsonPath('user_id', 'NIS-2001')
            ->assertJsonPath('attendance_type', AttendanceLog::TypeOut);

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $student->id,
            'device_id' => self::DeviceId,
            'type' => AttendanceLog::TypeOut,
            'status' => AttendanceLog::StatusPresent,
            'source' => AttendanceLog::SourceGate,
        ]);
    }

    public function test_face_scan_reports_the_same_person_twice(): void
    {
        $this->travelTo(Carbon::parse(self::CheckOutAt));
        $this->pairedDevice(self::DeviceId);
        $this->attendanceWindows();

        $student = User::factory()->student()->create(['identifier_number' => 'NIS-2002']);
        FaceEmbedding::create(['user_id' => $student->id, 'embedding' => $this->embedding(0.5), 'label' => 'depan']);

        $this->fakeEncoding($this->embedding(0.5));

        $operator = $this->cameraOperator();

        $this->actingAs($operator)
            ->postJson(route('face.attendance.scan'), ['image' => $this->photo()])
            ->assertJsonPath('recorded', true);

        $this->actingAs($operator)
            ->postJson(route('face.attendance.scan'), ['image' => $this->photo()])
            ->assertJsonPath('identified', true)
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('message', 'Anda sudah absen pulang pukul 13:30.');

        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_face_scan_leaves_a_face_that_is_not_close_enough(): void
    {
        $this->travelTo(Carbon::parse(self::CheckOutAt));
        $this->pairedDevice(self::DeviceId);
        $this->attendanceWindows();

        $student = User::factory()->student()->create(['identifier_number' => 'NIS-2003']);
        FaceEmbedding::create(['user_id' => $student->id, 'embedding' => $this->embedding(0.0)]);

        // Semua angka berbeda jauh, jaraknya lebih besar daripada batas 0,6.
        $this->fakeEncoding($this->embedding(1.0));

        $this->actingAs($this->cameraOperator())
            ->postJson(route('face.attendance.scan'), ['image' => $this->photo()])
            ->assertOk()
            ->assertJsonPath('identified', false)
            ->assertJsonPath('recorded', false);

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_face_scan_reports_when_no_face_is_visible(): void
    {
        $this->pairedDevice(self::DeviceId);

        Http::fake([
            '*/encode' => Http::response(['status' => 'no_face', 'encoding' => null, 'face_count' => 0]),
        ]);

        $this->actingAs($this->cameraOperator())
            ->postJson(route('face.attendance.scan'), ['image' => $this->photo()])
            ->assertOk()
            ->assertJsonPath('identified', false)
            ->assertJsonPath('message', 'Wajah tidak terlihat. Dekatkan wajah ke kamera dan pastikan cahaya cukup.');
    }

    public function test_face_scan_says_so_when_the_face_service_is_off(): void
    {
        $this->pairedDevice(self::DeviceId);

        Http::fake(fn () => throw new ConnectionException('layanan wajah mati'));

        $this->actingAs($this->cameraOperator())
            ->postJson(route('face.attendance.scan'), ['image' => $this->photo()])
            ->assertStatus(503)
            ->assertJsonPath('identified', false)
            ->assertJsonPath('message', 'Layanan wajah di server belum aktif. Minta operator menyalakan layanan wajah, lalu coba lagi.');
    }

    public function test_face_scan_is_refused_while_the_camera_device_is_switched_off(): void
    {
        $this->travelTo(Carbon::parse(self::CheckOutAt));
        $this->pairedDevice(self::DeviceId, Device::StatusInactive);
        $this->attendanceWindows();

        $student = User::factory()->student()->create(['identifier_number' => 'NIS-2004']);
        FaceEmbedding::create(['user_id' => $student->id, 'embedding' => $this->embedding(0.5)]);

        $this->fakeEncoding($this->embedding(0.5));

        $this->actingAs($this->cameraOperator())
            ->postJson(route('face.attendance.scan'), ['image' => $this->photo()])
            ->assertForbidden()
            ->assertJsonPath('message', 'Perangkat sedang tidak aktif. Nyalakan perangkat di halaman Alat Sensor sebelum dipakai untuk presensi.');

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_face_camera_page_loads_for_an_operator(): void
    {
        $this->pairedDevice(self::DeviceId);

        $this->withoutVite()
            ->actingAs($this->cameraOperator())
            ->get(route('face.attendance'))
            ->assertOk()
            ->assertSee('Kamera Absen Wajah')
            ->assertSee('Nyalakan kamera');
    }

    public function test_face_camera_page_explains_a_switched_off_device(): void
    {
        $this->pairedDevice(self::DeviceId, Device::StatusInactive);

        $this->withoutVite()
            ->actingAs($this->cameraOperator())
            ->get(route('face.attendance'))
            ->assertOk()
            ->assertSee('Perangkat sedang tidak aktif.')
            ->assertSee('Perangkat belum siap');
    }

    public function test_face_camera_page_is_closed_for_a_teacher_without_the_task(): void
    {
        $teacher = User::factory()->teacher()->create(['can_scan_face' => false]);

        $this->actingAs($teacher)
            ->get(route('face.attendance'))
            ->assertRedirect(route($teacher->homeRoute()));

        // Pesannya harus benar benar terlihat di halaman utama guru, bukan hanya
        // tersimpan di sesi.
        $this->withoutVite()
            ->followingRedirects()
            ->actingAs($teacher)
            ->get(route('face.attendance'))
            ->assertOk()
            ->assertSee('Halaman kamera absen wajah hanya untuk petugas yang ditunjuk admin.');
    }

    public function test_face_scan_api_refuses_a_teacher_without_the_task(): void
    {
        $this->pairedDevice(self::DeviceId);
        $teacher = User::factory()->teacher()->create(['can_scan_face' => false]);

        $this->actingAs($teacher)
            ->postJson(route('face.attendance.scan'), ['image' => $this->photo()])
            ->assertForbidden()
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('display_message', 'Bukan petugas');
    }

    public function test_admin_sees_who_has_a_face_and_who_may_hold_the_camera(): void
    {
        $this->pairedDevice(self::DeviceId);
        $student = User::factory()->student()->create(['name' => 'Andi Pratama', 'identifier_number' => 'NIS-3001']);
        $teacher = User::factory()->teacher()->create(['name' => 'Bu Ratna', 'can_scan_face' => false]);
        FaceEmbedding::create(['user_id' => $student->id, 'embedding' => $this->embedding(0.2), 'label' => 'depan']);

        $this->withoutVite()
            ->actingAs(User::factory()->admin()->create())
            ->get(route('face.index'))
            ->assertOk()
            ->assertSee('Wajah Terdaftar')
            ->assertSee('Andi Pratama')
            ->assertSee('NIS-3001')
            ->assertSee('Bu Ratna');

        $this->put(route('face.operators.toggle', $teacher))->assertRedirect(route('face.index'));

        $this->assertTrue($teacher->refresh()->canScanFace());
    }

    public function test_admin_can_remove_one_photo_or_every_photo_of_a_member(): void
    {
        $this->pairedDevice(self::DeviceId);
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create(['identifier_number' => 'NIS-3002']);

        $first = FaceEmbedding::create(['user_id' => $student->id, 'embedding' => $this->embedding(0.2), 'label' => 'depan']);
        FaceEmbedding::create(['user_id' => $student->id, 'embedding' => $this->embedding(0.3), 'label' => 'kiri']);

        $this->actingAs($admin)
            ->delete(route('face.embeddings.destroy', $first))
            ->assertRedirect(route('face.index'));

        $this->assertSame(1, FaceEmbedding::query()->where('user_id', $student->id)->count());

        $this->actingAs($admin)
            ->delete(route('face.users.destroy', $student))
            ->assertRedirect(route('face.index'));

        $this->assertSame(0, FaceEmbedding::query()->where('user_id', $student->id)->count());
    }

    public function test_face_data_page_is_closed_for_a_teacher(): void
    {
        $teacher = User::factory()->teacher()->create();

        // Halaman pengelolaan wajah khusus admin, jadi guru dipulangkan ke
        // halaman utamanya lebih dulu, bukan ditolak di halaman itu sendiri.
        $this->actingAs($teacher)
            ->get(route('face.index'))
            ->assertRedirect(route($teacher->homeRoute()))
            ->assertSessionHasErrors('role');
    }

    public function test_face_data_page_says_when_the_face_service_is_off(): void
    {
        $this->pairedDevice(self::DeviceId);

        Http::fake(['*/health' => Http::response([], 500)]);

        $this->withoutVite()
            ->actingAs(User::factory()->admin()->create())
            ->get(route('face.index'))
            ->assertOk()
            ->assertSee('Layanan wajah di server belum aktif');
    }

    /**
     * Angka pembanding dengan satu nilai yang sama untuk seluruh 128 kolom.
     * Dua angka yang sama menghasilkan jarak 0, jadi wajahnya dianggap cocok.
     *
     * @return array<int, float>
     */
    private function embedding(float $value): array
    {
        return array_fill(0, FaceEmbedding::Dimensions, $value);
    }

    /**
     * Foto palsu untuk diunggah.
     *
     * Isi berkasnya harus benar benar ada, karena pengunggah membaca byte
     * fotonya lebih dulu sebelum menanyakan ke layanan wajah. Foto buatan yang
     * kosong akan berhenti di "Foto tidak terbaca" dan tesnya tidak menguji apa
     * yang dimaksudkan.
     */
    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('wajah.jpg', str_repeat('wajah', 400));
    }

    /**
     * Balasan layanan wajah untuk satu angka pembanding tertentu.
     *
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

    private function registerEmbedding(string $identifier, string $label, bool $replace = false): void
    {
        $this->postJson('/api/face/register', [
            'device_id' => self::DeviceId,
            'user_id' => $identifier,
            'embedding' => $this->embedding(0.25),
            'label' => $label,
            'replace' => $replace,
        ])->assertCreated();
    }

    /**
     * Guru yang ditugaskan memegang kamera absen wajah.
     */
    private function cameraOperator(): User
    {
        return User::factory()->teacher()->create(['can_scan_face' => true]);
    }

    private function pairedDevice(string $deviceId, string $status = Device::StatusActive): Device
    {
        return Device::create([
            'device_id' => $deviceId,
            'name' => 'Kamera Wajah',
            'location' => 'Ruang piket',
            'status' => $status,
        ]);
    }

    /**
     * Jam presensi masuk pagi dan jam pulang siang, supaya absen pulang benar
     * benar dicatat sebagai pulang dan bukan masuk.
     */
    private function attendanceWindows(): void
    {
        AttendanceWindow::create(['kind' => AttendanceWindow::KindPresent, 'starts_at' => '06:00', 'ends_at' => '07:00']);
        AttendanceWindow::create(['kind' => AttendanceWindow::KindCheckOut, 'starts_at' => '13:00', 'ends_at' => '15:00']);
    }
}
