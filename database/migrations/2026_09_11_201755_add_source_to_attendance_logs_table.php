<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presensi dari sensor gerbang dan sensor kelas disimpan di tabel yang sama
     * tetapi dipisah lewat kolom `source`, supaya laporan kehadiran harian tetap
     * dihitung dari absen masuk/pulang saja. Baris lama berasal dari sensor
     * gerbang, jadi defaultnya `gate`.
     */
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->string('source', 10)->default('gate')->after('type');
            $table->index(['scanned_at', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['scanned_at', 'source']);
            $table->dropColumn('source');
        });
    }
};
