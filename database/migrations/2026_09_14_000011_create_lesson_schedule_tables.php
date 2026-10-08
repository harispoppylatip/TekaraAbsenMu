<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inti pengaturan jam pelajaran, terdiri dari tiga tabel berurutan:
     * `lesson_hours` (jam ke berapa), `lesson_schedules` (pelajaran apa di kelas
     * mana, hari apa, oleh guru siapa), dan `lesson_sessions` (pertemuan nyata
     * yang absennya dibuka guru).
     *
     * Jam disimpan sebagai teks 'HH:MM' seperti jam presensi supaya batasnya
     * bisa dibandingkan langsung tanpa ikut membawa tanggal. Hari memakai
     * penomoran ISO (1 = Senin sampai 5 = Jumat) supaya sama dengan Carbon.
     */
    public function up(): void
    {
        Schema::create('lesson_hours', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('number')->unique();
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->timestamps();
        });

        Schema::create('lesson_schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('day');
            $table->foreignId('lesson_hour_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 50);
            $table->timestamps();

            // Satu kelas hanya boleh punya satu pelajaran pada satu jam, dan
            // satu guru tidak mungkin mengajar dua kelas pada jam yang sama.
            $table->unique(['day', 'lesson_hour_id', 'school_class_id'], 'lesson_schedules_class_unique');
            $table->unique(['day', 'lesson_hour_id', 'user_id'], 'lesson_schedules_teacher_unique');
            $table->index(['user_id', 'day']);
        });

        Schema::create('lesson_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('substitute_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 100)->nullable();
            $table->timestamps();

            // Sesi absen hanya terjadi sekali untuk satu pertemuan.
            $table->unique(['lesson_schedule_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sessions');
        Schema::dropIfExists('lesson_schedules');
        Schema::dropIfExists('lesson_hours');
    }
};
