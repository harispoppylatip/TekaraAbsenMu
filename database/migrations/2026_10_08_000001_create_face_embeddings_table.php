<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sidik wajah anggota.
     *
     * Satu anggota boleh punya banyak baris: satu baris untuk setiap foto
     * yang dipakai saat pendaftaran (depan, kiri, kanan, dan seterusnya).
     * Semakin banyak sudut wajah yang tersimpan, semakin tahan salah kenal.
     *
     * Yang disimpan hanya angka hasil hitungan mesin wajah (embedding), bukan
     * fotonya. Angka 128 dimensi ini sudah cukup untuk membandingkan wajah,
     * tetapi tidak bisa dikembalikan menjadi gambar, jadi wajah anggota tetap
     * tidak bisa dipulihkan dari basis data.
     */
    public function up(): void
    {
        Schema::create('face_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('embedding');
            $table->string('label', 50)->nullable();
            $table->string('model', 30)->default('dlib-resnet-v1');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_embeddings');
    }
};
