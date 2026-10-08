<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\EnrollmentSession;
use App\Models\FingerprintApiLog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LogMaintenanceService
{
    public const TargetAttendance = 'attendance';

    public const TargetApi = 'api';

    public const TargetEnrollment = 'enrollment';

    /** @return array<int, string> */
    public static function targets(): array
    {
        return [self::TargetAttendance, self::TargetApi, self::TargetEnrollment];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::TargetAttendance => 'Riwayat presensi',
            self::TargetApi => 'Catatan teknis alat',
            self::TargetEnrollment => 'Sesi pendaftaran selesai',
        ];
    }

    /**
     * Jumlah baris dan waktu log tertua per jenis. Waktu tertua dipakai supaya
     * pengguna tahu pilihan pembersihan mana yang benar-benar menghapus
     * sesuatu, bukan sekadar melihat pesan tanpa hasil.
     *
     * @return array<string, array{count: int, oldest: ?CarbonInterface}>
     */
    public function overview(): array
    {
        $overview = [];

        foreach (self::targets() as $target) {
            $summary = $this->query($target)->toBase()
                ->selectRaw('count(*) as total, min('.$this->column($target).') as oldest')
                ->first();

            $overview[$target] = [
                'count' => (int) ($summary->total ?? 0),
                'oldest' => $summary?->oldest ? Carbon::parse($summary->oldest) : null,
            ];
        }

        return $overview;
    }

    /**
     * Waktu log tertua dari jenis yang dipilih. Dipakai untuk menerangkan
     * mengapa sebuah pembersihan tidak menemukan apa pun untuk dihapus.
     *
     * @param  array<int, string>  $targets
     */
    public function oldest(array $targets): ?CarbonInterface
    {
        $selected = $this->selected($targets);
        $oldest = null;

        foreach ($this->overview() as $target => $info) {
            if (! in_array($target, $selected, true) || $info['oldest'] === null) {
                continue;
            }

            if ($oldest === null || $info['oldest']->lt($oldest)) {
                $oldest = $info['oldest'];
            }
        }

        return $oldest;
    }

    /**
     * @param  array<int, string>  $targets
     * @return array<string, int>
     */
    public function deleteOlderThan(array $targets, CarbonInterface $threshold): array
    {
        return $this->purge(
            $targets,
            fn (Builder $query, string $target) => $query->where($this->column($target), '<', $threshold),
        );
    }

    /**
     * @param  array<int, string>  $targets
     * @return array<string, int>
     */
    public function deleteOnDate(array $targets, CarbonInterface $date): array
    {
        return $this->purge($targets, function (Builder $query, string $target) use ($date) {
            $query->whereBetween($this->column($target), [
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay(),
            ]);
        });
    }

    /**
     * @param  array<int, string>  $targets
     * @return array<string, int>
     */
    public function deleteAll(array $targets): array
    {
        return $this->purge($targets);
    }

    /**
     * @param  array<int, string>  $targets
     * @param  (callable(Builder, string): void)|null  $constraint
     * @return array<string, int>
     */
    private function purge(array $targets, ?callable $constraint = null): array
    {
        $deleted = [];

        foreach ($this->selected($targets) as $target) {
            $query = $this->query($target);

            if ($constraint) {
                $constraint($query, $target);
            }

            $deleted[$target] = $query->delete();
        }

        return $deleted;
    }

    /**
     * Keep only the known targets so a crafted request cannot reach another table.
     *
     * @param  array<int, string>  $targets
     * @return array<int, string>
     */
    private function selected(array $targets): array
    {
        return array_values(array_intersect(self::targets(), $targets));
    }

    private function query(string $target): Builder
    {
        [$model, , $scope] = $this->descriptor($target);

        $query = $model::query();

        if ($scope) {
            $scope($query);
        }

        return $query;
    }

    private function column(string $target): string
    {
        return $this->descriptor($target)[1];
    }

    /**
     * @return array{0: class-string<Model>, 1: string, 2: (callable(Builder): void)|null}
     */
    private function descriptor(string $target): array
    {
        return match ($target) {
            self::TargetAttendance => [AttendanceLog::class, 'scanned_at', null],
            self::TargetApi => [FingerprintApiLog::class, 'created_at', null],
            self::TargetEnrollment => [
                EnrollmentSession::class,
                'created_at',
                fn (Builder $query) => $query->whereIn('status', ['completed', 'expired']),
            ],
        };
    }
}
