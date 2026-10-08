<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu baris presensi harus bisa dibedakan sebagai absen masuk atau absen
     * pulang. Jam kapan tiap jenis absen berlaku diatur di tabel
     * `attendance_windows`, bukan di perangkat.
     */
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->string('type', 5)->default('in')->after('device_id');
            $table->index(['scanned_at', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['scanned_at', 'type']);
            $table->dropColumn('type');
        });
    }
};
