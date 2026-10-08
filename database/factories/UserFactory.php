<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Pengguna hasil pabrik dianggap sudah memakai kata sandinya sendiri, jadi
     * `must_change_password` dimatikan di sini. Keadaan bawaan basis data yang
     * mewajibkan ganti kata sandi punya keadaan khusus `mustChangePassword`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => User::RoleStudent,
            'status' => User::StatusActive,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Akun admin: pengelola jam pelajaran, jadwal, kelas, dan anggota.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => User::RoleAdmin,
            'subject' => null,
            'class_name' => null,
        ]);
    }

    /**
     * Akun guru. Kelasnya sengaja kosong karena guru mengajar menurut jadwal,
     * bukan menurut satu kelas tetap.
     */
    public function teacher(?string $subject = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => User::RoleTeacher,
            'subject' => $subject ?? fake()->randomElement(['Matematika', 'Bahasa Indonesia', 'Informatika']),
            'class_name' => null,
        ]);
    }

    /**
     * Akun siswa. Nama kelasnya diberikan lewat parameter supaya tetap sama
     * dengan kelas yang dibuat di dalam tes.
     */
    public function student(?string $className = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => User::RoleStudent,
            'identifier_number' => (string) fake()->unique()->numerify('############'),
            'class_name' => $className,
            'subject' => null,
        ]);
    }

    /**
     * Akun yang tidak boleh masuk.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => User::StatusInactive,
        ]);
    }

    /**
     * Akun yang masih memakai kata sandi bawaan, jadi belum boleh membuka
     * halaman aplikasi sebelum menggantinya.
     */
    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes): array => [
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);
    }

    /**
     * Tentukan kata sandi yang bisa dipakai untuk masuk di dalam tes.
     */
    public function withPassword(string $plain): static
    {
        return $this->state(fn (array $attributes): array => [
            'password' => Hash::make($plain),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }
}
