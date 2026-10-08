<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\EnrollmentSession;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class DeviceController extends Controller
{
    /**
     * Pusat kontrol perangkat sensor: perangkat yang baru terbaca, perangkat
     * terdaftar, layanan tiap alat, dan tombol daftar, matikan, sampai hapus.
     */
    public function index(): View
    {
        return view('devices.index', [
            'pendingDevices' => Device::query()
                ->where('status', Device::StatusUnmapped)
                ->orderByRaw("COALESCE(NULLIF(name, ''), device_id)")
                ->get(),
            'registeredDevices' => Device::query()
                ->with('schoolClasses')
                ->withCount(['enrollmentSessions', 'attendanceLogs'])
                ->where('status', '!=', Device::StatusUnmapped)
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Device::StatusActive])
                ->orderByRaw("COALESCE(NULLIF(name, ''), device_id)")
                ->get(),
            'classes' => SchoolClass::query()->orderedByName()->get(),
            'stats' => [
                'total' => Device::query()->count(),
                'active' => Device::query()->where('status', Device::StatusActive)->count(),
                'inactive' => Device::query()->where('status', Device::StatusInactive)->count(),
                'pending' => Device::query()->where('status', Device::StatusUnmapped)->count(),
            ],
        ]);
    }

    /**
     * Simpan nama, lokasi, dan token perangkat tanpa mengubah statusnya.
     */
    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:100'],
            'device_token' => ['nullable', 'string', 'min:12'],
        ]);

        $device->update([
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'token_hash' => filled($data['device_token'] ?? null) ? Hash::make($data['device_token']) : $device->token_hash,
        ]);

        return to_route('devices.index')
            ->with('success', 'Data perangkat '.$device->label().' berhasil disimpan.');
    }

    /**
     * Daftarkan perangkat yang baru terbaca supaya boleh dipakai untuk presensi.
     * Perangkat baru tidak bisa dipakai sebelum melewati langkah ini.
     */
    public function link(Request $request, Device $device): RedirectResponse
    {
        if ($device->isPaired()) {
            return to_route('devices.index')
                ->with('warning', 'Perangkat '.$device->label().' sudah didaftarkan, tidak ada yang diperbarui.');
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:100'],
            'device_token' => ['nullable', 'string', 'min:12'],
        ]);

        $device->update([
            'name' => filled($data['name'] ?? null) ? $data['name'] : $device->name,
            'location' => filled($data['location'] ?? null) ? $data['location'] : $device->location,
            'token_hash' => filled($data['device_token'] ?? null) ? Hash::make($data['device_token']) : $device->token_hash,
            'status' => Device::StatusActive,
        ]);

        return to_route('devices.index')
            ->with('success', 'Perangkat '.$device->label().' berhasil didaftarkan. Alat ini boleh dipakai untuk presensi.');
    }

    /**
     * Batalkan pendaftaran perangkat tanpa menghapus datanya, misalnya saat alat
     * diganti atau dipindah dari sekolah.
     */
    public function unlink(Device $device): RedirectResponse
    {
        if (! $device->isPaired()) {
            return to_route('devices.index')
                ->with('warning', 'Perangkat '.$device->label().' memang belum didaftarkan, tidak ada yang diperbarui.');
        }

        EnrollmentSession::where('device_id', $device->device_id)
            ->whereIn('status', ['waiting_tap_1', 'waiting_tap_2', 'ready'])
            ->delete();

        $device->update(['status' => Device::StatusUnmapped]);

        return to_route('devices.index')
            ->with('success', 'Pendaftaran perangkat '.$device->label().' dibatalkan. Alat ini tidak bisa dipakai untuk presensi sampai didaftarkan lagi.');
    }

    /**
     * Nyalakan atau matikan perangkat yang sudah didaftarkan.
     */
    public function setStatus(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        if (! $device->isPaired()) {
            return to_route('devices.index')
                ->withErrors(['status' => 'Daftarkan perangkat '.$device->label().' dulu sebelum dinyalakan atau dimatikan.']);
        }

        if ($device->status === $data['status']) {
            return to_route('devices.index')
                ->with('warning', 'Perangkat '.$device->label().' sudah '.($data['status'] === Device::StatusActive ? 'menyala' : 'dimatikan').', tidak ada yang diperbarui.');
        }

        $device->update(['status' => $data['status']]);

        return to_route('devices.index')->with('success', $data['status'] === Device::StatusActive
            ? 'Perangkat '.$device->label().' dinyalakan. Alat ini boleh dipakai lagi untuk presensi.'
            : 'Perangkat '.$device->label().' dimatikan. Alat ini menolak semua permintaan presensi dan menampilkan pesan Sensor dimatikan di layarnya sampai dinyalakan lagi.');
    }

    /**
     * Simpan pilihan layanan perangkat: sensor pulang masuk di gerbang, kelas
     * tertentu, atau keduanya sekaligus.
     */
    public function updateServices(Request $request, Device $device): RedirectResponse
    {
        $request->validate([
            'services' => ['nullable', 'array'],
            'services.*' => ['string'],
        ]);

        $services = collect($request->input('services', []))->map(fn ($value): string => (string) $value);

        $classIds = $services
            ->filter(fn (string $value): bool => ctype_digit($value))
            ->map(fn (string $value): int => (int) $value)
            ->unique()
            ->values();

        if ($classIds->isNotEmpty() && SchoolClass::query()->whereIn('id', $classIds)->count() !== $classIds->count()) {
            return to_route('devices.index')
                ->withErrors(['services' => 'Ada kelas yang dipilih sudah tidak ada. Muat ulang halaman ini.']);
        }

        $gate = $services->contains(Device::ServiceGate);
        $device->loadMissing('schoolClasses');
        $currentClassIds = $device->schoolClasses->pluck('id')->sort()->values();

        if ($gate === $device->isGate() && $classIds->sort()->values()->all() === $currentClassIds->all()) {
            return to_route('devices.index')
                ->with('warning', 'Layanan sensor '.$device->label().' masih sama, tidak ada yang diperbarui.');
        }

        $device->update(['serves_gate' => $gate]);
        $device->schoolClasses()->sync($classIds->all());

        $device->load('schoolClasses');

        return to_route('devices.index')
            ->with('success', 'Layanan sensor '.$device->label().' diperbarui: '.$device->serviceSummary().'.');
    }

    public function destroy(Device $device): RedirectResponse
    {
        $label = $device->label();

        EnrollmentSession::where('device_id', $device->device_id)->delete();
        $device->delete();

        return to_route('devices.index')
            ->with('success', 'Perangkat '.$label.' berhasil dihapus. Riwayat presensinya tetap tersimpan.');
    }
}
