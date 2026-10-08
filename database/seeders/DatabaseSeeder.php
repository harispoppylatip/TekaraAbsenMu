<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Yang disemai hanya satu akun admin, karena akun inilah pintu masuk untuk
     * mengisi jam pelajaran, jadwal, kelas, dan anggota. Akun guru dan siswa
     * dibuat lewat halaman Anggota supaya nomor induk dan kelasnya benar, dan
     * keduanya memakai kata sandi bawaan yang wajib diganti saat pertama masuk.
     * Akun admin sengaja tidak diduplikasi saat penyemaian diulang, hanya kata
     * sandinya dikembalikan ke bawaan.
     *
     * Nomor induk admin ikut diisi karena nomor inilah identitas yang dikirim
     * ke sensor saat sidik jarinya didaftarkan. Tanpa nomor itu, tombol scan
     * sidik jari di halaman Anggota tidak menghasilkan perintah apa pun.
     *
     * Kamera wajah juga disemai karena ia bukan alat yang ditemukan sendiri oleh
     * server: yang bertindak sebagai sensor adalah browser petugas, sementara
     * identitas sensornya dipakai bersama untuk absen wajah dan pendaftaran
     * wajah. Ia didaftarkan sebagai sensor gerbang yang langsung aktif, dan admin
     * tetap bisa mematikannya dari halaman Alat Sensor. Penyemaian ulang tidak
     * mengubah nama atau status yang sudah diatur admin.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@tekara.my.id'],
            [
                'name' => 'Admin Sekolah',
                'identifier_number' => 'ADM-001',
                'password' => User::DefaultPassword,
                'role' => User::RoleAdmin,
                'status' => User::StatusActive,
                'must_change_password' => true,
                'password_changed_at' => null,
                'email_verified_at' => now(),
            ],
        );

        Device::query()->firstOrCreate(
            ['device_id' => 'kamera-wajah'],
            [
                'name' => 'Kamera Wajah',
                'location' => 'Ruang piket',
                'status' => Device::StatusActive,
                'serves_gate' => true,
                'last_ping' => now(),
            ],
        );
    }
}
