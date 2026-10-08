<?php

namespace App\Http\Controllers;

use App\Services\LogMaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function __construct(private LogMaintenanceService $logMaintenance) {}

    public function index(): View
    {
        return view('settings.index', [
            'overview' => $this->logMaintenance->overview(),
            'labels' => LogMaintenanceService::labels(),
            'timezone' => config('app.timezone'),
            'serverTime' => now(),
        ]);
    }

    public function destroyLogs(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['in:'.implode(',', LogMaintenanceService::targets())],
            'mode' => ['required', 'in:days,date,all'],
            'days' => ['nullable', 'required_if:mode,days', 'integer', 'min:1', 'max:3650'],
            'date' => ['nullable', 'required_if:mode,date', 'date'],
        ], [
            'targets.required' => 'Pilih minimal satu jenis riwayat yang akan dihapus.',
            'mode.required' => 'Pilih tindakan penghapusan.',
            'days.required_if' => 'Isi jumlah hari batas penyimpanan riwayat.',
            'days.integer' => 'Jumlah hari harus berupa angka.',
            'days.min' => 'Jumlah hari minimal 1.',
            'days.max' => 'Jumlah hari maksimal 3650.',
            'date.required_if' => 'Isi tanggal riwayat yang akan dihapus.',
            'date.date' => 'Tanggal tidak valid.',
        ]);

        if ($data['mode'] === 'all') {
            $request->validate(
                ['confirm' => ['required', 'accepted']],
                [
                    'confirm.required' => 'Centang pernyataan konfirmasi sebelum menghapus semua riwayat.',
                    'confirm.accepted' => 'Centang pernyataan konfirmasi sebelum menghapus semua riwayat.',
                ]
            );
        }

        $deleted = match ($data['mode']) {
            'days' => $this->logMaintenance->deleteOlderThan($data['targets'], now()->subDays((int) $data['days'])),
            'date' => $this->logMaintenance->deleteOnDate($data['targets'], Carbon::parse($data['date'])),
            default => $this->logMaintenance->deleteAll($data['targets']),
        };

        if (array_sum($deleted) === 0) {
            return to_route('settings.index')->with('warning', $this->nothingDeleted($data));
        }

        return to_route('settings.index')->with('success', $this->summary($deleted, $data));
    }

    /**
     * Pembersihan yang tidak menemukan apa pun tidak boleh dilaporkan sebagai
     * keberhasilan, sebab pengguna akan mengira lognya sudah terhapus padahal
     * masih ada. Pesannya menyebut batas waktu dan umur log tertua.
     *
     * @param  array<string, mixed>  $data
     */
    private function nothingDeleted(array $data): string
    {
        $age = $this->logMaintenance->oldest($data['targets'])?->translatedFormat('d F Y, H:i');

        return match ($data['mode']) {
            'days' => $age === null
                ? 'Tidak ada riwayat pada jenis yang dipilih, jadi tidak ada yang dihapus.'
                : 'Tidak ada riwayat yang lebih lama dari '.$data['days'].' hari, jadi tidak ada yang dihapus. Riwayat tertua saat ini '.$age.'. Pakai pilihan Hapus semua riwayat atau turunkan jumlah hari.',
            'date' => $age === null
                ? 'Tidak ada riwayat pada jenis yang dipilih, jadi tidak ada yang dihapus.'
                : 'Tidak ada riwayat pada tanggal '.Carbon::parse($data['date'])->translatedFormat('d F Y').', jadi tidak ada yang dihapus. Riwayat tertua saat ini '.$age.'. Periksa kembali tanggalnya atau pakai pilihan Hapus semua riwayat.',
            default => 'Jenis riwayat yang dipilih sudah kosong, jadi tidak ada yang dihapus.',
        };
    }

    /**
     * @param  array<string, int>  $deleted
     * @param  array<string, mixed>  $data
     */
    private function summary(array $deleted, array $data): string
    {
        if (array_sum($deleted) === 0) {
            return 'Tidak ada riwayat yang cocok dengan pilihan tersebut.';
        }

        $labels = LogMaintenanceService::labels();
        $parts = [];

        foreach ($deleted as $target => $count) {
            if ($count > 0) {
                $parts[] = $labels[$target].' '.number_format($count, 0, ',', '.').' baris';
            }
        }

        $context = match ($data['mode']) {
            'days' => 'lebih lama dari '.$data['days'].' hari',
            'date' => 'pada tanggal '.Carbon::parse($data['date'])->translatedFormat('d F Y'),
            default => 'seluruh periode',
        };

        return implode(', ', $parts).' dihapus, '.$context.'.';
    }
}
