<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jam presensi disimpan sebagai teks 'HH:MM' supaya batas rentang bisa
     * dibandingkan langsung sebagai jam, tanpa ikut membawa tanggal.
     */
    public function up(): void
    {
        Schema::create('attendance_windows', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->timestamps();
        });

        $now = now();

        DB::table('attendance_windows')->insert([
            ['kind' => 'present', 'starts_at' => '06:00', 'ends_at' => '07:30', 'created_at' => $now, 'updated_at' => $now],
            ['kind' => 'late', 'starts_at' => '07:30', 'ends_at' => '09:00', 'created_at' => $now, 'updated_at' => $now],
            ['kind' => 'check_out', 'starts_at' => '15:00', 'ends_at' => '16:00', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_windows');
    }
};
