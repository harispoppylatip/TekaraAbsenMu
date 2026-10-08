<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MemberImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MemberImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();
    }

    public function test_members_page_offers_the_csv_import(): void
    {
        $this->withoutVite()
            ->get(route('members.index'))
            ->assertOk()
            ->assertSee('Tambah dari File CSV')
            ->assertSee(route('members.import.template'), false)
            ->assertSee('Unggah dan tambahkan');
    }

    public function test_template_contains_the_expected_columns(): void
    {
        $content = $this->get(route('members.import.template'))
            ->assertOk()
            ->assertDownload('contoh-impor-anggota.csv')
            ->streamedContent();

        $this->assertSame("\xEF\xBB\xBFNama;NIS;Email;Peran;Kelas;Mapel\n", $content);
    }

    public function test_excel_semicolon_file_adds_students_and_teachers(): void
    {
        $response = $this->upload("\xEF\xBB\xBF".implode("\r\n", [
            'Nama;NIS;Email;Peran;Kelas;Mapel',
            'Andi Pratama;25001;Andi@Sekolah.test;Siswa;x tkj 9;',
            'Bu Ratna;NIP-01;ratna@sekolah.test;guru;;Matematika',
            'Citra Lestari;25002;citra@sekolah.test;;X TKJ 9;',
            ';;;;;',
        ]));

        $response->assertRedirect(route('members.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_starts_with($message, '3 anggota berhasil ditambahkan dari file.'))
            ->assertSessionHas('importSkipped', []);

        $andi = User::query()->where('identifier_number', '25001')->firstOrFail();
        $this->assertSame(User::RoleStudent, $andi->role);
        $this->assertSame('andi@sekolah.test', $andi->email);
        $this->assertSame('x tkj 9', $andi->class_name);
        $this->assertTrue(Hash::check('25001', $andi->password));
        $this->assertTrue((bool) $andi->must_change_password);

        $ratna = User::query()->where('identifier_number', 'NIP-01')->firstOrFail();
        $this->assertSame(User::RoleTeacher, $ratna->role);
        $this->assertSame('Matematika', $ratna->subject);
        $this->assertNull($ratna->class_name);
        $this->assertTrue(Hash::check(User::DefaultPassword, $ratna->password));

        $this->assertSame(User::RoleStudent, User::query()->where('identifier_number', '25002')->value('role'), 'Peran kosong berarti siswa.');
        $this->assertSame(1, SchoolClass::query()->whereRaw('LOWER(name) = ?', ['x tkj 9'])->count(), 'Kelas baru terdaftar sekali saja.');
    }

    public function test_comma_file_with_other_column_names_is_understood(): void
    {
        $this->upload(implode("\n", [
            'Nama Lengkap,NIM,E-mail,Role,Kelas,Mata Pelajaran',
            '"Pratama, Andi",25001,andi@sekolah.test,student,X TKJ 1,',
        ]))->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['name' => 'Pratama, Andi', 'identifier_number' => '25001']);
    }

    public function test_problem_rows_are_skipped_with_their_reason_and_the_rest_is_saved(): void
    {
        User::factory()->student()->create(['email' => 'lama@sekolah.test', 'identifier_number' => '99999']);

        $response = $this->upload(implode("\n", [
            'Nama;NIS;Email;Peran;Kelas;Mapel',
            'Andi Pratama;25001;andi@sekolah.test;siswa;X TKJ 1;',
            'Budi Lama;25002;lama@sekolah.test;siswa;X TKJ 1;',
            'Citra Kembar;25003;andi@sekolah.test;siswa;X TKJ 1;',
            'Dedi Salah;25004;bukan-email;siswa;X TKJ 1;',
            'Eka Aneh;25005;eka@sekolah.test;kepala sekolah;;',
            'Fajar Excel;2,02041E+10;fajar@sekolah.test;siswa;X TKJ 1;',
            ';25006;tanpa.nama@sekolah.test;siswa;;',
            'Gina Pratiwi;99999;gina@sekolah.test;siswa;X TKJ 1;',
        ]));

        $response->assertRedirect(route('members.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_starts_with($message, '1 anggota berhasil ditambahkan, 7 baris dilewati.'));

        $this->assertDatabaseHas('users', ['identifier_number' => '25001']);
        $this->assertSame(3, User::query()->count(), 'Hanya admin, anggota lama, dan Andi yang ada.');

        $skipped = collect(session('importSkipped'))->keyBy('line');

        $this->assertSame([3, 4, 5, 6, 7, 8, 9], $skipped->keys()->all());
        $this->assertSame('Email lama@sekolah.test sudah dipakai anggota lain.', $skipped[3]['reason']);
        $this->assertSame('Email andi@sekolah.test sudah dipakai baris lain di file ini.', $skipped[4]['reason']);
        $this->assertSame('Email "bukan-email" tidak valid.', $skipped[5]['reason']);
        $this->assertStringStartsWith('Peran "kepala sekolah" tidak dikenal.', $skipped[6]['reason']);
        $this->assertStringContainsString('Excel memendekkan angka panjang', $skipped[7]['reason']);
        $this->assertSame('Nama kosong.', $skipped[8]['reason']);
        $this->assertSame('Tanpa nama', $skipped[8]['name']);
        $this->assertSame('NIS 99999 sudah dipakai anggota lain.', $skipped[9]['reason']);

        $this->withoutVite()
            ->get(route('members.index'))
            ->assertSee('Baris yang Dilewati')
            ->assertSee('Dedi Salah');
    }

    public function test_windows_encoded_file_keeps_accented_names(): void
    {
        $this->upload("Nama;NIS;Email\nJos\xE9 Ramos;25001;jose@sekolah.test\n")->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['name' => 'José Ramos']);
    }

    public function test_file_without_required_columns_saves_nothing(): void
    {
        $this->upload("Nama;Kelas\nAndi;X TKJ 1\n")
            ->assertRedirect(route('members.index'))
            ->assertSessionHasErrors(['file' => 'Kolom NIS, Email tidak ditemukan di baris pertama. Pakai judul kolom seperti file contoh: Nama, NIS, Email, Peran, Kelas, Mapel.']);

        $this->assertSame(1, User::query()->count());
    }

    public function test_file_with_too_many_rows_is_refused(): void
    {
        $lines = ['Nama;NIS;Email'];

        foreach (range(1, MemberImportService::MaxRows + 1) as $number) {
            $lines[] = "Siswa {$number};S{$number};s{$number}@sekolah.test";
        }

        $this->upload(implode("\n", $lines))->assertSessionHasErrors('file');

        $this->assertSame(1, User::query()->count());
    }

    public function test_only_admins_can_import(): void
    {
        $this->signInAs(User::factory()->teacher()->create());

        $this->upload("Nama;NIS;Email\nAndi;25001;andi@sekolah.test\n")->assertRedirect(route('teacher.index'));

        $this->assertDatabaseMissing('users', ['identifier_number' => '25001']);
    }

    private function upload(string $content): TestResponse
    {
        return $this->post(route('members.import'), [
            'file' => UploadedFile::fake()->createWithContent('anggota.csv', $content),
        ]);
    }
}
