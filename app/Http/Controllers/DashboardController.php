<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Dasbor admin. Kehadiran dihitung dari absen jam pelajaran di sensor kelas,
 * karena itulah cara sekolah mencatat kehadiran. Presensi gerbang punya
 * halamannya sendiri.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $today = Carbon::today();

        $classSizes = $this->studentCountsByClassId();

        $sessions = LessonSession::query()
            ->with(['lessonSchedule.lessonHour', 'lessonSchedule.schoolClass', 'lessonSchedule.teacher', 'substitute'])
            ->whereDate('date', $today->toDateString())
            ->get()
            ->keyBy('lesson_schedule_id');

        $presentPerSession = AttendanceLog::query()
            ->whereIn('lesson_session_id', $sessions->modelKeys())
            ->whereHas('user', fn ($query) => $query->where('role', User::RoleStudent))
            ->selectRaw('lesson_session_id, COUNT(DISTINCT user_id) as total')
            ->groupBy('lesson_session_id')
            ->pluck('total', 'lesson_session_id');

        $lessons = LessonSchedule::query()
            ->with(['lessonHour', 'schoolClass', 'teacher'])
            ->where('day', (int) $today->dayOfWeekIso)
            ->ordered()
            ->get()
            ->map(function (LessonSchedule $schedule) use ($sessions, $presentPerSession, $classSizes): array {
                $session = $sessions->get($schedule->getKey());

                return [
                    'schedule' => $schedule,
                    'session' => $session,
                    'present' => $session === null ? 0 : (int) $presentPerSession->get($session->getKey(), 0),
                    'students' => (int) $classSizes->get((int) $schedule->school_class_id, 0),
                ];
            });

        $students = User::query()
            ->where('role', User::RoleStudent)
            ->where('status', User::StatusActive)
            ->count();

        $studentsPresent = AttendanceLog::query()
            ->fromClassSensor()
            ->whereDate('scanned_at', $today->toDateString())
            ->whereNotNull('lesson_session_id')
            ->whereHas('user', fn ($query) => $query->where('role', User::RoleStudent))
            ->distinct()
            ->count('user_id');

        return view('dashboard', [
            'today' => $today,
            'lessons' => $lessons,
            'stats' => [
                'lessons' => $lessons->count(),
                'opened' => $lessons->whereNotNull('session')->count(),
                'open' => $sessions->filter(fn (LessonSession $session): bool => $session->isOpen())->count(),
                'students' => $students,
                'studentsPresent' => $studentsPresent,
                'devices' => Device::where('status', Device::StatusActive)->count(),
                'unmapped' => Device::where('status', Device::StatusUnmapped)->count(),
            ],
            'recap' => $this->weeklyRecap(),
            'devices' => Device::with('schoolClasses')->latest('last_ping')->limit(6)->get(),
            'logs' => AttendanceLog::with(['user', 'lessonSession.lessonSchedule.schoolClass'])
                ->whereDate('scanned_at', $today->toDateString())
                ->latest('scanned_at')
                ->limit(8)
                ->get(),
            'withoutFingerprint' => User::query()
                ->whereIn('role', [User::RoleStudent, User::RoleTeacher])
                ->doesntHave('fingerprints')
                ->count(),
        ]);
    }

    /**
     * Jumlah siswa yang hadir di jam pelajaran selama tujuh hari terakhir.
     * Satu siswa dihitung sekali per hari walau hadir di beberapa jam.
     *
     * @return array<int, array{label: string, date: string, total: int}>
     */
    private function weeklyRecap(): array
    {
        $start = Carbon::today()->subDays(6);

        $totals = AttendanceLog::query()
            ->fromClassSensor()
            ->whereNotNull('lesson_session_id')
            ->where('scanned_at', '>=', $start)
            ->whereHas('user', fn ($query) => $query->where('role', User::RoleStudent))
            ->get(['user_id', 'scanned_at'])
            ->groupBy(fn (AttendanceLog $log): string => $log->scanned_at->toDateString())
            ->map(fn (Collection $logs): int => $logs->unique('user_id')->count());

        return collect(range(0, 6))
            ->map(function (int $offset) use ($start, $totals): array {
                $day = $start->copy()->addDays($offset);

                return [
                    'label' => $day->translatedFormat('D'),
                    'date' => $day->translatedFormat('d M'),
                    'total' => (int) $totals->get($day->toDateString(), 0),
                ];
            })
            ->all();
    }

    /**
     * Jumlah siswa per kelas. Anggota terhubung ke kelas lewat nama kelas tanpa
     * membedakan huruf besar dan kecil.
     *
     * @return Collection<int, int>
     */
    private function studentCountsByClassId(): Collection
    {
        $classIds = SchoolClass::query()
            ->get(['id', 'name'])
            ->mapWithKeys(fn (SchoolClass $schoolClass): array => [mb_strtolower($schoolClass->name) => (int) $schoolClass->getKey()]);

        return User::query()
            ->where('role', User::RoleStudent)
            ->whereNotNull('class_name')
            ->pluck('class_name')
            ->map(fn (string $name): ?int => $classIds->get(mb_strtolower($name)))
            ->filter()
            ->countBy();
    }
}
