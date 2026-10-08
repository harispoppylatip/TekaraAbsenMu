<?php

namespace App\Http\Controllers;

use App\Models\Fingerprint;
use App\Models\FingerprintApiLog;
use App\Models\User;
use App\Services\AttendanceRecorderService;
use App\Services\FingerprintRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FingerprintApiController extends Controller
{
    public function __construct(
        private FingerprintRegistryService $fingerprints,
        private AttendanceRecorderService $attendance,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:50'],
            'user_id' => ['required', 'string', 'max:50', 'exists:users,identifier_number'],
            'template' => ['required', 'string', 'max:1000000'],
            'finger_position' => ['nullable', 'string', 'max:30'],
        ]);

        $user = User::where('identifier_number', $data['user_id'])->firstOrFail();
        $fingerprint = $this->fingerprints->store($user, $data['template'], $data['finger_position'] ?? null);
        FingerprintApiLog::create([
            'action' => 'register',
            'device_id' => $data['device_id'] ?? null,
            'user_identifier' => $data['user_id'],
            'matched_user_id' => $user->id,
            'template_length' => strlen($data['template']),
            'template_hash' => hash('sha256', $data['template']),
            'result_status' => 'success',
            'message' => 'Template sidik jari berhasil disimpan.',
            'http_status' => 201,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Template sidik jari berhasil disimpan.',
            'data' => [
                'id' => $fingerprint->id,
                'user_id' => $data['user_id'],
                'finger_position' => $fingerprint->finger_position,
            ],
        ], 201);
    }

    public function template(Request $request, string $userId): JsonResponse
    {
        $deviceId = $request->string('device_id')->toString();

        $fingerprint = Fingerprint::query()
            ->whereHas('user', fn ($query) => $query->where('identifier_number', $userId))
            ->latest('id')
            ->first();

        if (! $fingerprint) {
            FingerprintApiLog::create([
                'action' => 'lookup',
                'device_id' => $deviceId !== '' ? $deviceId : null,
                'user_identifier' => $userId,
                'result_status' => 'not_found',
                'message' => 'Template sidik jari tidak ditemukan.',
                'http_status' => 404,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Template sidik jari tidak ditemukan.',
            ], 404);
        }

        FingerprintApiLog::create([
            'action' => 'lookup',
            'device_id' => $deviceId !== '' ? $deviceId : null,
            'user_identifier' => $userId,
            'matched_user_id' => $fingerprint->user_id,
            'template_length' => strlen($fingerprint->template),
            'template_hash' => hash('sha256', $fingerprint->template),
            'result_status' => 'success',
            'message' => 'Template sidik jari ditemukan.',
            'http_status' => 200,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Template sidik jari ditemukan.',
            'data' => [
                'user_id' => $userId,
                'template' => $fingerprint->template,
            ],
        ]);
    }

    public function match(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:50'],
            'user_id' => ['nullable', 'string', 'max:50', 'exists:users,identifier_number'],
            'matched' => ['required', 'boolean'],
        ]);

        $user = ! empty($data['user_id'])
            ? User::where('identifier_number', $data['user_id'])->firstOrFail()
            : null;
        $matched = (bool) $data['matched'];
        $status = $matched ? 'matched' : 'no_match';
        $message = $matched ? 'Match sensor berhasil.' : 'Sidik jari tidak cocok.';
        $attendance = $matched && $user ? $this->attendance->record($user, $data['device_id']) : null;

        if ($attendance !== null) {
            $message = $attendance['message'];
        }

        $attendance ??= [];

        FingerprintApiLog::create([
            'action' => 'match',
            'device_id' => $data['device_id'],
            'user_identifier' => $data['user_id'] ?? null,
            'matched_user_id' => $matched && $user ? $user->id : null,
            'result_status' => $status,
            'message' => $message,
            'http_status' => 200,
        ]);

        return response()->json([
            'status' => $status,
            'message' => $message,
            'user_id' => $data['user_id'] ?? null,
            'name' => $user?->name,
            'attendance_id' => ($attendance['log'] ?? null)?->id,
            'attendance_recorded' => $attendance['recorded'] ?? false,
            'attendance_type' => $attendance['type'] ?? null,
            'attendance_label' => $attendance['label'] ?? null,
            'display_message' => $attendance['display_message'] ?? $user?->name,
            'display_detail' => $attendance['display_detail'] ?? '',
        ]);
    }

    public function command(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:50'],
        ]);

        $command = Cache::pull('fingerprint.command.'.$data['device_id']);

        return response()->json($command ?: ['mode' => 'verify']);
    }

    public function templates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:50'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => Fingerprint::with('user')->latest('id')->get()->map(fn (Fingerprint $fingerprint) => [
                'user_id' => $fingerprint->user->identifier_number,
                'template' => $fingerprint->template,
            ])->values(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:50'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => Fingerprint::with('user')->latest('id')->get()->map(fn (Fingerprint $fingerprint) => [
                'user_id' => $fingerprint->user->identifier_number,
            ])->values(),
        ]);
    }
}
