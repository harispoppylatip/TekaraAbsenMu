<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Halaman siswa: jadwal pelajaran kelasnya hari ini, jadwal satu minggu, dan
 * rekap kehadirannya sendiri per jam pelajaran.
 *
 * Yang tampil hanya kelas siswa itu, bukan kelas lain, dan yang dihitung hanya
 * catatan presensinya sendiri.
 */
class StudentPortalController extends Controller
{
    public function index(Request $request): View
    {
        $student = $request->user();
        $today = Carbon::now();
        $day = (int) $today->dayOfWeekIso;

        $class = $this->classOf($student);

        $schedules = $class === null
            ? new Collection
            : LessonSchedule::query()
                ->with([
                    'lessonHour',
                    'teacher',
                    'sessions' => fn ($query) => $query->whereDate('date', $today->toDateString()),
                ])
                ->where('school_class_id', $class->getKey())
                ->ordered()
                ->get();

        $todaySchedules = $schedules->where('day', $day)->values();

        $scannedSessionIds = AttendanceLog::query()
            ->where('user_id', $student->getKey())
            ->whereNotNull('lesson_session_id')
            ->pluck('lesson_session_id')
            ->all();

        $scannedAt = AttendanceLog::query()
            ->where('user_id', $student->getKey())
            ->whereNotNull('lesson_session_id')
            ->get()
            ->keyBy('lesson_session_id');

        $classSessions = $class === null
            ? new Collection
            : LessonSession::query()
                ->with(['lessonSchedule.lessonHour', 'lessonSchedule.schoolClass', 'substitute'])
                ->whereHas('lessonSchedule', fn ($query) => $query->where('school_class_id', $class->getKey()))
                ->orderByDesc('date')
                ->orderByDesc('opened_at')
                ->limit(20)
                ->get();

        $history = $classSessions
            ->map(fn (LessonSession $session): array => [
                'session' => $session,
                'present' => in_array($session->getKey(), $scannedSessionIds, true),
                'log' => $scannedAt->get($session->getKey()),
            ])
            ->values();

        return view('student.dashboard', [
            'student' => $student,
            'class' => $class,
            'today' => $today,
            'day' => $day,
            'todaySchedules' => $todaySchedules,
            'weekSchedules' => $schedules,
            'history' => $history,
            'stats' => [
                'jadwalHariIni' => $todaySchedules->count(),
                'jadwalMinggu' => $schedules->count(),
                'hadir' => $history->where('present', true)->count(),
                'belum' => $history->where('present', false)->count(),
            ],
        ]);
    }

    public function gateAttendance(Request $request): View
    {
        $student = $request->user();
        $from = $this->date($request->input('dari')) ?? Carbon::today()->startOfMonth();
        $to = $this->date($request->input('sampai')) ?? Carbon::today();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        $logs = AttendanceLog::query()
            ->with('device')
            ->fromGate()
            ->where('user_id', $student->getKey())
            ->whereBetween('scanned_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderByDesc('scanned_at')
            ->get();

        return view('student.gate-attendance', [
            'student' => $student,
            'from' => $from,
            'to' => $to,
            'logs' => $logs,
            'stats' => [
                'total' => $logs->count(),
                'masuk' => $logs->where('type', AttendanceLog::TypeIn)->count(),
                'pulang' => $logs->where('type', AttendanceLog::TypeOut)->count(),
            ],
        ]);
    }

    /**
     * Kelas siswa dicocokkan tanpa membedakan huruf besar-kecil, sama seperti
     * cara kelas lain dipakai di aplikasi ini.
     */
    private function classOf(User $student): ?SchoolClass
    {
        if (! filled($student->class_name)) {
            return null;
        }

        return SchoolClass::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $student->class_name)])
            ->first();
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
