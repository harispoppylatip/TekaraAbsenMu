<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jam mengajar guru per kelas, disimpan sebagai teks 'HH:MM' supaya bisa
     * dibandingkan langsung sebagai jam seperti pada jam presensi. Guru hanya
     * boleh memakai sensor kelas yang sedang dia ajar sesuai rentang ini.
     */
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('teaching_schedules');
    }
};
