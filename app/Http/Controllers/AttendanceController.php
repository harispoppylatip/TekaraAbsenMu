<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AttendanceWindow;
use App\Models\Device;
use App\Models\User;
use App\Services\AttendanceRecorderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(): View
    {
        // Kehadiran harian dihitung dari sensor gerbang saja. Scan sensor kelas
        // dicatat terpisah supaya tidak menghitung orang yang sama dua kali.
        $gateToday = AttendanceLog::query()->fromGate()->whereDate('scanned_at', today());
        $classToday = AttendanceLog::query()->fromClassSensor()->whereDate('scanned_at', today());

        $masukUserIds = (clone $gateToday)->where('type', AttendanceLog::TypeIn)->distinct()->pluck('user_id');

        $activeUsers = User::where('status', 'active')->count();
        $presentUserIds = (clone $gateToday)->where('type', AttendanceLog::TypeIn)
            ->where('status', AttendanceLog::StatusPresent)->distinct()->pluck('user_id');
        $lateUserIds = (clone $gateToday)->where('type', AttendanceLog::TypeIn)
            ->where('status', AttendanceLog::StatusLate)->distinct()->pluck('user_id');
        $checkOutUserIds = (clone $gateToday)->where('type', AttendanceLog::TypeOut)->distinct()->pluck('user_id');

        $classUserIds = (clone $classToday)->distinct()->pluck('user_id');

        return view('attendance.index', [
            'windows' => AttendanceWindow::query()->ordered()->get(),
            'kindOptions' => AttendanceWindow::kindOptions(),
            'fallbackLateHour' => AttendanceRecorderService::LateHourThreshold,
            'devices' => Device::query()
                ->with('schoolClasses')
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Device::StatusUnmapped])
                ->orderByRaw('COALESCE(NULLIF(name, \'\'), device_id)')
                ->get(),
            'unmappedDevices' => Device::where('status', Device::StatusUnmapped)->count(),
            'stats' => [
                'hadir' => $presentUserIds->count(),
                'terlambat' => $lateUserIds->count(),
                'pulang' => $checkOutUserIds->count(),
                'belumHadir' => max($activeUsers - $masukUserIds->count(), 0),
                'belumPulang' => max($masukUserIds->diff($checkOutUserIds)->count(), 0),
                'users' => $activeUsers,
                'scans' => (clone $gateToday)->count(),
                'classScans' => (clone $classToday)->count(),
                'classRecorded' => $classUserIds->count(),
            ],
            'logs' => AttendanceLog::with('user')
                ->fromGate()
                ->whereDate('scanned_at', today())
                ->latest('scanned_at')
                ->limit(25)
                ->get(),
            'classLogs' => AttendanceLog::with(['user', 'lessonSession.lessonSchedule.lessonHour'])
                ->fromClassSensor()
                ->whereDate('scanned_at', today())
                ->latest('scanned_at')
                ->limit(25)
                ->get(),
        ]);
    }

    public function storeWindow(Request $request): RedirectResponse
    {
        $window = AttendanceWindow::create($this->validated($request));

        return to_route('attendance.index')
            ->with('success', 'Jam '.$window->label().' '.$window->range().' berhasil ditambahkan.');
    }

    public function updateWindow(Request $request, AttendanceWindow $window): RedirectResponse
    {
        $data = $this->validated($request, $window);

        $unchanged = $data['kind'] === $window->kind
            && $data['starts_at'] === $window->starts_at
            && $data['ends_at'] === $window->ends_at;

        if ($unchanged) {
            return to_route('attendance.index')->with('warning', 'Jam presensi masih sama, tidak ada yang diperbarui.');
        }

        $window->update($data);

        return to_route('attendance.index')
            ->with('success', 'Jam '.$window->label().' '.$window->range().' berhasil diperbarui.');
    }

    public function destroyWindow(AttendanceWindow $window): RedirectResponse
    {
        if (AttendanceWindow::query()->count() <= 1) {
            return to_route('attendance.index')->withErrors([
                'window' => 'Minimal harus ada satu jam presensi. Ubah jam yang ada daripada menghapus semuanya.',
            ]);
        }

        $window->delete();

        return to_route('attendance.index')
            ->with('success', 'Jam '.$window->label().' '.$window->range().' berhasil dihapus.');
    }

    /**
     * @return array{kind: string, starts_at: string, ends_at: string}
     */
    private function validated(Request $request, ?AttendanceWindow $window = null): array
    {
        $request->validate([
            'kind' => ['required', Rule::in(AttendanceWindow::Kinds)],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => [
                'required',
                'date_format:H:i',
                'after:starts_at',
                $this->noOverlapRule($request, $window),
            ],
        ], [
            'ends_at.after' => 'Jam selesai harus setelah jam mulai.',
        ]);

        return [
            'kind' => $request->string('kind')->toString(),
            'starts_at' => $request->string('starts_at')->toString(),
            'ends_at' => $request->string('ends_at')->toString(),
        ];
    }

    /**
     * Dua rentang yang bertabrakan membuat aturan jam jadi tidak jelas karena
     * hanya rentang paling awal yang dipakai, jadi ditolak sejak awal.
     */
    private function noOverlapRule(Request $request, ?AttendanceWindow $window): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($request, $window): void {
            $starts = $request->string('starts_at')->toString();
            $ends = $request->string('ends_at')->toString();

            if (! preg_match('/^\d{2}:\d{2}$/', $starts) || ! preg_match('/^\d{2}:\d{2}$/', $ends)) {
                return;
            }

            $conflict = AttendanceWindow::query()
                ->when($window, fn ($query) => $query->whereKeyNot($window->getKey()))
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
