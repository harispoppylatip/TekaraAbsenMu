<?php

namespace App\Http\Controllers;

use App\Models\LessonHour;
use App\Models\LessonSchedule;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Jadwal pelajaran mingguan: mata pelajaran apa di kelas mana, hari apa, jam
 * ke berapa, dan siapa gurunya.
 *
 * Dua tabrakan dijaga sejak validasi: satu kelas tidak boleh punya dua
 * pelajaran pada jam yang sama, dan satu guru tidak boleh mengajar dua kelas
 * pada jam yang sama. Kalau tidak dijaga, sesi absen jadi tidak jelas kelas
 * mana yang sedang berjalan.
 */
class LessonScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $classes = SchoolClass::query()->orderedByName()->get();
        $hours = LessonHour::query()->ordered()->get();
        $teachers = User::query()->where('role', User::RoleTeacher)->orderBy('name')->get();

        $selectedClass = $request->filled('class')
            ? $classes->firstWhere('id', (int) $request->input('class'))
            : null;

        $day = $this->dayFilter($request);
        $teacherId = $request->filled('teacher') ? (int) $request->input('teacher') : null;

        $classSchedules = $selectedClass === null
            ? new Collection
            : LessonSchedule::query()
                ->with(['lessonHour', 'schoolClass', 'teacher'])
                ->where('school_class_id', $selectedClass->getKey())
                ->ordered()
                ->get();

        $schedules = LessonSchedule::query()
            ->with(['lessonHour', 'schoolClass', 'teacher'])
            ->withCount('sessions')
            ->when($selectedClass, fn ($query) => $query->where('school_class_id', $selectedClass->getKey()))
            ->when($day !== null, fn ($query) => $query->where('day', $day))
            ->when($teacherId !== null, fn ($query) => $query->where('user_id', $teacherId))
            ->ordered()
            ->get();

        return view('schedules.index', [
            'classes' => $classes,
            'hours' => $hours,
            'teachers' => $teachers,
            'schedules' => $schedules,
            'selectedClass' => $selectedClass,
            'grid' => $this->grid($classSchedules, $hours),
            'filters' => [
                'class' => $selectedClass?->getKey(),
                'day' => $day,
                'teacher' => $teacherId,
            ],
            'dayNames' => LessonSchedule::DayNames,
            'days' => LessonSchedule::Days,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        LessonSchedule::create($data);

        return to_route('schedules.index', $this->redirectFilters($data))
            ->with('success', 'Jadwal '.$this->describe($data).' berhasil ditambahkan.');
    }

    public function update(Request $request, LessonSchedule $schedule): RedirectResponse
    {
        $data = $this->validated($request, $schedule);

        $unchanged = (int) $data['day'] === (int) $schedule->day
            && (int) $data['lesson_hour_id'] === (int) $schedule->lesson_hour_id
            && (int) $data['school_class_id'] === (int) $schedule->school_class_id
            && (int) $data['user_id'] === (int) $schedule->user_id
            && $data['subject'] === $schedule->subject;

        if ($unchanged) {
            return to_route('schedules.index', $this->redirectFilters($data))
                ->with('warning', 'Jadwal ini masih sama, tidak ada yang diperbarui.');
        }

        $schedule->update($data);

        return to_route('schedules.index', $this->redirectFilters($data))
            ->with('success', 'Jadwal '.$this->describe($data).' berhasil diperbarui.');
    }

    /**
     * Menghapus jadwal ikut menghapus pertemuan yang pernah dibuka untuk jadwal
     * itu, tetapi catatan presensi siswanya tetap tersimpan: tautan jamnya
     * dikosongkan, bukan ikut dihapus. Jumlahnya disebut supaya admin sadar.
     */
    public function destroy(LessonSchedule $schedule): RedirectResponse
    {
        $label = $this->describe([
            'day' => $schedule->day,
            'lesson_hour_id' => $schedule->lesson_hour_id,
            'school_class_id' => $schedule->school_class_id,
            'user_id' => $schedule->user_id,
            'subject' => $schedule->subject,
        ]);

        $sessions = $schedule->sessions()->count();

        $schedule->delete();

        if ($sessions > 0) {
            return to_route('schedules.index')
                ->with('warning', 'Jadwal '.$label.' dihapus bersama '.$sessions.' pertemuan. Catatan presensi siswa tetap tersimpan tanpa tautan jam pelajaran.');
        }

        return to_route('schedules.index')->with('success', 'Jadwal '.$label.' berhasil dihapus.');
    }

    /**
     * Susunan jam × hari untuk satu kelas, dipakai menggambar kisi jadwal.
     *
     * @param  Collection<int, LessonSchedule>  $schedules
     * @param  Collection<int, LessonHour>  $hours
     * @return array<int, array<int, ?LessonSchedule>>
     */
    private function grid(Collection $schedules, Collection $hours): array
    {
        $grid = [];

        foreach ($hours as $hour) {
            $row = [];

            foreach (LessonSchedule::Days as $day) {
                $row[$day] = $schedules->first(fn (LessonSchedule $schedule): bool => (int) $schedule->lesson_hour_id === (int) $hour->getKey()
                    && (int) $schedule->day === $day);
            }

            $grid[$hour->getKey()] = $row;
        }

        return $grid;
    }

    private function dayFilter(Request $request): ?int
    {
        $day = $request->input('day');

        return in_array((int) $day, LessonSchedule::Days, true) ? (int) $day : null;
    }

    /**
     * @param  array{day: int, lesson_hour_id: int, school_class_id: int, user_id: int, subject: string}  $data
     * @return array<string, int>
     */
    private function redirectFilters(array $data): array
    {
        return ['class' => (int) $data['school_class_id']];
    }

    /**
     * Kalimat singkat untuk pesan hasil, misalnya "Senin Jam ke-1 X 21 -
     * Matematika (Budi)".
     *
     * @param  array{day: int, lesson_hour_id: int, school_class_id: int, user_id: int, subject: string}  $data
     */
    private function describe(array $data): string
    {
        $hour = LessonHour::query()->find($data['lesson_hour_id']);
        $class = SchoolClass::query()->find($data['school_class_id']);
        $teacher = User::query()->find($data['user_id']);

        return LessonSchedule::dayName((int) $data['day'])
            .' '.($hour?->label() ?? 'jam')
            .' '.($class?->name ?? 'kelas')
            .' - '.$data['subject']
            .' ('.($teacher?->name ?? 'guru').')';
    }

    /**
     * @return array{day: int, lesson_hour_id: int, school_class_id: int, user_id: int, subject: string}
     */
    private function validated(Request $request, ?LessonSchedule $schedule = null): array
    {
        $request->validate([
            'school_class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', User::RoleTeacher)],
            'day' => ['required', 'integer', Rule::in(LessonSchedule::Days)],
            'lesson_hour_id' => [
                'required',
                'integer',
                Rule::exists('lesson_hours', 'id'),
                $this->classConflictRule($request, $schedule),
                $this->teacherConflictRule($request, $schedule),
            ],
            'subject' => ['required', 'string', 'max:50'],
        ], [
            'school_class_id.required' => 'Kelas wajib dipilih.',
            'school_class_id.exists' => 'Kelas itu tidak ditemukan.',
            'user_id.required' => 'Guru pengampu wajib dipilih.',
            'user_id.exists' => 'Guru itu tidak ditemukan atau bukan berperan guru.',
            'day.required' => 'Hari wajib dipilih.',
            'day.in' => 'Hari pelajaran hanya Senin sampai Jumat.',
            'lesson_hour_id.required' => 'Jam pelajaran wajib dipilih.',
            'lesson_hour_id.exists' => 'Jam pelajaran itu tidak ditemukan.',
            'subject.required' => 'Mata pelajaran wajib diisi.',
            'subject.max' => 'Nama mata pelajaran paling banyak 50 karakter.',
        ]);

        return [
            'day' => (int) $request->input('day'),
            'lesson_hour_id' => (int) $request->input('lesson_hour_id'),
            'school_class_id' => (int) $request->input('school_class_id'),
            'user_id' => (int) $request->input('user_id'),
            'subject' => LessonSchedule::normalizeSubject($request->input('subject')),
        ];
    }

    /**
     * Satu kelas hanya boleh punya satu pelajaran pada satu jam, jadi pelajaran
     * yang sudah ada di jam itu harus dipindahkan dulu.
     */
    private function classConflictRule(Request $request, ?LessonSchedule $schedule): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($request, $schedule): void {
            $day = (int) $request->input('day');
            $classId = (int) $request->input('school_class_id');
            $hourId = (int) $request->input('lesson_hour_id');

            if ($day < 1 || $classId < 1 || $hourId < 1) {
                return;
            }

            $conflict = LessonSchedule::query()
                ->with('lessonHour')
                ->when($schedule, fn ($query) => $query->whereKeyNot($schedule->getKey()))
                ->where('day', $day)
                ->where('school_class_id', $classId)
                ->where('lesson_hour_id', $hourId)
                ->first();

            if ($conflict) {
                $fail('Kelas ini sudah punya '.$conflict->subject.' pada '.LessonSchedule::dayName($day).' '.($conflict->lessonHour?->label() ?? 'jam itu').'.');
            }
        };
    }

    /**
     * Guru yang sudah mengajar di kelas lain pada jam yang sama tidak bisa
     * ditugaskan lagi di jam itu.
     */
    private function teacherConflictRule(Request $request, ?LessonSchedule $schedule): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($request, $schedule): void {
            $day = (int) $request->input('day');
            $teacherId = (int) $request->input('user_id');
            $hourId = (int) $request->input('lesson_hour_id');

            if ($day < 1 || $teacherId < 1 || $hourId < 1) {
                return;
            }

            $conflict = LessonSchedule::query()
                ->with(['lessonHour', 'schoolClass'])
                ->when($schedule, fn ($query) => $query->whereKeyNot($schedule->getKey()))
                ->where('day', $day)
                ->where('user_id', $teacherId)
                ->where('lesson_hour_id', $hourId)
                ->first();

            if ($conflict) {
                $fail('Guru ini sudah mengajar '.$conflict->subject.' di '.($conflict->schoolClass?->name ?? 'kelas lain').' pada '.LessonSchedule::dayName($day).' '.($conflict->lessonHour?->label() ?? 'jam itu').'.');
            }
        };
    }
}
