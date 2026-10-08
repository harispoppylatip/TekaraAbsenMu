<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Peran masuk/pulang per perangkat digantikan oleh tautan kelas: satu sensor
     * melayani kelas tertentu, dan jam presensi yang menentukan jenis absennya.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['detects_check_in', 'detects_check_out']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('detects_check_in')->default(true)->after('status');
            $table->boolean('detects_check_out')->default(true)->after('detects_check_in');
        });
    }
};
