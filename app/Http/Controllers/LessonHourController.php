<?php

namespace App\Http\Controllers;

use App\Models\LessonHour;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Jam pelajaran: berapa sesi dalam sehari dan pukul berapa saja.
 *
 * Daftar ini dipakai semua kelas, jadi urutannya ditentukan oleh nomor jam,
 * bukan urutan penambahan. Rentang jam tidak boleh bertabrakan supaya tidak ada
 * dua jam yang mengaku berjalan pada waktu yang sama.
 */
class LessonHourController extends Controller
{
    /** Batas jumlah jam per hari supaya salah ketik nomor tidak lolos. */
    public const MaxNumber = 20;

    public function index(): View
    {
        $hours = LessonHour::query()->withCount('schedules')->ordered()->get();

        return view('lesson-hours.index', [
            'hours' => $hours,
            'nextNumber' => ((int) $hours->max('number')) + 1,
            'usedInSchedules' => $hours->sum('schedules_count'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $hour = LessonHour::create($this->validated($request));

        return to_route('lesson-hours.index')
            ->with('success', $hour->label().' '.$hour->range().' berhasil ditambahkan.');
    }

    public function update(Request $request, LessonHour $lessonHour): RedirectResponse
    {
        $data = $this->validated($request, $lessonHour);

        $unchanged = (int) $data['number'] === (int) $lessonHour->number
            && $data['starts_at'] === $lessonHour->starts_at
            && $data['ends_at'] === $lessonHour->ends_at;

        if ($unchanged) {
            return to_route('lesson-hours.index')->with('warning', 'Jam ini masih sama, tidak ada yang diperbarui.');
        }

        $lessonHour->update($data);

        return to_route('lesson-hours.index')
            ->with('success', $lessonHour->label().' '.$lessonHour->range().' berhasil diperbarui.');
    }

    public function destroy(LessonHour $lessonHour): RedirectResponse
    {
        $used = $lessonHour->schedules()->count();

        if ($used > 0) {
            return to_route('lesson-hours.index')->withErrors([
                'hour' => $lessonHour->label().' masih dipakai '.$used.' jadwal pelajaran. Pindahkan atau hapus jadwalnya dulu di halaman Jadwal Pelajaran.',
            ]);
        }

        $lessonHour->delete();

        return to_route('lesson-hours.index')
            ->with('success', $lessonHour->label().' '.$lessonHour->range().' berhasil dihapus.');
    }

    /**
     * Isi daftar jam sekaligus: satu jam pertama, lalu jam berikutnya mengikuti
     * durasi yang sama. Hanya berlaku saat daftar masih kosong supaya jam yang
     * sudah diatur sekolah tidak tertimpa diam-diam.
     */
    public function generate(Request $request): RedirectResponse
    {
        if (LessonHour::query()->exists()) {
            return to_route('lesson-hours.index')->withErrors([
                'hour' => 'Jam pelajaran sudah ada. Tambahkan satu per satu, atau hapus daftar lama dulu untuk mengisi ulang dari awal.',
            ]);
        }

        $validated = $request->validate([
            'starts_at' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer', 'between:20,120'],
            'total' => ['required', 'integer', 'between:1,'.self::MaxNumber],
        ], [
            'starts_at.required' => 'Jam mulai wajib diisi.',
            'starts_at.date_format' => 'Jam mulai memakai format 07:00.',
            'duration.between' => 'Durasi satu jam pelajaran antara 20 sampai 120 menit.',
            'total.between' => 'Jumlah jam pelajaran antara 1 sampai '.self::MaxNumber.'.',
        ]);

        $duration = (int) $validated['duration'];
        $total = (int) $validated['total'];
        $cursor = Carbon::createFromFormat('H:i', $validated['starts_at']);

        if ($cursor->hour * 60 + $cursor->minute + ($duration * $total) > 24 * 60) {
            return to_route('lesson-hours.index')->withErrors([
                'total' => 'Jam terakhir akan melewati tengah malam. Kurangi jumlah jam, durasi, atau majukan jam mulainya.',
            ]);
        }

        for ($number = 1; $number <= $total; $number++) {
            $ends = (clone $cursor)->addMinutes($duration);

            LessonHour::create([
                'number' => $number,
                'starts_at' => $cursor->format('H:i'),
                'ends_at' => $ends->format('H:i'),
            ]);

            $cursor = $ends;
        }

        return to_route('lesson-hours.index')
            ->with('success', $total.' jam pelajaran berhasil dibuat dari '.$validated['starts_at'].' sampai '.$cursor->format('H:i').'. Silakan sesuaikan kalau jam sekolah berbeda.');
    }

    /**
     * @return array{number: int, starts_at: string, ends_at: string}
     */
    private function validated(Request $request, ?LessonHour $hour = null): array
    {
        $request->validate([
            'number' => [
                'required',
                'integer',
                'between:1,'.self::MaxNumber,
                Rule::unique('lesson_hours', 'number')->ignore($hour?->getKey()),
            ],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => [
                'required',
                'date_format:H:i',
                'after:starts_at',
                $this->noOverlapRule($request, $hour),
            ],
        ], [
            'number.required' => 'Nomor jam wajib diisi.',
            'number.between' => 'Nomor jam antara 1 sampai '.self::MaxNumber.'.',
            'number.unique' => 'Nomor jam itu sudah dipakai jam lain.',
            'starts_at.date_format' => 'Jam mulai memakai format 07:00.',
            'ends_at.date_format' => 'Jam selesai memakai format 07:45.',
            'ends_at.after' => 'Jam selesai harus setelah jam mulai.',
        ]);

        return [
            'number' => (int) $request->input('number'),
            'starts_at' => $request->string('starts_at')->toString(),
            'ends_at' => $request->string('ends_at')->toString(),
        ];
    }

    /**
     * Dua jam yang bertabrakan membuat sesi absen jadi ambigu, jadi ditolak
     * sejak awal seperti pada jam presensi.
     */
    private function noOverlapRule(Request $request, ?LessonHour $hour): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($request, $hour): void {
            $starts = $request->string('starts_at')->toString();
            $ends = $request->string('ends_at')->toString();

            if (! preg_match('/^\d{2}:\d{2}$/', $starts) || ! preg_match('/^\d{2}:\d{2}$/', $ends)) {
                return;
            }

            $conflict = LessonHour::query()
                ->when($hour, fn ($query) => $query->whereKeyNot($hour->getKey()))
                ->where('starts_at', '<', $ends)
                ->where('ends_at', '>', $starts)
                ->ordered()
                ->first();

            if ($conflict) {
                $fail('Jam bertabrakan dengan '.$conflict->label().' '.$conflict->range().'.');
            }
        };
    }
}
