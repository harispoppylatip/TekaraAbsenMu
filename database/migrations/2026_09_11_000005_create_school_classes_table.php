<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_classes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->index('class_name');
        });

        $this->importClassNamesUsedByMembers();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['class_name']);
        });

        Schema::dropIfExists('school_classes');
    }

    /**
     * Kelas yang sudah dipakai anggota didaftarkan lebih dulu supaya daftar kelas
     * langsung sinkron dengan data anggota saat halaman Kelas pertama dibuka.
     */
    private function importClassNamesUsedByMembers(): void
    {
        $now = now();

        $names = DB::table('users')
            ->whereNotNull('class_name')
            ->where('class_name', '!=', '')
            ->distinct()
            ->pluck('class_name')
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values();

        if ($names->isEmpty()) {
            return;
        }

        DB::table('school_classes')->insert(
            $names->map(fn (string $name): array => [
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }
};
