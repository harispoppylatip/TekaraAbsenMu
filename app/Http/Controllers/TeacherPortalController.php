<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\CsvExporter;
use App\Services\LessonSessionService;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman mengajar guru. Semua data di sini hanya milik guru yang sedang masuk,
 * jadi tidak ada guru yang bisa melihat atau membuka kelas guru lain.
 *
 * Guru membuka absen saat jam pelajarannya berjalan, menutupnya setelah selesai,
 * lalu melihat siapa saja yang sudah scan di jam itu.
 */
class TeacherPortalController extends Controller
{
    /**
     * Kolom CSV rekap: satu baris untuk setiap siswa di setiap pertemuan.
     *
     * @var list<string>
     */
    private const RecapCsvHeaders = [
        'Tanggal',
        'Hari',
        'Jam',
        'Pukul',
        'Kelas',
        'Mata Pelajaran',
        'Guru',
        'Nama Siswa',
        'Nomor Induk',
        'Status',
        'Jam Scan',
    ];

    public function __construct(private LessonSessionService $service) {}

    public function index(Request $request): View
    {
        $teacher = $request->user();
        $today = Carbon::now();
        $day = (int) $today->dayOfWeekIso;

        $schedules = LessonSchedule::query()
            ->with([
                'lessonHour',
                'schoolClass',
                'teacher',
                'sessions' => fn ($query) => $query->whereDate('date', $today->toDateString()),
            ])
            ->where('user_id', $teacher->getKey())
            ->ordered()
            ->get();

        $todaySchedules = $schedules->where('day', $day)->values();

        $todaySessions = LessonSession::query()
            ->with(['lessonSchedule.lessonHour', 'lessonSchedule.schoolClass', 'substitute'])
            ->whereDate('date', $today->toDateString())
            ->where(function ($query) use ($teacher): void {
                $query->whereHas('lessonSchedule', fn ($inner) => $inner->where('user_id', $teacher->getKey()))
                    ->orWhere('substitute_user_id', $teacher->getKey());
            })
            ->orderBy('opened_at')
            ->get();

        $recentLogs = AttendanceLog::query()
            ->with(['user', 'lessonSession.lessonSchedule.lessonHour'])
            ->whereIn('lesson_session_id', $todaySessions->pluck('id'))
            ->latest('scanned_at')
            ->limit(12)
            ->get();

        return view('teacher.dashboard', [
            'today' => $today,
            'day' => $day,
            'todaySchedules' => $todaySchedules,
            'weekSchedules' => $schedules,
            'todaySessions' => $todaySessions,
            'recentLogs' => $recentLogs,
            'stats' => [
                'jadwalHariIni' => $todaySchedules->count(),
                'jadwalMinggu' => $schedules->count(),
                'dibuka' => $todaySessions->filter(fn (LessonSession $session): bool => $session->isOpen())->count(),
                'scan' => $todaySessions->sum(fn (LessonSession $session): int => $session->logs()->count()),
            ],
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lesson_schedule_id' => ['required', 'integer', Rule::exists('lesson_schedules', 'id')],
            'note' => ['nullable', 'string', 'max:100'],
        ], [
            'lesson_schedule_id.required' => 'Pilih dulu jam pelajaran yang mau dibuka.',
            'lesson_schedule_id.exists' => 'Jadwal itu tidak ditemukan.',
            'note.max' => 'Keterangan paling banyak 100 karakter.',
        ]);

        $schedule = LessonSchedule::query()->findOrFail($validated['lesson_schedule_id']);

        $result = $this->service->open(
            $request->user(),
            $schedule,
            Carbon::now(),
            null,
            $validated['note'] ?? null,
        );

        return to_route('teacher.index')->with(
            $result['opened'] ? 'success' : 'warning',
            $result['message'],
        );
    }

    public function close(Request $request, LessonSession $session): RedirectResponse
    {
        $refusal = $this->refusal($request, $session);

        if ($refusal !== null) {
            return $refusal;
        }

        $result = $this->service->close($request->user(), $session);

        return to_route('teacher.index')->with($result['closed'] ? 'success' : 'warning', $result['message']);
    }

    public function recap(Request $request, LessonSession $session): View|RedirectResponse
    {
        $refusal = $this->refusal($request, $session);

        if ($refusal !== null) {
            return $refusal;
        }

        return view('teacher.recap', [
            'session' => $session,
            'recap' => $this->service->recap($session),
        ]);
    }

    /**
     * Riwayat mengajar guru: seluruh pertemuan yang pernah dia buka beserta
     * jumlah siswa yang hadir di tiap jam.
     */
    public function recaps(Request $request): View
    {
        $teacher = $request->user();

        $sessions = $this->teacherSessions($teacher)
            ->with(['lessonSchedule.lessonHour', 'lessonSchedule.schoolClass', 'substitute'])
            ->withCount('logs')
            ->orderByDesc('date')
            ->orderByDesc('opened_at')
            ->limit(60)
            ->get();

        return view('teacher.recaps', [
            'sessions' => $sessions,
            'classes' => $this->taughtClasses($teacher),
        ]);
    }

    /**
     * Unduh daftar hadir semua pertemuan yang dipegang guru ini. Dengan
     * `?kelas=` hanya pertemuan kelas itu yang diunduh.
     */
    public function exportRecaps(Request $request, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        $teacher = $request->user();
        $schoolClass = null;

        if ($request->filled('kelas')) {
            $schoolClass = $this->taughtClasses($teacher)->firstWhere('id', (int) $request->input('kelas'));

            if ($schoolClass === null) {
                return to_route('teacher.recaps')->withErrors([
                    'kelas' => 'Kelas itu tidak ada di pertemuan yang pernah Anda pegang.',
                ]);
            }
        }

        $sessions = $this->teacherSessions($teacher)
            ->when($schoolClass !== null, fn ($query) => $query->whereHas(
                'lessonSchedule',
                fn ($inner) => $inner->where('school_class_id', $schoolClass->getKey()),
            ))
            ->orderBy('date')
            ->orderBy('opened_at')
            ->get();

        $fileName = sprintf(
            'rekap-%s-%s-%s.csv',
            str($teacher->name)->slug(),
            $schoolClass === null ? 'semua-kelas' : str($schoolClass->name)->slug(),
            Carbon::today()->toDateString(),
        );

        return $csv->download($fileName, self::RecapCsvHeaders, $this->recapCsvRows($sessions));
    }

    /**
     * Unduh daftar hadir satu pertemuan saja.
     */
    public function exportRecap(Request $request, LessonSession $session, CsvExporter $csv): StreamedResponse|RedirectResponse
    {
        $refusal = $this->refusal($request, $session);

        if ($refusal !== null) {
            return $refusal;
        }

        $fileName = sprintf(
            'rekap-%s-%s-%s.csv',
            str($session->schoolClassName() ?? 'kelas')->slug(),
            str($session->lessonSchedule?->subject ?? 'pelajaran')->slug(),
            $session->date->toDateString(),
        );

        return $csv->download($fileName, self::RecapCsvHeaders, $this->recapCsvRows(collect([$session])));
    }

    /**
     * Baris CSV dari daftar hadir tiap pertemuan, memakai rekap yang sama
     * dengan halaman rekap supaya isi file sama persis dengan layar.
     *
     * @param  iterable<int, LessonSession>  $sessions
     * @return Generator<int, list<string>>
     */
    private function recapCsvRows(iterable $sessions): Generator
    {
        foreach ($sessions as $session) {
            $recap = $this->service->recap($session);

            foreach ($recap['rows'] as $row) {
                yield [
                    $session->date->format('d/m/Y'),
                    $session->dayLabel(),
                    $session->lessonHour()?->label() ?? '-',
                    $session->range(),
                    $session->schoolClassName() ?? '-',
                    $session->lessonSchedule?->subject ?? '-',
                    $session->teacherName(),
                    $row['user']->name,
                    (string) $row['user']->identifier_number,
                    $row['present'] ? 'Hadir' : 'Belum hadir',
                    $row['log']?->scanned_at?->format('H:i:s') ?? '-',
                ];
            }
        }
    }

    /**
     * Pertemuan yang dipegang guru ini, sebagai pengampu jadwal atau sebagai
     * guru pengganti.
     *
     * @return Builder<LessonSession>
     */
    private function teacherSessions(User $teacher): Builder
    {
        return LessonSession::query()->where(function ($query) use ($teacher): void {
            $query->whereHas('lessonSchedule', fn ($inner) => $inner->where('user_id', $teacher->getKey()))
                ->orWhere('substitute_user_id', $teacher->getKey());
        });
    }

    /**
     * Kelas yang pernah diajar guru ini, untuk pilihan unduh per kelas.
     *
     * @return Collection<int, SchoolClass>
     */
    private function taughtClasses(User $teacher): Collection
    {
        return SchoolClass::query()
            ->whereIn('id', LessonSchedule::query()
                ->whereIn('id', $this->teacherSessions($teacher)->select('lesson_schedule_id'))
                ->select('school_class_id'))
            ->orderedByName()
            ->get();
    }

    /**
     * Hanya guru yang bertanggung jawab pada pertemuan itu yang boleh menutup
     * atau membuka daftar hadirnya.
     */
    private function refusal(Request $request, LessonSession $session): ?RedirectResponse
    {
        $teacherId = $request->user()->getKey();

        if ($session->teacherUserId() === (int) $teacherId || $request->user()->isAdmin()) {
            return null;
        }

        return to_route('teacher.index')->withErrors([
            'session' => 'Pertemuan itu bukan milik Anda, jadi tidak bisa dibuka dari akun ini.',
        ]);
    }
}
