<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu perangkat bisa dipilih melayani absen pulang masuk di gerbang, kelas
     * tertentu, atau keduanya. Perangkat baru dianggap sensor gerbang supaya
     * alat yang baru terdeteksi langsung bisa mencatat presensi seperti sebelum
     * ada pengaturan layanan.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('serves_gate')->default(true)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('serves_gate');
        });
    }
};
