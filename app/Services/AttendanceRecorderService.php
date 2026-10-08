<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\AttendanceWindow;
use App\Models\Device;
use App\Models\LessonSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Penulis tunggal catatan presensi supaya semua jalur scan memakai aturan yang
 * sama: jam yang berlaku, perangkat yang dipakai, dan batas satu scan.
 *
 * Ada dua jalur catatan yang berbeda:
 * - sensor gerbang mengikuti jam presensi masuk dan pulang;
 * - sensor kelas hanya bekerja saat guru sudah membuka sesi absen jam pelajaran
 *   untuk kelas yang dilayani sensor itu.
 */
class AttendanceRecorderService
{
    /**
     * Batas jam terlambat yang dipakai kalau jam presensi belum diatur sama
     * sekali, supaya perangkat lama tetap bisa mencatat kehadiran.
     */
    public const LateHourThreshold = 8;

    public function __construct(
        private AttendanceScheduleService $schedule,
        private LessonSessionService $lessonSessions,
    ) {}

    /**
     * Catat presensi dari satu scan, atau tolak scan beserta alasannya.
     *
     * @return array{
     *     recorded: bool,
     *     log: ?AttendanceLog,
     *     type: ?string,
     *     status: ?string,
     *     label: ?string,
     *     message: string,
     *     display_message: string,
     *     display_detail: string
     * }
     */
    public function record(User $user, string $deviceId, ?CarbonInterface $scannedAt = null): array
    {
        $scannedAt ??= Carbon::now();

        $device = Device::query()->where('device_id', $deviceId)->first();

        return $device !== null && ! $device->isGate()
            ? $this->recordClassAttendance($device, $user, $scannedAt)
            : $this->recordGateAttendance($device, $user, $deviceId, $scannedAt);
    }

    /**
     * Sensor gerbang mencatat absen masuk dan pulang, jadi hanya boleh dipakai di
     * dalam jam presensi. Kalau sensor juga melayani kelas tertentu, hanya
     * anggota kelas itu yang dilayani.
     *
     * @return array{recorded: bool, log: ?AttendanceLog, type: ?string, status: ?string, label: ?string, message: string, display_message: string, display_detail: string}
     */
    private function recordGateAttendance(?Device $device, User $user, string $deviceId, CarbonInterface $scannedAt): array
    {
        $configured = $this->schedule->isConfigured();
        $window = $configured ? $this->schedule->windowAt($scannedAt) : null;

        if ($configured && $window === null) {
            return $this->reject(
                'Bukan jam absen',
                'Scan di luar jam presensi. Jam yang berlaku: '.$this->schedule->summary().'.',
                $user,
            );
        }

        $refusal = $device === null ? null : $this->classMembershipRefusal($device, $user);

        if ($refusal !== null) {
            return $this->reject($refusal['summary'], $refusal['message'], $user, $window);
        }

        if ($window === null) {
            return $this->store($user, $deviceId, $scannedAt, AttendanceLog::TypeIn, $this->statusFor($scannedAt), AttendanceLog::SourceGate);
        }

        $previous = $this->previousGateLog($user, $scannedAt, $window->type());

        if ($previous !== null) {
            return $this->reject(
                'Sudah absen',
                // Sebutan jamnya dipendekkan supaya tetap enak dibaca di tengah
                // kalimat: "sudah absen pulang", bukan "sudah absen Pulang".
                'Anda sudah absen '.mb_strtolower($window->typeLabel()).' pukul '.$previous->scanned_at->format('H:i').'.',
                $user,
                $window,
            );
        }

        return $this->store($user, $deviceId, $scannedAt, $window->type(), $window->status(), AttendanceLog::SourceGate);
    }

    /**
     * Sensor kelas mencatat kehadiran di jam pelajaran yang sedang berjalan,
     * jadi jam presensi gerbang tidak berlaku. Yang membatasi adalah sesi absen
     * yang dibuka guru: tanpa sesi terbuka untuk kelas sensor ini, scan apa pun
     * ditolak walaupun perangkatnya aktif. Guru pengampu (atau guru pengganti
     * yang ditunjuk admin) ikut tercatat sebagai bukti hadir, tetapi scan guru
     * tidak pernah menahan scan siswa.
     *
     * @return array{recorded: bool, log: ?AttendanceLog, type: ?string, status: ?string, label: ?string, message: string, display_message: string, display_detail: string}
     */
    private function recordClassAttendance(Device $device, User $user, CarbonInterface $scannedAt): array
    {
        $classNames = $device->classNames();

        if ($classNames === []) {
            return $this->reject(
                'Sensor belum diatur',
                'Sensor ini belum dipilih kelas atau layanan pulang masuk, jadi belum bisa mencatat presensi. Atur di halaman Presensi Gerbang.',
                $user,
            );
        }

        $sessions = $this->lessonSessions->openForClassNames($classNames, $scannedAt);

        if ($sessions->isEmpty()) {
            return $this->reject(
                'Belum waktunya',
                'Absen kelas dibuka guru saat jam pelajaran berjalan. Sensor ini melayani kelas '.implode(', ', $classNames).', jadi belum ada jam pelajaran yang dibuka.',
                $user,
            );
        }

        $session = $this->sessionFor($sessions, $user);

        if ($session === null) {
            return $this->refuseSessionMismatch($device, $user, $sessions, $classNames);
        }

        $previous = $session->logs()->where('user_id', $user->getKey())->first();

        if ($previous !== null) {
            return $this->reject(
                'Sudah absen',
                'Kehadiran Anda di '.$session->label().' sudah tercatat pukul '.$previous->scanned_at->format('H:i').'.',
                $user,
            );
        }

        return $this->store(
            $user,
            $device->device_id,
            $scannedAt,
            AttendanceLog::TypeIn,
            AttendanceLog::StatusPresent,
            AttendanceLog::SourceClass,
            $session,
        );
    }

    /**
     * Sesi yang cocok untuk pemilik jari ini: guru memakai sesi yang dia ajar
     * (atau yang ditunjuk admin sebagai pengganti), siswa memakai sesi kelasnya.
     * Admin tidak memakai sensor kelas.
     *
     * @param  Collection<int, LessonSession>  $sessions
     */
    private function sessionFor(Collection $sessions, User $user): ?LessonSession
    {
        if ($user->isTeacher()) {
            return $sessions->first(fn (LessonSession $session): bool => $session->teacherUserId() === (int) $user->getKey());
        }

        if ($user->isStudent()) {
            return $sessions->first(fn (LessonSession $session): bool => filled($user->class_name)
                && $session->schoolClassName() === $user->class_name);
        }

        return null;
    }

    /**
     * @param  Collection<int, LessonSession>  $sessions
     * @param  array<int, string>  $classNames
     * @return array{recorded: bool, log: null, type: null, status: null, label: null, message: string, display_message: string, display_detail: string}
     */
    private function refuseSessionMismatch(Device $device, User $user, Collection $sessions, array $classNames): array
    {
        if ($user->isAdmin()) {
            return $this->reject(
                'Tidak diizinkan',
                'Admin tidak memakai sensor kelas untuk absen. Pantau jalannya absen dari halaman Sesi Absen.',
                $user,
            );
        }

        $openClasses = $sessions
            ->map(fn (LessonSession $session): ?string => $session->schoolClassName())
            ->filter()
            ->unique()
            ->implode(', ');

        if ($user->isStudent()) {
            $refusal = $this->classMembershipRefusal($device, $user);

            if ($refusal !== null) {
                return $this->reject($refusal['summary'], $refusal['message'], $user);
            }

            return $this->reject(
                'Belum dibuka',
                'Jam pelajaran kelas '.$user->class_name.' belum dibuka guru'.($openClasses === '' ? '.' : ', yang sedang terbuka kelas '.$openClasses.'.'),
                $user,
            );
        }

        return $this->reject(
            'Di luar jadwal',
            'Sensor ini sedang melayani kelas '.($openClasses === '' ? implode(', ', $classNames) : $openClasses).'. Anda tidak mengajar di kelas itu pada jam ini.',
            $user,
        );
    }

    /**
     * Sensor yang melayani kelas tertentu hanya melayani siswa kelas itu. Perangkat
     * tanpa tautan kelas tetap terbuka untuk semua anggota, seperti alat di
     * gerbang.
     *
     * @return array{summary: string, message: string}|null
     */
    private function classMembershipRefusal(Device $device, User $user): ?array
    {
        $classNames = $device->classNames();

        if ($classNames === [] || ! $user->isStudent()) {
            return null;
        }

        if (in_array((string) $user->class_name, $classNames, true)) {
            return null;
        }

        return [
            'summary' => 'Bukan kelas ini',
            'message' => filled($user->class_name)
                ? 'Sensor ini hanya untuk kelas '.implode(', ', $classNames).', bukan kelas '.$user->class_name.'.'
                : 'Kelas Anda belum diisi, jadi sensor kelas '.implode(', ', $classNames).' tidak bisa dipakai.',
        ];
    }

    /**
     * Status kehadiran untuk satu waktu scan berdasarkan jam presensi.
     */
    public function statusFor(CarbonInterface $scannedAt): string
    {
        return $this->schedule->windowAt($scannedAt)?->status()
            ?? ($scannedAt->hour >= self::LateHourThreshold ? AttendanceLog::StatusLate : AttendanceLog::StatusPresent);
    }

    /**
     * Satu orang hanya boleh tercatat sekali untuk setiap jenis absen dalam
     * sehari, jadi scan berulang tidak menambah baris presensi. Presensi sensor
     * kelas tidak dihitung di sini supaya scan kelas tidak memblokir absen di
     * gerbang.
     */
    private function previousGateLog(User $user, CarbonInterface $scannedAt, string $type): ?AttendanceLog
    {
        return AttendanceLog::query()
            ->fromGate()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereDate('scanned_at', $scannedAt->format('Y-m-d'))
            ->orderBy('scanned_at')
            ->first();
    }

    /**
     * Satu orang hanya boleh punya satu catatan per jam pelajaran, dan itulah
     * sebabnya batas absen kelas tidak lagi dihitung per hari: guru mengajar
     * beberapa jam di kelas yang sama, dan setiap jam punya daftarnya sendiri.
     *
     * @return array{recorded: bool, log: AttendanceLog, type: string, status: string, label: string, message: string, display_message: string, display_detail: string}
     */
    private function store(
        User $user,
        string $deviceId,
        CarbonInterface $scannedAt,
        string $type,
        string $status,
        string $source,
        ?LessonSession $lessonSession = null,
    ): array {
        $log = AttendanceLog::create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'lesson_session_id' => $lessonSession?->getKey(),
            'scanned_at' => $scannedAt,
            'status' => $status,
            'type' => $type,
            'source' => $source,
        ]);

        $time = $log->scanned_at->format('H:i');

        return [
            'recorded' => true,
            'log' => $log,
            'type' => $type,
            'status' => $status,
            'label' => $log->statusLabel(),
            'message' => $lessonSession === null
                ? ($log->isClassLog()
                    ? 'Kehadiran kelas berhasil dicatat pukul '.$time.'.'
                    : 'Presensi berhasil. '.$log->statusLabel().' tercatat pukul '.$time.'.')
                : 'Kehadiran '.$lessonSession->label().' tercatat pukul '.$time.'.',
            'display_message' => $user->name,
            'display_detail' => $this->displayDetail($log, $lessonSession, $time),
        ];
    }

    /**
     * Baris kedua layar sensor. Namanya sengaja pendek karena layar hanya
     * memuat 16 karakter per baris.
     */
    private function displayDetail(AttendanceLog $log, ?LessonSession $lessonSession, string $time): string
    {
        if ($lessonSession !== null) {
            return 'Jam ke-'.($lessonSession->lessonHour()?->number ?? '-').' '.$time;
        }

        return $log->isClassLog() ? 'Kelas '.$time : $log->statusLabel().' '.$time;
    }

    /**
     * @return array{recorded: bool, log: null, type: ?string, status: null, label: null, message: string, display_message: string, display_detail: string}
     */
    private function reject(string $summary, string $message, User $user, ?AttendanceWindow $window = null): array
    {
        return [
            'recorded' => false,
            'log' => null,
            'type' => $window?->type(),
            'status' => null,
            'label' => null,
            'message' => $message,
            'display_message' => $summary,
            'display_detail' => $user->name,
        ];
    }
}
