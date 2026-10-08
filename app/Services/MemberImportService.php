<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Tambah banyak anggota sekaligus dari file CSV hasil Excel.
 *
 * Aturannya sama dengan formulir tambah anggota. Baris yang lolos langsung
 * disimpan, baris yang bermasalah dilewati dan dilaporkan dengan nomor baris
 * serta alasannya, supaya admin cukup memperbaiki baris itu saja lalu
 * mengunggah ulang.
 */
class MemberImportService
{
    /** Batas baris per unggahan, supaya satu unggahan tidak terlalu lama. */
    public const MaxRows = 500;

    /** Kolom file contoh, urut seperti yang diisi admin. */
    public const TemplateHeaders = ['Nama', 'NIS', 'Email', 'Peran', 'Kelas', 'Mapel'];

    /**
     * Nama kolom yang dikenali, ditulis dalam huruf kecil tanpa spasi ganda.
     *
     * @var array<string, list<string>>
     */
    private const HeaderAliases = [
        'name' => ['nama', 'nama lengkap', 'name', 'nama siswa', 'nama guru'],
        'identifier_number' => ['nis', 'nisn', 'nim', 'nip', 'nomor induk', 'no induk', 'no. induk', 'nomor induk (nis / nip)'],
        'email' => ['email', 'e-mail', 'surel', 'alamat email'],
        'role' => ['peran', 'role', 'status', 'jabatan'],
        'class_name' => ['kelas', 'class', 'rombel'],
        'subject' => ['mapel', 'mata pelajaran', 'pelajaran', 'subject'],
    ];

    /** @var array<string, string> */
    private const RoleAliases = [
        'siswa' => User::RoleStudent,
        'murid' => User::RoleStudent,
        'pelajar' => User::RoleStudent,
        'student' => User::RoleStudent,
        'guru' => User::RoleTeacher,
        'pengajar' => User::RoleTeacher,
        'teacher' => User::RoleTeacher,
        'admin' => User::RoleAdmin,
        'administrator' => User::RoleAdmin,
    ];

    /**
     * @return array{
     *     created: list<User>,
     *     skipped: list<array{line: int, name: string, reason: string}>,
     *     error: ?string
     * }
     */
    public function import(string $path): array
    {
        $rows = $this->readRows($path);

        if (is_string($rows)) {
            return ['created' => [], 'skipped' => [], 'error' => $rows];
        }

        // Kata sandi bawaan di-hash per anggota, jadi ratusan baris butuh
        // waktu lebih lama dari batas waktu permintaan biasa.
        set_time_limit(0);

        $created = [];
        $skipped = [];
        $seenEmails = [];
        $seenNumbers = [];

        foreach ($rows as $line => $row) {
            $data = $this->normalize($row);
            $label = $data['name'] !== '' ? $data['name'] : 'Tanpa nama';

            $reason = $this->rowProblem($data, $seenEmails, $seenNumbers);

            if ($reason !== null) {
                $skipped[] = ['line' => $line, 'name' => $label, 'reason' => $reason];

                continue;
            }

            $seenEmails[] = mb_strtolower($data['email']);
            $seenNumbers[] = mb_strtolower($data['identifier_number']);

            $created[] = User::create(User::applyStudyFields($data) + [
                'password' => User::defaultPasswordFor($data['role'], $data['identifier_number']),
                'must_change_password' => true,
                'status' => User::StatusActive,
            ]);
        }

        return ['created' => $created, 'skipped' => $skipped, 'error' => null];
    }

    /**
     * Baca file menjadi baris bernomor (nomor baris sesuai tampilan Excel,
     * baris 1 = judul kolom). Mengembalikan pesan kalau file tidak bisa dipakai.
     *
     * @return array<int, array<string, string>>|string
     */
    private function readRows(string $path): array|string
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        // Excel di Windows sering menyimpan CSV bukan dalam UTF-8.
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $headerLine = $lines[0] ?? '';

        if (trim($headerLine) === '') {
            return 'File kosong. Isi baris pertama dengan judul kolom seperti file contoh.';
        }

        $delimiter = $this->delimiter($headerLine);
        $headers = array_map(fn (?string $header): ?string => $this->field(mb_strtolower(trim((string) $header))), str_getcsv($headerLine, $delimiter, '"', ''));

        $missing = array_diff(['name', 'identifier_number', 'email'], $headers);

        if ($missing !== []) {
            $labels = ['name' => 'Nama', 'identifier_number' => 'NIS', 'email' => 'Email'];

            return 'Kolom '.implode(', ', array_map(fn (string $key): string => $labels[$key], $missing)).' tidak ditemukan di baris pertama. Pakai judul kolom seperti file contoh: '.implode(', ', self::TemplateHeaders).'.';
        }

        $rows = [];

        foreach (array_slice($lines, 1, null, true) as $index => $line) {
            if (trim(str_replace($delimiter, '', $line)) === '') {
                continue;
            }

            $values = str_getcsv($line, $delimiter, '"', '');
            $row = [];

            foreach ($headers as $position => $key) {
                if ($key !== null) {
                    $row[$key] = trim((string) ($values[$position] ?? ''));
                }
            }

            $rows[$index + 1] = $row;
        }

        if ($rows === []) {
            return 'File hanya berisi judul kolom. Isi data anggota mulai baris kedua.';
        }

        if (count($rows) > self::MaxRows) {
            return 'File berisi '.count($rows).' baris. Satu unggahan paling banyak '.self::MaxRows.' anggota, jadi bagi filenya menjadi beberapa bagian.';
        }

        return $rows;
    }

    /**
     * Pemisah kolom ditebak dari baris judul: Excel berbahasa Indonesia
     * memakai titik koma, Excel lain memakai koma.
     */
    private function delimiter(string $headerLine): string
    {
        $counts = [';' => substr_count($headerLine, ';'), ',' => substr_count($headerLine, ','), "\t" => substr_count($headerLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function field(string $header): ?string
    {
        $header = (string) preg_replace('/\s+/', ' ', $header);

        foreach (self::HeaderAliases as $field => $aliases) {
            if (in_array($header, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{name: string, identifier_number: string, email: string, role: string, class_name: ?string, subject: ?string, role_input: string}
     */
    private function normalize(array $row): array
    {
        $roleInput = mb_strtolower(trim($row['role'] ?? ''));

        return [
            'name' => (string) preg_replace('/\s+/', ' ', $row['name'] ?? ''),
            'identifier_number' => $row['identifier_number'] ?? '',
            'email' => mb_strtolower($row['email'] ?? ''),
            'role' => $roleInput === '' ? User::RoleStudent : (self::RoleAliases[$roleInput] ?? $roleInput),
            'class_name' => ($row['class_name'] ?? '') === '' ? null : $row['class_name'],
            'subject' => ($row['subject'] ?? '') === '' ? null : $row['subject'],
            'role_input' => $roleInput,
        ];
    }

    /**
     * Alasan baris dilewati, dalam bahasa sehari-hari. Null kalau baris aman.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $seenEmails
     * @param  list<string>  $seenNumbers
     */
    private function rowProblem(array &$data, array $seenEmails, array $seenNumbers): ?string
    {
        if (preg_match('/^\d+([.,]\d+)?E\+?\d+$/i', $data['identifier_number']) === 1) {
            return 'NIS terbaca "'.$data['identifier_number'].'" karena Excel memendekkan angka panjang. Di Excel, ubah format kolom NIS menjadi Teks lalu ketik ulang NIS-nya.';
        }

        if (! in_array($data['role'], User::Roles, true)) {
            return 'Peran "'.$data['role_input'].'" tidak dikenal. Isi dengan siswa, guru, atau admin.';
        }

        if (in_array(mb_strtolower($data['email']), $seenEmails, true)) {
            return 'Email '.$data['email'].' sudah dipakai baris lain di file ini.';
        }

        if (in_array(mb_strtolower($data['identifier_number']), $seenNumbers, true)) {
            return 'NIS '.$data['identifier_number'].' sudah dipakai baris lain di file ini.';
        }

        unset($data['role_input']);

        $validator = Validator::make($data, User::memberRules(), [
            'name.required' => 'Nama kosong.',
            'name.max' => 'Nama terlalu panjang (paling banyak 100 huruf).',
            'email.required' => 'Email kosong.',
            'email.email' => 'Email "'.$data['email'].'" tidak valid.',
            'email.unique' => 'Email '.$data['email'].' sudah dipakai anggota lain.',
            'identifier_number.required' => 'NIS kosong.',
            'identifier_number.max' => 'NIS terlalu panjang (paling banyak 50 karakter).',
            'identifier_number.unique' => 'NIS '.$data['identifier_number'].' sudah dipakai anggota lain.',
            'class_name.max' => 'Nama kelas terlalu panjang (paling banyak 50 huruf).',
            'subject.max' => 'Nama mata pelajaran terlalu panjang (paling banyak 50 huruf).',
        ]);

        return $validator->fails() ? $validator->errors()->first() : null;
    }
}
