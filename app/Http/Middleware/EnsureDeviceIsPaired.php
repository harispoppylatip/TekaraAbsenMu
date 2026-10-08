<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Services\DeviceRegistryService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceIsPaired
{
    public function __construct(private DeviceRegistryService $devices) {}

    /**
     * Hanya perangkat yang sudah didaftarkan dan aktif boleh memakai API presensi.
     *
     * Perangkat baru tetap dideteksi agar muncul di halaman Alat Sensor, dan alat
     * yang ditolak selalu menerima balasan JSON berisi pesan untuk layarnya,
     * bukan halaman error HTML.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->input('device_id');

        if (! is_string($deviceId) || trim($deviceId) === '') {
            return $this->sensorReply([
                'status' => 'device_id_missing',
                'message' => 'device_id wajib dikirim.',
                'display_message' => 'ID sensor kosong',
                'display_detail' => 'Lapor operator',
            ], 422);
        }

        $device = $this->devices->touch(trim($deviceId));
        $refusal = $this->devices->usageRefusal($device);

        if ($refusal !== null) {
            return $this->sensorReply($refusal, 403, $device);
        }

        $request->attributes->set('device', $device);

        return $next($request);
    }

    /**
     * Balasan yang bisa dibaca sensor: status, pesan untuk layar alat, dan
     * identitas perangkat supaya alat tahu balasan ini memang untuknya.
     *
     * @param  array{status: string, message: string, display_message: string, display_detail: string}  $reply
     */
    private function sensorReply(array $reply, int $status, ?Device $device = null): JsonResponse
    {
        if ($device !== null) {
            $reply += [
                'device_id' => $device->device_id,
                'device_name' => $device->name,
                'device_status' => $device->status,
                'device_status_label' => $device->statusLabel(),
            ];
        }

        return response()->json($reply + ['blocked' => true], $status);
    }
}
