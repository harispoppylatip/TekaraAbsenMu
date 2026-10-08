<?php

namespace App\Http\Controllers;

use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\LessonSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pemantauan sesi absen bagi admin. Guru membuka absennya sendiri dari halaman
 * mengajar, sedangkan admin di sini bisa membuka, menutup, membuka kembali,
 * menunjuk guru pengganti, dan menghapus sesi kapan saja untuk tanggal mana pun.
 */
class LessonSessionController extends Controller
{
    public function __construct(private LessonSessionService $service) {}

    public function index(Request $request): View
    {
        $date = $this->dateFilter($request);
        $classes = SchoolClass::query()->orderedByName()->get();
        $classId = $request->filled('class') ? (int) $request->input('class') : null;
        $status = in_array($request->input('status'), ['open', 'closed', 'scheduled'], true)
            ? $request->input('status')
            : null;

        $sessions = LessonSession::query()
            ->with([
                'lessonSchedule.lessonHour',
                'lessonSchedule.schoolClass',
                'lessonSchedule.teacher',
                'substitute',
                'openedBy',
                'closedBy',
            ])
            ->withCount('logs')
            ->whereDate('date', $date->toDateString())
            ->when($classId !== null, fn ($query) => $query->whereHas(
                'lessonSchedule',
                fn ($inner) => $inner->where('school_class_id', $classId),
            ))
            ->when($status === 'open', fn ($query) => $query->whereNotNull('opened_at')->whereNull('closed_at'))
            ->when($status === 'closed', fn ($query) => $query->whereNotNull('closed_at'))
            ->when($status === 'scheduled', fn ($query) => $query->whereNull('opened_at')->whereNotNull('scheduled_at'))
            ->orderBy('opened_at')
            ->get();

        $availableSchedules = LessonSchedule::query()
            ->with(['lessonHour', 'schoolClass', 'teacher'])
            ->where('day', $date->dayOfWeekIso)
            ->whereNotIn('id', $sessions->pluck('lesson_schedule_id'))
            ->ordered()
            ->get();

        return view('sessions.index', [
            'sessions' => $sessions,
            'availableSchedules' => $availableSchedules,
            'classes' => $classes,
            'teachers' => User::query()->where('role', User::RoleTeacher)->orderBy('name')->get(),
            'schedules' => LessonSchedule::query()
                ->with(['lessonHour', 'schoolClass', 'teacher'])
                ->ordered()
                ->get()
                ->groupBy('day'),
            'dayNames' => LessonSchedule::DayNames,
            'date' => $date,
            'filters' => ['class' => $classId, 'status' => $status],
            'stats' => [
                'total' => $sessions->count(),
                'open' => $sessions->filter(fn (LessonSession $session): bool => $session->isOpen())->count(),
                'scheduled' => $sessions->filter(fn (LessonSession $session): bool => $session->isScheduled())->count(),
                'scans' => $sessions->sum('logs_count'),
            ],
        ]);
    }

    public function show(LessonSession $session): View
    {
        return view('sessions.show', [
            'session' => $session,
            'recap' => $this->service->recap($session),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lesson_schedule_id' => ['required', 'integer', Rule::exists('lesson_schedules', 'id')],
            'date' => ['required', 'date_format:Y-m-d'],
            'substitute_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', User::RoleTeacher)],
            'note' => ['nullable', 'string', 'max:100'],
        ], [
            'lesson_schedule_id.required' => 'Jadwal pelajaran wajib dipilih.',
            'lesson_schedule_id.exists' => 'Jadwal itu tidak ditemukan.',
            'date.required' => 'Tanggal pertemuan wajib diisi.',
            'date.date_format' => 'Tanggal memakai format 2026-09-14.',
            'substitute_user_id.exists' => 'Guru pengganti itu tidak ditemukan atau bukan berperan guru.',
            'note.max' => 'Keterangan paling banyak 100 karakter.',
        ]);

        $schedule = LessonSchedule::query()->findOrFail($validated['lesson_schedule_id']);
        $date = Carbon::createFromFormat('Y-m-d', $validated['date']);

        $result = $this->service->open(
            $request->user(),
            $schedule,
            $date,
            $validated['substitute_user_id'] ?? null,
            $validated['note'] ?? null,
        );

        $session = $result['session'];

        if ($session === null) {
            return to_route('sessions.index', ['date' => $date->toDateString()])->withErrors(['session' => $result['message']]);
        }

        return to_route('sessions.index', ['date' => $date->toDateString()])->with('success', $result['message']);
    }

    public function update(Request $request, LessonSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'substitute_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', User::RoleTeacher)],
            'note' => ['nullable', 'string', 'max:100'],
        ], [
            'substitute_user_id.exists' => 'Guru pengganti itu tidak ditemukan atau bukan berperan guru.',
            'note.max' => 'Keterangan paling banyak 100 karakter.',
        ]);

        $substituteId = $validated['substitute_user_id'] ?? null;
        $note = $validated['note'] ?? null;

        if ((int) $session->substitute_user_id === (int) $substituteId && (string) $session->note === (string) $note) {
            return to_route('sessions.index', ['date' => $session->date->toDateString()])
                ->with('warning', 'Sesi ini masih sama, tidak ada yang diperbarui.');
        }

        $session->update([
            'substitute_user_id' => $substituteId,
            'note' => $note,
        ]);

        return to_route('sessions.index', ['date' => $session->date->toDateString()])
            ->with('success', 'Sesi '.$session->label().' berhasil diperbarui.');
    }

    public function close(Request $request, LessonSession $session): RedirectResponse
    {
        $result = $this->service->close($request->user(), $session);

        return to_route('sessions.index', ['date' => $session->date->toDateString()])
            ->with($result['closed'] ? 'success' : 'warning', $result['message']);
    }

    /**
     * Admin bisa membuka kembali sesi yang sudah ditutup, dipakai kalau absen
     * ditutup terlalu cepat dan masih ada siswa yang belum scan.
     */
    public function reopen(LessonSession $session): RedirectResponse
    {
        if ($session->isOpen()) {
            return to_route('sessions.index', ['date' => $session->date->toDateString()])
                ->with('warning', 'Absen '.$session->label().' memang masih dibuka.');
        }

        $session->update(['closed_at' => null, 'closed_by' => null]);

        return to_route('sessions.index', ['date' => $session->date->toDateString()])
            ->with('success', 'Absen '.$session->label().' dibuka kembali.');
    }

    /**
     * Menghapus sesi tidak menghapus catatan presensi siswa; tautan jamnya saja
     * yang dikosongkan supaya riwayat kehadiran tetap utuh.
     */
    public function destroy(LessonSession $session): RedirectResponse
    {
        $label = $session->label();
        $date = $session->date->toDateString();
        $logs = $session->logs()->count();

        $session->delete();

        return to_route('sessions.index', ['date' => $date])
            ->with('warning', 'Sesi '.$label.' dihapus. '.$logs.' catatan presensi dari sesi itu tetap tersimpan tanpa tautan jam pelajaran.');
    }

    /**
     * Tanggal yang dipilih admin, dengan pemeriksaan format supaya masukan
     * asal-asalan tidak membuat halaman error.
     */
    private function dateFilter(Request $request): Carbon
    {
        $value = $request->input('date');

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        }

        return Carbon::today();
    }
}
