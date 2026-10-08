<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jam mengajar lama digantikan jadwal pelajaran.
     *
     * Tabel `teaching_schedules` hanya menyimpan rentang jam, tanpa hari dan
     * tanpa mata pelajaran, sehingga tidak bisa dipakai untuk memutuskan siapa
     * yang berhak absen pada pertemuan tertentu. Datanya tidak dipindahkan
     * otomatis karena tidak ada pasangan hari yang pasti; jadwal diisi ulang
     * lewat halaman Jadwal Pelajaran.
     */
    public function up(): void
    {
        Schema::dropIfExists('teaching_schedules');
    }

    public function down(): void
    {
        // Dibuat kembali dalam keadaan kosong; isinya sudah tidak bisa
        // dikembalikan karena jam mengajar lama tidak punya padanan hari.
        Schema::create('teaching_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->timestamps();

            $table->index(['user_id', 'school_class_id']);
        });
    }
};
