<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\FingerprintApiLog;
use App\Services\FingerprintScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FingerprintScanController extends Controller
{
    public function __invoke(Request $request, FingerprintScanService $service): JsonResponse
    {
        Cache::put('debug.last_fingerprint_scan', [
            'received_at' => now()->format('Y-m-d H:i:s'),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'user_agent' => $request->userAgent(),
            'payload' => $request->all(),
        ], now()->addHour());

        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:50'],
            'fingerprint_data' => ['required', 'string'],
            'device_token' => ['nullable', 'string'],
        ]);

        /** @var Device $device */
        $device = $request->attributes->get('device');
        $template = $data['fingerprint_data'];
        $result = $service->scan($device, $template, $data['device_token'] ?? null);
        $metrics = $this->templateMetrics($template);

        FingerprintApiLog::create([
            'action' => 'scan',
            'device_id' => $data['device_id'],
            'user_identifier' => $result['user_id'] ?? null,
            'matched_user_id' => $result['user_id_internal'] ?? null,
            'template_length' => strlen($template),
            'template_nonzero_bytes' => $metrics['nonzero_bytes'],
            'template_hash' => hash('sha256', $template),
            'result_status' => $result['status'] ?? null,
            'message' => $result['message'] ?? null,
            'http_status' => 200,
        ]);

        return response()->json($result);
    }

    /** @return array{nonzero_bytes: int|null} */
    private function templateMetrics(string $template): array
    {
        if (strlen($template) % 2 !== 0 || ! ctype_xdigit($template)) {
            return ['nonzero_bytes' => null];
        }

        $nonzeroBytes = 0;
        for ($index = 0; $index < strlen($template); $index += 2) {
            if (substr($template, $index, 2) !== '00') {
                $nonzeroBytes++;
            }
        }

        return ['nonzero_bytes' => $nonzeroBytes];
    }
}
