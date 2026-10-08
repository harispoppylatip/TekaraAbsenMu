<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\EnrollmentSession;
use App\Models\FingerprintApiLog;
use App\Models\User;
use App\Services\LogMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Halaman pengaturan hanya terbuka untuk yang sudah masuk, jadi setiap tes
     * di sini dimulai sebagai admin.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();
    }

    public function test_application_uses_samarinda_timezone(): void
    {
        $this->assertSame('Asia/Makassar', config('app.timezone'));
        $this->assertSame('+08:00', now()->format('P'));
    }

    public function test_settings_page_shows_timezone_and_log_counts(): void
    {
        $this->attendanceLog();
        FingerprintApiLog::create(['action' => 'match', 'result_status' => 'matched', 'http_status' => 200]);

        $this->withoutVite()
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Asia/Makassar')
            ->assertSee('Riwayat presensi')
            ->assertSee('Catatan teknis alat');
    }

    public function test_logs_older_than_the_retention_window_are_deleted(): void
    {
        $old = $this->attendanceLog(now()->subDays(40));
        $recent = $this->attendanceLog(now()->subDays(3));

        $this->delete(route('settings.logs.destroy'), [
            'targets' => ['attendance'],
            'mode' => 'days',
            'days' => 30,
        ])->assertRedirect(route('settings.index'))->assertSessionHas('success');

        $this->assertDatabaseMissing('attendance_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('attendance_logs', ['id' => $recent->id]);
    }

    public function test_purge_that_matches_nothing_is_reported_as_a_warning(): void
    {
        $log = $this->attendanceLog(now()->subDay());

        $this->delete(route('settings.logs.destroy'), [
            'targets' => ['attendance'],
            'mode' => 'days',
            'days' => 30,
        ])
            ->assertRedirect(route('settings.index'))
            ->assertSessionMissing('success')
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'tidak ada yang dihapus'));

        $this->assertDatabaseHas('attendance_logs', ['id' => $log->id]);
    }

    public function test_purge_that_matches_nothing_names_the_oldest_log(): void
    {
        $oldest = $this->attendanceLog(now()->subDays(2));

        $this->delete(route('settings.logs.destroy'), [
            'targets' => ['attendance'],
            'mode' => 'days',
            'days' => 30,
        ])->assertSessionHas(
            'warning',
            fn (string $message) => str_contains(
                $message,
                $oldest->fresh()->scanned_at->translatedFormat('d F Y, H:i')
            )
        );
    }

    public function test_overview_reports_the_oldest_log_per_type(): void
    {
        $oldest = $this->attendanceLog(now()->subDays(5));
        $this->attendanceLog(now()->subDay());
        FingerprintApiLog::create(['action' => 'match', 'result_status' => 'matched', 'http_status' => 200]);

        $overview = app(LogMaintenanceService::class)->overview();

        $this->assertSame(2, $overview[LogMaintenanceService::TargetAttendance]['count']);
        $this->assertSame(
            $oldest->fresh()->scanned_at->toDateString(),
            $overview[LogMaintenanceService::TargetAttendance]['oldest']->toDateString()
        );
        $this->assertSame(1, $overview[LogMaintenanceService::TargetApi]['count']);
        $this->assertSame(0, $overview[LogMaintenanceService::TargetEnrollment]['count']);
        $this->assertNull($overview[LogMaintenanceService::TargetEnrollment]['oldest']);
    }

    public function test_settings_page_shows_the_age_of_the_oldest_log_per_type(): void
    {
        $this->attendanceLog(now()->subDays(2));

        $this->withoutVite()
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('baris, tertua');
    }

    public function test_logs_on_a_specific_date_are_deleted_by_scan_time(): void
    {
        $target = $this->attendanceLog(now()->subDay()->setTime(10, 0));
        $keeper = $this->attendanceLog(now()->setTime(9, 0));

        $this->delete(route('settings.logs.destroy'), [
            'targets' => ['attendance'],
            'mode' => 'date',
            'date' => now()->subDay()->toDateString(),
        ])->assertRedirect(route('settings.index'));

        $this->assertDatabaseMissing('attendance_logs', ['id' => $target->id]);
        $this->assertDatabaseHas('attendance_logs', ['id' => $keeper->id]);
    }

    public function test_deleting_every_log_requires_explicit_confirmation(): void
    {
        $log = $this->attendanceLog();

        $this->from(route('settings.index'))
            ->delete(route('settings.logs.destroy'), ['targets' => ['attendance'], 'mode' => 'all'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasErrors('confirm');

        $this->assertDatabaseHas('attendance_logs', ['id' => $log->id]);

        $this->delete(route('settings.logs.destroy'), [
            'targets' => ['attendance'],
            'mode' => 'all',
            'confirm' => '1',
        ])->assertRedirect(route('settings.index'));

        $this->assertDatabaseMissing('attendance_logs', ['id' => $log->id]);
    }

    public function test_unknown_log_target_is_rejected(): void
    {
        $log = $this->attendanceLog();

        $this->from(route('settings.index'))
            ->delete(route('settings.logs.destroy'), ['targets' => ['users'], 'mode' => 'all', 'confirm' => '1'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasErrors('targets.0');

        $this->assertDatabaseHas('attendance_logs', ['id' => $log->id]);
    }

    public function test_only_finished_enrollment_sessions_are_purged(): void
    {
        $finished = EnrollmentSession::create(['device_id' => 'ESP32-OLD', 'step' => 2, 'status' => 'completed']);
        $waiting = EnrollmentSession::create(['device_id' => 'ESP32-WAIT', 'step' => 1, 'status' => 'waiting_tap_1']);
        $ready = EnrollmentSession::create(['device_id' => 'ESP32-READY', 'step' => 2, 'status' => 'ready']);

        $this->delete(route('settings.logs.destroy'), [
            'targets' => ['enrollment'],
            'mode' => 'all',
            'confirm' => '1',
        ])->assertRedirect(route('settings.index'));

        $this->assertDatabaseMissing('enrollment_sessions', ['id' => $finished->id]);
        $this->assertDatabaseHas('enrollment_sessions', ['id' => $waiting->id]);
        $this->assertDatabaseHas('enrollment_sessions', ['id' => $ready->id]);
    }

    public function test_recent_api_logs_are_kept_when_retention_only_targets_attendance(): void
    {
        FingerprintApiLog::create([
            'action' => 'scan',
            'result_status' => 'success',
            'http_status' => 200,
        ])->forceFill(['created_at' => now()->subDays(90)])->save();

        $this->delete(route('settings.logs.destroy'), [
            'targets' => ['attendance'],
            'mode' => 'days',
            'days' => 7,
        ])->assertRedirect(route('settings.index'));

        $this->assertSame(1, FingerprintApiLog::count());
    }

    private function attendanceLog(mixed $scannedAt = null): AttendanceLog
    {
        return AttendanceLog::create([
            'user_id' => User::factory()->create()->id,
            'device_id' => 'ESP32-LOG-01',
            'scanned_at' => $scannedAt ?? now(),
            'status' => 'present',
        ]);
    }
}
