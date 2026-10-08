<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Services\AttendanceRecorderService;
use App\Services\DeviceRegistryService;
use App\Services\FaceEncodingService;
use App\Services\FaceMatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Absen wajah dari kamera HP petugas.
 *
 * Alurnya: HP mengambil gambar, gambar dikirim ke sini, dihitung oleh layanan
 * wajah, dicocokkan dengan sidik wajah yang tersimpan, lalu dicatat lewat
 * `AttendanceRecorderService` yang sama dengan sensor sidik jari. Jadi aturan
 * jam masuk dan jam pulangnya tetap satu, tidak ada aturan kedua yang perlu
 * dijaga terpisah.
 *
 * Kamera wajah tetap satu perangkat pada halaman Alat Sensor, jadi admin bisa
 * mengganti namanya, mematikannya, atau membatalkan pendaftarannya di satu
 * tempat yang sama dengan sensor lain.
 */
class FaceAttendanceController extends Controller
{
    public function __construct(
        private FaceEncodingService $encoder,
        private FaceMatcherService $matcher,
        private AttendanceRecorderService $attendance,
        private DeviceRegistryService $devices,
    ) {}

    public function index(): View
    {
        $device = $this->devices->touch($this->deviceId());

        return view('face.camera', [
            'device' => $device,
            'deviceRefusal' => $this->devices->usageRefusal($device),
            'serviceAvailable' => $this->encoder->isAvailable(),
            'recentLogs' => AttendanceLog::query()
                ->with('user')
                ->where('device_id', $device->device_id)
                ->latest('scanned_at')
                ->limit(8)
                ->get(),
        ]);
    }

    /**
     * Periksa satu gambar dari kamera dan catat presensinya.
     *
     * Balasan selalu berbentuk data yang bisa dibaca halaman kamera, termasuk
     * saat wajahnya tidak dikenali, supaya petugas tahu apa yang harus
     * dilakukan tanpa membuka halaman lain.
     */
    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'image.required' => 'Gambar belum terkirim.',
            'image.mimes' => 'Format gambar tidak dikenali.',
            'image.max' => 'Gambar terlalu besar.',
        ]);

        $device = $this->devices->touch($this->deviceId());
        $refusal = $this->devices->usageRefusal($device);

        if ($refusal !== null) {
            return $this->reply([
                'identified' => false,
                'recorded' => false,
                'message' => $refusal['message'],
            ], 403);
        }

        $encoded = $this->encoder->encode($request->file('image'));

        if ($encoded['status'] === FaceEncodingService::StatusUnavailable) {
            return $this->reply([
                'identified' => false,
                'recorded' => false,
                'message' => $encoded['message'],
            ], 503);
        }

        if ($encoded['status'] !== FaceEncodingService::StatusSuccess) {
            return $this->reply([
                'identified' => false,
                'recorded' => false,
                'message' => $encoded['message'],
            ]);
        }

        $match = $this->matcher->identify($encoded['encoding']);

        if ($match === null) {
            return $this->reply([
                'identified' => false,
                'recorded' => false,
                'message' => 'Wajah belum terdaftar. Minta admin mendaftarkan wajahnya di halaman Data Wajah.',
            ]);
        }

        $attendance = $this->attendance->record($match['user'], $device->device_id);

        return $this->reply([
            'identified' => true,
            'recorded' => $attendance['recorded'],
            'user_id' => $match['user']->identifier_number,
            'name' => $match['user']->name,
            'distance' => round($match['distance'], 3),
            'attendance_type' => $attendance['type'],
            'attendance_label' => $attendance['label'],
            'message' => $attendance['message'],
        ]);
    }

    private function deviceId(): string
    {
        return (string) config('face.device_id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reply(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload + ['status' => $status === 200 ? 'ok' : 'error'], $status);
    }
}
