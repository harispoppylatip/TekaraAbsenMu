<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\AttendanceReportService;
use App\Services\CsvExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menu laporan admin: rekap kehadiran siswa per jam pelajaran, rekap guru
 * mengajar, dan rekap presensi gerbang untuk rentang tanggal tertentu. Setiap
 * laporan bisa dicetak atau diunduh sebagai CSV yang terbuka di Excel.
 */
class ReportController extends Controller
{
    /** Rentang terpanjang satu laporan, supaya halaman tetap ringan. */
    public const MaxRangeDays = 366;

    public function __construct(private AttendanceReportService $reports) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('reports.index', [
            ...$filters,
            'report' => $this->build($filters),
            'classes' => SchoolClass::query()->orderedByName()->get(),
            'typeLabels' => AttendanceReportService::TypeLabels,
            'attentionRate' => AttendanceReportService::AttentionRate,
            'presets' => $this->presets(),
        ]);
    }

    /**
     * Unduh laporan yang sama persis dengan yang sedang tampil. Pemisah titik
     * koma dan BOM UTF-8 dipakai supaya Excel berbahasa Indonesia langsung
     * membaca kolom dan huruf dengan benar.
     */
    public function export(Request $request, CsvExporter $csv): StreamedResponse
    {
        $filters = $this->filters($request);
        $report = $this->build($filters);
        [$headers, $rows] = $this->table($filters['type'], $report['rows']);

        $fileName = sprintf(
            'laporan-%s-%s-sampai-%s%s.csv',
            $filters['type'],
            $filters['from']->toDateString(),
            $filters['to']->toDateString(),
            $filters['schoolClass'] === null ? '' : '-'.str($filters['schoolClass']->name)->slug(),
        );

        return $csv->download($fileName, $headers, $rows);
    }

    /**
     * Filter dibaca longgar seperti halaman lain: masukan yang tidak dikenali
     * kembali ke nilai bawaan, tanggal terbalik ditukar, dan rentang yang
     * terlalu panjang dipotong dengan pemberitahuan.
     *
     * @return array{type: string, from: Carbon, to: Carbon, schoolClass: ?SchoolClass, notice: ?string}
     */
    private function filters(Request $request): array
    {
        $type = array_key_exists((string) $request->input('jenis'), AttendanceReportService::TypeLabels)
            ? (string) $request->input('jenis')
            : AttendanceReportService::TypeStudent;

        $from = $this->date($request->input('dari')) ?? Carbon::today()->startOfMonth();
        $to = $this->date($request->input('sampai')) ?? Carbon::today();
        $notice = null;

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
            $notice = 'Tanggal awal lebih akhir dari tanggal akhir, jadi keduanya ditukar.';
        }

        if ($from->diffInDays($to) >= self::MaxRangeDays) {
            $from = $to->copy()->subDays(self::MaxRangeDays - 1);
            $notice = 'Satu laporan paling panjang '.self::MaxRangeDays.' hari, jadi rentangnya dimulai dari '.$from->format('d/m/Y').'.';
        }

        $schoolClass = $request->filled('kelas')
            ? SchoolClass::query()->find((int) $request->input('kelas'))
            : null;

        return [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'schoolClass' => $schoolClass,
            'notice' => $notice,
        ];
    }

    /**
     * @param  array{type: string, from: Carbon, to: Carbon, schoolClass: ?SchoolClass}  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, stats: array<string, int|null>}
     */
    private function build(array $filters): array
    {
        return match ($filters['type']) {
            AttendanceReportService::TypeTeacher => $this->reports->teacherReport($filters['from'], $filters['to'], $filters['schoolClass']),
            AttendanceReportService::TypeGate => $this->reports->gateReport($filters['from'], $filters['to'], $filters['schoolClass']),
            default => $this->reports->studentReport($filters['from'], $filters['to'], $filters['schoolClass']),
        };
    }

    /**
     * Kolom CSV mengikuti kolom tabel di halaman laporan.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{0: list<string>, 1: list<list<string|int>>}
     */
    private function table(string $type, $rows): array
    {
        $percent = fn (?int $rate): string => $rate === null ? '-' : $rate.'%';

        return match ($type) {
            AttendanceReportService::TypeTeacher => [
                ['Nama', 'NIP', 'Mata Pelajaran', 'Pertemuan', 'Sebagai Pengganti', 'Guru Scan', 'Guru Tidak Scan', 'Jadwal Tanpa Sesi', 'Siswa Hadir', 'Siswa Seharusnya', 'Kehadiran Siswa'],
                $rows->map(fn (array $row): array => [
                    $row['user']->name,
                    (string) $row['user']->identifier_number,
                    (string) $row['user']->subject,
                    $row['meetings'],
                    $row['substitute'],
                    $row['scanned'],
                    $row['unscanned'],
                    $row['missed'],
                    $row['studentsPresent'],
                    $row['studentsExpected'],
                    $percent($row['rate']),
                ])->all(),
            ],
            AttendanceReportService::TypeGate => [
                ['Siswa', 'Nomor Induk', 'Kelas', 'Hari Sekolah', 'Masuk', 'Tepat Waktu', 'Terlambat', 'Izin', 'Pulang', 'Tidak Hadir', 'Kehadiran'],
                $rows->map(fn (array $row): array => [
                    $row['user']->name,
                    (string) $row['user']->identifier_number,
                    $row['class'],
                    $row['schoolDays'],
                    $row['days'],
                    $row['onTime'],
                    $row['late'],
                    $row['permission'],
                    $row['checkOut'],
                    $row['absent'],
                    $percent($row['rate']),
                ])->all(),
            ],
            default => [
                ['Nama', 'Nomor Induk', 'Kelas', 'Pertemuan', 'Hadir', 'Tidak Hadir', 'Kehadiran'],
                $rows->map(fn (array $row): array => [
                    $row['user']->name,
                    (string) $row['user']->identifier_number,
                    $row['class'],
                    $row['meetings'],
                    $row['present'],
                    $row['absent'],
                    $percent($row['rate']),
                ])->all(),
            ],
        };
    }

    /**
     * Pilihan rentang cepat di atas formulir filter.
     *
     * @return array<string, array{dari: string, sampai: string}>
     */
    private function presets(): array
    {
        $today = Carbon::today();

        return [
            'Hari ini' => ['dari' => $today->toDateString(), 'sampai' => $today->toDateString()],
            'Minggu ini' => ['dari' => $today->copy()->startOfWeek()->toDateString(), 'sampai' => $today->toDateString()],
            'Bulan ini' => ['dari' => $today->copy()->startOfMonth()->toDateString(), 'sampai' => $today->toDateString()],
            'Bulan lalu' => [
                'dari' => $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'sampai' => $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
        ];
    }

    private function date(mixed $value): ?Carbon
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        }

        return null;
    }
}
