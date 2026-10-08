<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu perangkat presensi hanya boleh melayani jenis absen tertentu, supaya
     * alat di pintu masuk dan alat di pintu pulang bisa dipisah. Jam kapan tiap
     * jenis absen berlaku tetap diatur di tabel `attendance_windows`.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('detects_check_in')->default(true)->after('status');
            $table->boolean('detects_check_out')->default(true)->after('detects_check_in');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['detects_check_in', 'detects_check_out']);
        });
    }
};
