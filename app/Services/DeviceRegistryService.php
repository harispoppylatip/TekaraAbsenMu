<?php

namespace App\Services;

use App\Models\Device;

class DeviceRegistryService
{
    /**
     * Deteksi perangkat pada kontak pertama dan perbarui aktivitas terakhirnya.
     *
     * Perangkat lahir sebagai `unmapped` supaya halaman Alat Sensor bisa
     * menampilkannya untuk didaftarkan.
     */
    public function touch(string $deviceId): Device
    {
        $device = Device::firstOrCreate(['device_id' => $deviceId]);

        $device->update(['last_ping' => now()]);

        return $device;
    }

    /**
     * Pesan penolakan untuk perangkat yang belum didaftarkan atau sedang
     * dimatikan. `null` berarti perangkat boleh dipakai untuk presensi.
     *
     * Balasan ini juga dikirim ke sensor, jadi `display_message` dan
     * `display_detail` dipakai apa adanya oleh firmware sebagai dua baris layar
     * alat. Keduanya wajib pendek: LCD alat hanya 16 kolom, teks yang lebih
     * panjang akan terpotong di tengah kata. `message` menjelaskan langkah
     * perbaikannya untuk operator di aplikasi.
     *
     * @return array{status: string, message: string, display_message: string, display_detail: string}|null
     */
    public function usageRefusal(Device $device): ?array
    {
        if (! $device->isPaired()) {
            return [
                'status' => 'device_unregistered',
                'message' => 'Perangkat belum didaftarkan. Daftarkan perangkat di halaman Alat Sensor sebelum dipakai untuk presensi.',
                'display_message' => 'Belum terdaftar',
                'display_detail' => 'Lapor operator',
            ];
        }

        if (! $device->isUsable()) {
            return [
                'status' => 'device_inactive',
                'message' => 'Perangkat sedang tidak aktif. Nyalakan perangkat di halaman Alat Sensor sebelum dipakai untuk presensi.',
                'display_message' => 'Sensor dimatikan',
                'display_detail' => 'Lapor operator',
            ];
        }

        return null;
    }
}
