<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Penyusun laporan kehadiran untuk rentang tanggal tertentu.
 *
 * Tiga laporan yang disusun dari data yang sudah ada, tanpa tabel baru:
 * - siswa: kehadiran per jam pelajaran, dihitung dari sesi absen yang benar
 *   benar dibuka untuk kelasnya;
 * - guru: pertemuan yang dipegang, bukti scan guru, dan jadwal yang tidak
 *   pernah dibuka absennya;
 * - gerbang: absen harian siswa dari sensor gerbang (masuk, terlambat, pulang).
 */
class AttendanceReportService
{
    public const TypeStudent = 'siswa';

    public const TypeTeacher = 'guru';

    public const TypeGate = 'gerbang';

    /** @var array<string, string> */
    public const TypeLabels = [
        self::TypeStudent => 'Kehadiran Siswa',
        self::TypeTeacher => 'Kehadiran Guru Mengajar',
        self::TypeGate => 'Absen Gerbang',
    ];

    /** Batas persentase kehadiran yang dianggap perlu perhatian wali kelas. */
    public const AttentionRate = 75;

    /**
     * Rekap kehadiran siswa per jam pelajaran. Yang dihitung sebagai pertemuan
     * hanya sesi absen yang dibuka untuk kelas siswa itu, jadi jadwal yang tidak
     * dibuka guru tidak membuat siswa terlihat bolos.
     *
     * @return array{
     *     rows: Collection<int, array{user: User, class: string, meetings: int, present: int, absent: int, rate: ?int}>,
     *     stats: array{students: int, meetings: int, present: int, rate: ?int, attention: int}
     * }
     */
    public function studentReport(CarbonInterface $from, CarbonInterface $to, ?SchoolClass $schoolClass = null): array
    {
        $classIds = $this->classIdsByLowerName();

        $sessions = $this->sessionsBetween($from, $to, $schoolClass)
            ->get(['lesson_sessions.id', 'lesson_sessions.lesson_schedule_id'])
            ->load('lessonSchedule:id,school_class_id');

        $meetingsPerClass = $sessions->countBy(fn (LessonSession $session): int => (int) $session->lessonSchedule?->school_class_id);

        $presentPerUser = AttendanceLog::query()
            ->whereIn('lesson_session_id', $sessions->modelKeys())
            ->selectRaw('user_id, COUNT(DISTINCT lesson_session_id) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $students = User::query()
            ->where('role', User::RoleStudent)
            ->when($schoolClass !== null, fn ($query) => $query->whereRaw('LOWER(class_name) = ?', [mb_strtolower($schoolClass->name)]))
            ->orderBy('class_name')
            ->orderBy('name')
            ->get();

        $rows = $students->map(function (User $student) use ($classIds, $meetingsPerClass, $presentPerUser): array {
            $classId = $classIds->get(mb_strtolower((string) $student->class_name));
            $meetings = $classId === null ? 0 : (int) $meetingsPerClass->get($classId, 0);
            $present = min((int) $presentPerUser->get($student->getKey(), 0), $meetings);

            return [
                'user' => $student,
                'class' => filled($student->class_name) ? (string) $student->class_name : 'Belum ada kelas',
                'meetings' => $meetings,
                'present' => $present,
                'absent' => $meetings - $present,
                'rate' => $this->rate($present, $meetings),
            ];
        })->values();

        $counted = $rows->where('meetings', '>', 0);

        return [
            'rows' => $rows,
            'stats' => [
                'students' => $rows->count(),
                'meetings' => $sessions->count(),
                'present' => $rows->sum('present'),
                'rate' => $this->rate($counted->sum('present'), $counted->sum('meetings')),
                'attention' => $counted->filter(fn (array $row): bool => $row['rate'] < self::AttentionRate)->count(),
            ],
        ];
    }

    /**
     * Rekap mengajar tiap guru. Pertemuan dicatat atas nama guru yang memegang
     * sesi itu (guru pengganti kalau ada), sedangkan jadwal yang tidak pernah
     * dibuka dihitung untuk guru pengampu jadwalnya.
     *
     * @return array{
     *     rows: Collection<int, array{user: User, meetings: int, substitute: int, scanned: int, unscanned: int, missed: int, studentsPresent: int, studentsExpected: int, rate: ?int}>,
     *     stats: array{teachers: int, meetings: int, scanned: int, missed: int, rate: ?int}
     * }
     */
    public function teacherReport(CarbonInterface $from, CarbonInterface $to, ?SchoolClass $schoolClass = null): array
    {
        $sessions = $this->sessionsBetween($from, $to, $schoolClass)
            ->with(['lessonSchedule.schoolClass', 'logs:id,lesson_session_id,user_id'])
            ->get();

        $classSizes = $this->studentCountsByClassId();

        $schedules = LessonSchedule::query()
            ->when($schoolClass !== null, fn ($query) => $query->where('school_class_id', $schoolClass->getKey()))
            ->get(['id', 'day', 'user_id', 'school_class_id']);

        $missedPerTeacher = $this->missedMeetings($schedules, $sessions, $from, $to);

        $teacherIds = $sessions->map(fn (LessonSession $session): ?int => $session->teacherUserId())
            ->merge($schedules->pluck('user_id'))
            ->filter()
            ->unique();

        $teachers = User::query()
            ->where('role', User::RoleTeacher)
            ->when($schoolClass !== null, fn ($query) => $query->whereKey($teacherIds->all()))
            ->orderBy('name')
            ->get();

        $sessionsPerTeacher = $sessions->groupBy(fn (LessonSession $session): int => (int) $session->teacherUserId());

        $rows = $teachers->map(function (User $teacher) use ($sessionsPerTeacher, $missedPerTeacher, $classSizes): array {
            /** @var Collection<int, LessonSession> $own */
            $own = $sessionsPerTeacher->get($teacher->getKey(), collect());

            $scanned = $own->filter(fn (LessonSession $session): bool => $session->logs->contains('user_id', $teacher->getKey()))->count();

            $studentsPresent = $own->sum(fn (LessonSession $session): int => $session->logs
                ->where('user_id', '!=', $teacher->getKey())
                ->unique('user_id')
                ->count());

            $studentsExpected = $own->sum(fn (LessonSession $session): int => (int) $classSizes->get((int) $session->lessonSchedule?->school_class_id, 0));

            return [
                'user' => $teacher,
                'meetings' => $own->count(),
                'substitute' => $own->filter(fn (LessonSession $session): bool => $session->isSubstituted())->count(),
                'scanned' => $scanned,
                'unscanned' => $own->count() - $scanned,
                'missed' => (int) $missedPerTeacher->get($teacher->getKey(), 0),
                'studentsPresent' => $studentsPresent,
                'studentsExpected' => $studentsExpected,
                'rate' => $this->rate(min($studentsPresent, $studentsExpected), $studentsExpected),
            ];
        })->values();

        return [
            'rows' => $rows,
            'stats' => [
                'teachers' => $rows->count(),
                'meetings' => $rows->sum('meetings'),
                'scanned' => $rows->sum('scanned'),
                'missed' => $rows->sum('missed'),
                'rate' => $this->rate($rows->sum('scanned'), $rows->sum('meetings')),
            ],
        ];
    }

    /**
     * Rekap absen gerbang per siswa. Satu hari dihitung sekali walau siswa
     * menempelkan jari berkali-kali.
     *
     * @return array{
     *     rows: Collection<int, array{user: User, class: string, schoolDays: int, days: int, onTime: int, late: int, permission: int, checkOut: int, absent: int, rate: ?int}>,
     *     stats: array{members: int, schoolDays: int, days: int, late: int, rate: ?int}
     * }
     */
    public function gateReport(CarbonInterface $from, CarbonInterface $to, ?SchoolClass $schoolClass = null): array
    {
        $schoolDays = $this->schoolDaysBetween($from, $to);

        $members = User::query()
            ->where('role', User::RoleStudent)
            ->where('status', User::StatusActive)
            ->when($schoolClass !== null, fn ($query) => $query->whereRaw('LOWER(class_name) = ?', [mb_strtolower($schoolClass->name)]))
            ->orderBy('role')
            ->orderBy('class_name')
            ->orderBy('name')
            ->get();

        $logs = AttendanceLog::query()
            ->fromGate()
            ->whereIn('user_id', $members->modelKeys())
            ->whereDate('scanned_at', '>=', $from->toDateString())
            ->whereDate('scanned_at', '<=', $to->toDateString())
            ->get(['user_id', 'scanned_at', 'type', 'status'])
            ->groupBy('user_id');

        $rows = $members->map(function (User $member) use ($logs, $schoolDays): array {
            /** @var Collection<int, AttendanceLog> $own */
            $own = $logs->get($member->getKey(), collect());

            $daysWith = fn (callable $filter): int => $own->filter($filter)
                ->map(fn (AttendanceLog $log): string => $log->scanned_at->toDateString())
                ->unique()
                ->count();

            $checkIn = fn (AttendanceLog $log): bool => $log->type !== AttendanceLog::TypeOut;

            $days = $daysWith($checkIn);
            $late = $daysWith(fn (AttendanceLog $log): bool => $checkIn($log) && $log->status === AttendanceLog::StatusLate);
            $permission = $daysWith(fn (AttendanceLog $log): bool => $checkIn($log) && $log->status === AttendanceLog::StatusPermission);

            return [
                'user' => $member,
                'class' => $member->isTeacher() ? 'Guru' : (filled($member->class_name) ? (string) $member->class_name : 'Belum ada kelas'),
                'schoolDays' => $schoolDays,
                'days' => $days,
                'onTime' => max($days - $late - $permission, 0),
                'late' => $late,
                'permission' => $permission,
                'checkOut' => $daysWith(fn (AttendanceLog $log): bool => $log->type === AttendanceLog::TypeOut),
                'absent' => max($schoolDays - $days, 0),
                'rate' => $this->rate(min($days, $schoolDays), $schoolDays),
            ];
        })->values();

        return [
            'rows' => $rows,
            'stats' => [
                'members' => $rows->count(),
                'schoolDays' => $schoolDays,
                'days' => $rows->sum('days'),
                'late' => $rows->sum('late'),
                'rate' => $this->rate($rows->sum(fn (array $row): int => min($row['days'], $schoolDays)), $schoolDays * $rows->count()),
            ],
        ];
    }

    /**
     * Hari sekolah (Senin sampai Jumat) dalam rentang, tidak melewati hari ini
     * karena hari yang belum terjadi belum bisa dihadiri.
     */
    public function schoolDaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $end = $to->copy()->min(Carbon::today());

        if ($end->lessThan($from)) {
            return 0;
        }

        return collect(CarbonPeriod::create($from->toDateString(), $end->toDateString()))
            ->filter(fn (CarbonInterface $date): bool => in_array((int) $date->dayOfWeekIso, LessonSchedule::Days, true))
            ->count();
    }

    /**
     * Sesi absen yang tanggal pertemuannya berada di dalam rentang.
     *
     * @return Builder<LessonSession>
     */
    private function sessionsBetween(CarbonInterface $from, CarbonInterface $to, ?SchoolClass $schoolClass): Builder
    {
        return LessonSession::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when($schoolClass !== null, fn ($query) => $query->whereHas(
                'lessonSchedule',
                fn ($inner) => $inner->where('school_class_id', $schoolClass->getKey()),
            ));
    }

    /**
     * Jadwal yang seharusnya berlangsung tetapi tidak punya sesi absen, dijumlah
     * per guru pengampu. Hari libur ikut terhitung karena aplikasi belum
     * menyimpan kalender libur.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, LessonSchedule>  $schedules
     * @param  \Illuminate\Database\Eloquent\Collection<int, LessonSession>  $sessions
     * @return Collection<int, int>
     */
    private function missedMeetings(Collection $schedules, Collection $sessions, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $end = $to->copy()->min(Carbon::today());

        if ($end->lessThan($from)) {
            return collect();
        }

        $occurrencesPerDay = collect(CarbonPeriod::create($from->toDateString(), $end->toDateString()))
            ->countBy(fn (CarbonInterface $date): int => (int) $date->dayOfWeekIso);

        $openedDates = $sessions->groupBy('lesson_schedule_id')
            ->map(fn (Collection $group): int => $group
                ->filter(fn (LessonSession $session): bool => $session->date->lessThanOrEqualTo($end))
                ->filter(fn (LessonSession $session): bool => (int) $session->date->dayOfWeekIso === (int) $session->lessonSchedule?->day)
                ->count());

        return $schedules
            ->groupBy('user_id')
            ->map(fn (Collection $group): int => $group->sum(fn (LessonSchedule $schedule): int => max(
                (int) $occurrencesPerDay->get((int) $schedule->day, 0) - (int) $openedDates->get($schedule->getKey(), 0),
                0,
            )));
    }

    /**
     * Id kelas menurut nama kecilnya, karena anggota terhubung ke kelas lewat
     * nama tanpa membedakan huruf besar dan kecil.
     *
     * @return Collection<string, int>
     */
    private function classIdsByLowerName(): Collection
    {
        return SchoolClass::query()
            ->get(['id', 'name'])
            ->mapWithKeys(fn (SchoolClass $schoolClass): array => [mb_strtolower($schoolClass->name) => (int) $schoolClass->getKey()]);
    }

    /**
     * @return Collection<int, int>
     */
    private function studentCountsByClassId(): Collection
    {
        $classIds = $this->classIdsByLowerName();

        return User::query()
            ->where('role', User::RoleStudent)
            ->whereNotNull('class_name')
            ->pluck('class_name')
            ->map(fn (string $name): ?int => $classIds->get(mb_strtolower($name)))
            ->filter()
            ->countBy();
    }

    private function rate(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }
}
