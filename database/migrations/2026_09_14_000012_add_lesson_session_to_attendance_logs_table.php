<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hubungkan catatan presensi dengan sesi absen jam pelajaran yang
     * menghasilkannya, supaya rekap per jam pelajaran bisa dihitung tanpa
     * menebak dari jam scan.
     *
     * Penghapusan sesi mengosongkan kolom ini, bukan ikut menghapus catatan
     * presensinya, sebab riwayat kehadiran harus tetap utuh. Catatan presensi
     * lama (dari sensor gerbang atau sebelum fitur ini ada) bernilai kosong.
     */
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->foreignId('lesson_session_id')->nullable()->after('device_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lesson_session_id');
        });
    }
};
