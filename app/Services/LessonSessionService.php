<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Pengatur sesi absen jam pelajaran: kapan boleh dibuka, siapa yang boleh
 * membukanya, dan sesi mana yang sedang berlaku untuk sebuah sensor kelas.
 *
 * Aturan yang dipegang:
 * - guru membuka sendiri jadwalnya, hanya pada hari yang sesuai, dan hanya di
 *   dalam jam pelajarannya (diberi kelonggaran beberapa menit di awal);
 * - admin boleh membuka, menutup, dan menghapus kapan saja untuk tanggal mana
 *   pun, termasuk menunjuk guru pengganti;
 * - satu jadwal hanya punya satu sesi per tanggal.
 */
class LessonSessionService
{
    /**
     * Kelonggaran bagi guru untuk membuka absen sebelum jam pelajaran dimulai,
     * supaya bel sudah berbunyi saat siswa mulai mengantre.
     */
    public const EarlyOpenMinutes = 10;

    /**
     * Buka sesi absen untuk satu jadwal.
     *
     * @return array{opened: bool, session: ?LessonSession, message: string}
     */
    public function open(
        User $actor,
        LessonSchedule $schedule,
        ?CarbonInterface $date = null,
        ?int $substituteUserId = null,
        ?string $note = null,
    ): array {
        $date ??= Carbon::now();
        $isAdmin = $actor->isAdmin();

        $existing = LessonSession::query()
            ->with([
                'lessonSchedule.lessonHour',
                'lessonSchedule.schoolClass',
                'lessonSchedule.teacher',
                'substitute',
            ])
            ->where('lesson_schedule_id', $schedule->getKey())
            ->whereDate('date', $date->toDateString())
            ->first();

        // Guru pengganti yang ditunjuk admin memegang pertemuan hari itu, jadi
        // dia juga boleh membuka dan menutupnya sendiri.
        $isSubstitute = $existing !== null && (int) $existing->substitute_user_id === (int) $actor->getKey();

        if (! $isAdmin && ! $isSubstitute && (int) $schedule->user_id !== (int) $actor->getKey()) {
            return $this->refused('Jadwal ini bukan milik Anda, jadi absennya tidak bisa dibuka dari akun ini.');
        }

        if (! $isAdmin) {
            $refusal = $this->teacherTimeRefusal($schedule, $date);

            if ($refusal !== null) {
                return $this->refused($refusal);
            }
        }

        if ($existing !== null && $existing->isOpen()) {
            return $this->refused('Absen '.$existing->label().' sudah dibuka.');
        }

        if ($existing?->isScheduled()) {
            if (! $isAdmin) {
                $existing->update([
                    'opened_at' => Carbon::now(),
                    'scheduled_at' => null,
                    'opened_by' => $actor->getKey(),
                ]);

                return [
                    'opened' => true,
                    'session' => $existing,
                    'message' => 'Absen '.$existing->label().' dimulai. Siswa bisa mulai scan di sensor kelas.',
                ];
            }

            return $this->refused('Absen '.$existing->label().' sudah dijadwalkan untuk dibuka pukul '.$existing->scheduled_at->format('H:i').'.');
        }

        if ($existing !== null && $isAdmin) {
            $existing->update(['closed_at' => null, 'closed_by' => null]);

            return [
                'opened' => true,
                'session' => $existing,
                'message' => 'Absen '.$existing->label().' dibuka kembali.',
            ];
        }

        if ($existing !== null) {
            return $this->refused('Absen '.$existing->label().' sudah ditutup. Hubungi admin untuk membukanya lagi.');
        }

        $scheduledAt = $isAdmin && $date->isFuture() && $schedule->lessonHour !== null
            ? Carbon::parse($date->toDateString().' '.$schedule->lessonHour->starts_at)
            : null;

        $session = LessonSession::create([
            'lesson_schedule_id' => $schedule->getKey(),
            'date' => $date->toDateString(),
            'scheduled_at' => $scheduledAt,
            'opened_at' => $scheduledAt === null ? Carbon::now() : null,
            'opened_by' => $scheduledAt === null ? $actor->getKey() : null,
            'substitute_user_id' => $isAdmin ? $substituteUserId : null,
            'note' => $note,
        ]);

        $session->setRelation('lessonSchedule', $schedule);

        return [
            'opened' => true,
            'session' => $session,
            'message' => $scheduledAt === null
                ? 'Absen '.$session->label().' dibuka. Siswa bisa mulai scan di sensor kelas.'
                : 'Absen '.$session->label().' dijadwalkan dibuka otomatis pukul '.$scheduledAt->format('H:i').'.',
        ];
    }

    /**
     * Aktifkan sesi yang waktu mulainya sudah tiba. Operasi ini idempoten agar
     * aman dijalankan oleh scheduler lebih dari sekali.
     */
    public function activateScheduledSessions(?CarbonInterface $at = null): int
    {
        $now = $at ?? Carbon::now();

        return LessonSession::query()
            ->whereNull('opened_at')
            ->whereNull('closed_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->update([
                'opened_at' => $now,
                'scheduled_at' => null,
            ]);
    }

    /**
     * Tutup sesi absen sehingga scan baru tidak lagi tercatat.
     *
     * @return array{closed: bool, message: string}
     */
    public function close(User $actor, LessonSession $session): array
    {
        if ($session->isScheduled()) {
            return ['closed' => false, 'message' => 'Absen '.$session->label().' belum dibuka karena jadwalnya belum tiba.'];
        }

        if (! $session->isOpen()) {
            return ['closed' => false, 'message' => 'Absen '.$session->label().' sudah ditutup sebelumnya.'];
        }

        $session->update([
            'closed_at' => Carbon::now(),
            'closed_by' => $actor->getKey(),
        ]);

        return [
            'closed' => true,
            'message' => 'Absen '.$session->label().' ditutup. Scan berikutnya tidak akan tercatat.',
        ];
    }

    /**
     * Sesi yang sedang terbuka untuk kelas-kelas yang dilayani sebuah sensor.
     *
     * Sesi yang jam pelajarannya sedang berjalan didahulukan; kalau tidak ada
     * (misalnya admin membuka sesi di luar jamnya), sesi yang paling akhir
     * dibuka yang dipakai supaya scan tidak ditolak tanpa alasan.
     *
     * @param  array<int, string>  $classNames
     * @return Collection<int, LessonSession>
     */
    public function openForClassNames(array $classNames, CarbonInterface $at): Collection
    {
        if ($classNames === []) {
            return new Collection;
        }

        $sessions = LessonSession::query()
            ->with([
                'lessonSchedule.lessonHour',
                'lessonSchedule.schoolClass',
                'lessonSchedule.teacher',
                'substitute',
            ])
            ->open()
            ->whereDate('date', $at->format('Y-m-d'))
            ->whereHas('lessonSchedule.schoolClass', fn (Builder $query) => $query->whereIn('name', $classNames))
            ->orderBy('opened_at')
            ->get();

        $time = $at->format('H:i');

        $running = $sessions->filter(fn (LessonSession $session): bool => $session->coversTime($time));

        return ($running->isNotEmpty() ? $running : $sessions)->values();
    }

    /**
     * Daftar hadir satu sesi: seluruh siswa kelasnya dengan keterangan sudah
     * scan atau belum, ditambah bukti scan guru pengajarnya.
     *
     * @return array{
     *     session: LessonSession,
     *     rows: Collection<int, array{user: User, log: ?AttendanceLog, present: bool}>,
     *     presentCount: int,
     *     absentCount: int,
     *     teacherLog: ?AttendanceLog,
     *     otherLogs: Collection<int, AttendanceLog>
     * }
     */
    public function recap(LessonSession $session): array
    {
        $session->loadMissing([
            'lessonSchedule.lessonHour',
            'lessonSchedule.schoolClass',
            'lessonSchedule.teacher',
            'substitute',
            'logs.user',
        ]);

        $class = $session->lessonSchedule?->schoolClass;

        $students = $class === null
            ? new Collection
            : $class->students()->orderBy('name')->get();

        $logs = $session->logs->keyBy('user_id');

        $rows = $students->map(fn (User $student): array => [
            'user' => $student,
            'log' => $logs->get($student->getKey()),
            'present' => $logs->has($student->getKey()),
        ])->values();

        $teacherLog = $logs->get($session->teacherUserId());

        $known = $students->pluck('id')->push($session->teacherUserId())->filter()->all();

        return [
            'session' => $session,
            'rows' => $rows,
            'presentCount' => $rows->where('present', true)->count(),
            'absentCount' => $rows->where('present', false)->count(),
            'teacherLog' => $teacherLog,
            'otherLogs' => $logs->except($known)->values(),
        ];
    }

    /**
     * Alasan guru belum boleh membuka absen: bukan harinya, atau di luar jam
     * pelajaran. Dikembalikan null kalau sudah boleh.
     */
    private function teacherTimeRefusal(LessonSchedule $schedule, CarbonInterface $date): ?string
    {
        $now = Carbon::now();

        if (! $date->isSameDay($now)) {
            return 'Absen hanya bisa dibuka pada hari yang sama dengan jadwalnya. Tanggal lain hanya bisa dibuka admin.';
        }

        $day = (int) $date->dayOfWeekIso;

        if ($day !== (int) $schedule->day) {
            return 'Jadwal ini untuk hari '.LessonSchedule::dayName((int) $schedule->day).', hari ini '.LessonSchedule::dayName($day).'.';
        }

        $hour = $schedule->lessonHour;

        if ($hour === null) {
            return 'Jam pelajaran jadwal ini sudah tidak ada. Hubungi admin untuk memperbaikinya.';
        }

        $opensAt = $hour->startsOn($now)->subMinutes(self::EarlyOpenMinutes);
        $closesAt = $hour->endsOn($now);

        if ($now->lessThan($opensAt) || $now->greaterThanOrEqualTo($closesAt)) {
            return 'Absen '.$hour->label().' dibuka pukul '.$opensAt->format('H:i').' sampai '.$closesAt->format('H:i').'. Sekarang '.$now->format('H:i').'.';
        }

        return null;
    }

    /**
     * @return array{opened: bool, session: null, message: string}
     */
    private function refused(string $message): array
    {
        return ['opened' => false, 'session' => null, 'message' => $message];
    }
}
