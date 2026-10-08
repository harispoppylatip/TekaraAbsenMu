<?php

namespace App\Services;

use App\Models\AttendanceWindow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Membaca jam presensi yang berlaku. Semua jalur scan memakai service ini agar
 * aturan jam hanya dihitung di satu tempat.
 *
 * Jam selalu dibaca ulang dari database, tidak disimpan di properti instance.
 * Service ini juga dipakai proses berjalan lama seperti `fingerprint:mqtt-listen`;
 * kalau hasilnya di-cache di memori, perubahan jam presensi di halaman Presensi
 * Gerbang tidak akan terbaca perangkat sampai proses itu dijalankan ulang.
 */
class AttendanceScheduleService
{
    /**
     * @return Collection<int, AttendanceWindow>
     */
    public function windows(): Collection
    {
        return AttendanceWindow::query()->ordered()->get();
    }

    /**
     * Belum ada jam presensi sama sekali. Presensi tetap dicatat supaya
     * perangkat tidak pernah mentok, tetapi aturannya memakai batas lama.
     */
    public function isConfigured(): bool
    {
        return $this->windows()->isNotEmpty();
    }

    /**
     * Rentang jam yang mencakup waktu scan, atau null kalau di luar jam absen.
     */
    public function windowAt(CarbonInterface $at): ?AttendanceWindow
    {
        $time = $at->format('H:i');

        return $this->windows()->first(
            fn (AttendanceWindow $window): bool => $window->covers($time)
        );
    }

    /**
     * Ringkasan jam untuk pesan yang dikirim ke perangkat dan ke pengguna.
     */
    public function summary(): string
    {
        return $this->windows()
            ->map(fn (AttendanceWindow $window): string => $window->range().' '.$window->label())
            ->implode(', ');
    }
}
