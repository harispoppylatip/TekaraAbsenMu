<?php

namespace App\Services;

use App\Models\Device;
use App\Models\EnrollmentSession;
use App\Models\Fingerprint;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class FingerprintScanService
{
    public function __construct(
        private FingerprintRegistryService $fingerprints,
        private AttendanceRecorderService $attendance,
    ) {}

    /**
     * Perangkat sudah dipastikan tertaut sebelum masuk ke sini.
     */
    public function scan(Device $device, string $template, ?string $token = null, bool $allowEnrollment = true): array
    {
        return DB::transaction(function () use ($device, $template, $token, $allowEnrollment) {
            if ($device->token_hash && ! Hash::check($token ?? '', $device->token_hash)) {
                throw ValidationException::withMessages(['device_id' => 'Token perangkat tidak valid.']);
            }

            $deviceId = $device->device_id;

            $fingerprint = Fingerprint::with('user')->where('template', $template)->first();

            if ($fingerprint) {
                $result = $this->attendance->record($fingerprint->user, $deviceId);

                return [
                    'status' => $result['recorded'] ? 'success' : 'rejected',
                    'message' => $result['message'],
                    'user' => $fingerprint->user->name,
                    'user_id' => $fingerprint->user->identifier_number,
                    'user_id_internal' => $fingerprint->user_id,
                    'attendance_id' => $result['log']?->id,
                    'attendance_type' => $result['type'],
                    'attendance_label' => $result['label'],
                    'recorded' => $result['recorded'],
                    'display_message' => $result['display_message'],
                    'display_detail' => $result['display_detail'],
                ];
            }

            if (! $allowEnrollment) {
                return [
                    'status' => 'no_match',
                    'message' => 'Sidik jari tidak cocok.',
                    'display_message' => 'Tidak dikenali',
                    'display_detail' => 'Coba lagi',
                    'recorded' => false,
                ];
            }

            $session = EnrollmentSession::where('device_id', $deviceId)
                ->whereIn('status', ['waiting_tap_1', 'waiting_tap_2'])
                ->latest()
                ->first();

            if (! $session || $session->status === 'waiting_tap_1') {
                EnrollmentSession::create([
                    'device_id' => $deviceId,
                    'step' => 2,
                    'temp_template_1' => $template,
                    'status' => 'waiting_tap_2',
                ]);

                return ['status' => 'next_step', 'message' => 'Tap 1 berhasil. Tempelkan jari sekali lagi.'];
            }

            if (! hash_equals((string) $session->temp_template_1, $template)) {
                $session->update([
                    'step' => 1,
                    'temp_template_1' => null,
                    'temp_template_2' => null,
                    'status' => 'waiting_tap_1',
                ]);

                return ['status' => 'mismatch', 'message' => 'Jari tidak cocok. Ulangi dari Tap 1.'];
            }

            $session->update([
                'temp_template_2' => $template,
                'status' => 'ready',
            ]);

            return [
                'status' => 'completed',
                'message' => 'Data sidik jari cocok dan siap disimpan.',
                'enrollment_session_id' => $session->id,
            ];
        });
    }

    public function saveEnrollment(EnrollmentSession $session, array $data): User
    {
        if ($session->status !== 'ready' || ! $session->temp_template_1 || ! $session->temp_template_2) {
            throw ValidationException::withMessages(['enrollment' => 'Enrollment belum menyelesaikan dua tap.']);
        }

        return DB::transaction(function () use ($session, $data) {
            $existing = Fingerprint::whereIn('template', [$session->temp_template_1, $session->temp_template_2])->exists();

            if ($existing) {
                throw ValidationException::withMessages(['fingerprint' => 'Sidik jari sudah terdaftar atas nama lain.']);
            }

            $user = User::create($data + ['role' => $data['role'] ?? 'student', 'status' => 'active']);
            $this->fingerprints->store($user, $session->temp_template_2, $data['finger_position'] ?? null);
            $session->update(['status' => 'completed']);

            return $user;
        });
    }
}
