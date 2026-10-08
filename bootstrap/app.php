<?php

use App\Http\Middleware\EnsureCanScanFace;
use App\Http\Middleware\EnsureDeviceIsPaired;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\KeepFlashDataOnLiveRefresh;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('attendance:activate-scheduled')
            ->everyMinute()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'api/fingerprint/*',
            'api/face/*',
        ]);

        $middleware->web(append: [
            KeepFlashDataOnLiveRefresh::class,
        ]);

        $middleware->alias([
            'device.paired' => EnsureDeviceIsPaired::class,
            'role' => EnsureUserHasRole::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            'face.operator' => EnsureCanScanFace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Sensor menampilkan pesan dari `display_message`, jadi setiap permintaan
        // API yang datanya tidak lengkap juga dibalas dengan teks untuk layarnya.
        $exceptions->render(function (ValidationException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'status' => 'invalid_request',
                'message' => $exception->validator->errors()->first(),
                'display_message' => 'Data alat kurang',
                'display_detail' => 'Lapor operator',
                'errors' => $exception->errors(),
                'blocked' => true,
            ], 422);
        });
    })->create();
