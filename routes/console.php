<?php

use App\Models\Fingerprint;
use App\Models\FingerprintApiLog;
use App\Models\User;
use App\Services\AttendanceRecorderService;
use App\Services\AttendanceScheduleService;
use App\Services\DeviceRegistryService;
use App\Services\FingerprintRegistryService;
use App\Services\FingerprintScanService;
use App\Services\LessonSessionService;
use App\Services\MqttFingerprintService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('fingerprints:dedupe', function () {
    $removed = app(FingerprintRegistryService::class)->removeDuplicates();

    $this->info($removed === 0
        ? 'Tidak ada template sidik jari duplikat.'
        : $removed.' baris duplikat dihapus, tersisa satu template per pengguna dan posisi jari.');
})->purpose('Hapus template sidik jari duplikat dan sisakan yang paling baru');

Artisan::command('attendance:activate-scheduled', function (LessonSessionService $service): void {
    $activated = $service->activateScheduledSessions();

    $this->info($activated.' sesi absen terjadwal diaktifkan.');
})->purpose('Aktifkan sesi absen yang sudah mencapai jam mulai');

Artisan::command('fingerprint:mqtt-listen', function (
    MqttFingerprintService $mqtt,
    FingerprintScanService $scan,
    FingerprintRegistryService $fingerprints,
    AttendanceRecorderService $attendance,
    AttendanceScheduleService $schedule,
): void {
    if (! $mqtt->isEnabled()) {
        $this->error('MQTT_ENABLED sedang false.');

        return;
    }

    $client = $mqtt->client('subscriber-'.bin2hex(random_bytes(4)));
    $client->connect($mqtt->connectionSettings(), true);
    $this->info('Mendengarkan '.$mqtt->scanTopic());
    // Jam dicetak saat proses mulai supaya operator bisa memastikan proses ini
    // sudah memakai pengaturan terbaru; kalau jam di halaman Presensi Gerbang
    // diubah setelah baris ini muncul, proses perlu dijalankan ulang.
    $this->info($schedule->isConfigured()
        ? 'Jam presensi saat proses mulai: '.$schedule->summary()
        : 'Jam presensi belum diatur, batas bawaan yang dipakai.');

    $handleMessage = function (string $topic, string $message) use ($mqtt, $scan, $fingerprints, $attendance): void {
        fwrite(STDOUT, 'MQTT diterima: '.$topic.PHP_EOL);
        $deviceId = null;

        try {
            $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            $deviceId = is_array($payload) && is_string($payload['device_id'] ?? null)
                ? $payload['device_id']
                : null;

            if (str_ends_with($topic, '/users/request')) {
                $data = Validator::make($payload, [
                    'device_id' => ['required', 'string', 'max:50'],
                ])->validate();

                $mqtt->publishResponse($data['device_id'], 'users/response', [
                    'status' => 'success',
                    'data' => Fingerprint::with('user')->latest('id')->get()->map(fn (Fingerprint $fingerprint): array => [
                        'user_id' => $fingerprint->user->identifier_number,
                    ])->values()->all(),
                ]);

                return;
            }

            if (str_ends_with($topic, '/template/request')) {
                $data = Validator::make($payload, [
                    'device_id' => ['required', 'string', 'max:50'],
                    'user_id' => ['required', 'string', 'max:50'],
                ])->validate();
                $fingerprint = Fingerprint::query()
                    ->whereHas('user', fn ($query) => $query->where('identifier_number', $data['user_id']))
                    ->latest('id')
                    ->first();

                $mqtt->publishResponse($data['device_id'], 'template/response', $fingerprint === null
                    ? ['status' => 'not_found', 'message' => 'Template sidik jari tidak ditemukan.']
                    : ['status' => 'success', 'user_id' => $data['user_id'], 'template' => $fingerprint->template]);

                return;
            }

            if (str_ends_with($topic, '/match')) {
                $data = Validator::make($payload, [
                    'device_id' => ['required', 'string', 'max:50'],
                    'user_id' => ['nullable', 'string', 'max:50'],
                    'matched' => ['required', 'boolean'],
                ])->validate();
                $user = ! empty($data['user_id'])
                    ? User::where('identifier_number', $data['user_id'])->firstOrFail()
                    : null;
                $matched = (bool) $data['matched'];
                $message = $matched ? 'Match sensor berhasil.' : 'Sidik jari tidak cocok.';
                $attendanceResult = $matched && $user ? $attendance->record($user, $data['device_id']) : null;
                if ($attendanceResult !== null) {
                    $message = $attendanceResult['message'];
                }
                $attendanceResult ??= [];
                FingerprintApiLog::create([
                    'action' => 'match',
                    'device_id' => $data['device_id'],
                    'user_identifier' => $data['user_id'] ?? null,
                    'matched_user_id' => $matched && $user ? $user->id : null,
                    'result_status' => $matched ? 'matched' : 'no_match',
                    'message' => $message,
                    'http_status' => 200,
                ]);
                $mqtt->publishResult($data['device_id'], [
                    'status' => $matched ? 'matched' : 'no_match',
                    'message' => $message,
                    'user_id' => $data['user_id'] ?? null,
                    'name' => $user?->name,
                    'attendance_id' => ($attendanceResult['log'] ?? null)?->id,
                    'attendance_recorded' => $attendanceResult['recorded'] ?? false,
                    'attendance_type' => $attendanceResult['type'] ?? null,
                    'attendance_label' => $attendanceResult['label'] ?? null,
                    'display_message' => $attendanceResult['display_message'] ?? $user?->name,
                    'display_detail' => $attendanceResult['display_detail'] ?? '',
                ]);

                return;
            }

            if (str_ends_with($topic, '/register')) {
                $data = Validator::make($payload, [
                    'device_id' => ['required', 'string', 'max:50'],
                    'user_id' => ['required', 'string', 'max:50', 'exists:users,identifier_number'],
                    'template' => ['required', 'string', 'max:1000000'],
                    'finger_position' => ['nullable', 'string', 'max:30'],
                ])->validate();

                $user = User::where('identifier_number', $data['user_id'])->firstOrFail();
                $fingerprint = $fingerprints->store($user, $data['template'], $data['finger_position'] ?? null);
                $mqtt->publishResult($data['device_id'], [
                    'status' => 'success',
                    'message' => 'Template sidik jari berhasil disimpan.',
                    'fingerprint_id' => $fingerprint->id,
                    'user_id' => $data['user_id'],
                ]);

                return;
            }

            $data = Validator::make($payload, [
                'device_id' => ['required', 'string', 'max:50'],
                'fingerprint_data' => ['required', 'string'],
                'device_token' => ['nullable', 'string'],
            ])->validate();

            DB::statement('PRAGMA busy_timeout = 5000');
            $devices = app(DeviceRegistryService::class);
            $device = $devices->touch($data['device_id']);
            $refusal = $devices->usageRefusal($device);

            $mqtt->publishResult(
                $data['device_id'],
                $refusal === null
                    ? $scan->scan($device, $data['fingerprint_data'], $data['device_token'] ?? null, false)
                    : $refusal + ['blocked' => true],
            );
        } catch (Throwable $exception) {
            report($exception);

            if ($deviceId !== null) {
                $mqtt->publishResult($deviceId, [
                    'status' => 'error',
                    'message' => 'Server gagal memproses pembacaan.',
                    'display_message' => 'Server gagal',
                    'display_detail' => 'Coba lagi',
                    'blocked' => true,
                ]);
            }
        }
    };

    $client->subscribe($mqtt->scanTopic(), $handleMessage, (int) config('mqtt.qos'));
    $client->subscribe($mqtt->registerTopic(), $handleMessage, (int) config('mqtt.qos'));
    $client->subscribe(config('mqtt.topic_prefix').'/+/users/request', $handleMessage, (int) config('mqtt.qos'));
    $client->subscribe(config('mqtt.topic_prefix').'/+/template/request', $handleMessage, (int) config('mqtt.qos'));
    $client->subscribe(config('mqtt.topic_prefix').'/+/match', $handleMessage, (int) config('mqtt.qos'));

    $client->loop();
})->purpose('Terima pembacaan sidik jari sensor melalui MQTT');
