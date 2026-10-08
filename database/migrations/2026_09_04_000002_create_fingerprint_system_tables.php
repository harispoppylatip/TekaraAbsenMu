<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 50)->unique();
            $table->string('name', 100)->nullable();
            $table->string('location', 100)->nullable();
            $table->enum('status', ['unmapped', 'active', 'inactive'])->default('unmapped');
            $table->string('token_hash')->nullable();
            $table->timestamp('last_ping')->nullable();
            $table->timestamps();
        });

        Schema::create('fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('fingerprint_template');
            $table->string('finger_position', 30)->default('Jelunjuk Kanan');
            $table->timestamps();
        });

        Schema::create('enrollment_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 50);
            $table->unsignedTinyInteger('step')->default(1);
            $table->text('temp_template_1')->nullable();
            $table->text('temp_template_2')->nullable();
            $table->enum('status', ['waiting_tap_1', 'waiting_tap_2', 'ready', 'expired', 'completed'])->default('waiting_tap_1');
            $table->timestamps();
            $table->index(['device_id', 'status']);
        });

        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 50);
            $table->timestamp('scanned_at')->useCurrent();
            $table->enum('status', ['present', 'late', 'permission'])->default('present');
            $table->timestamps();
            $table->index(['scanned_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('enrollment_sessions');
        Schema::dropIfExists('fingerprints');
        Schema::dropIfExists('devices');
    }
};
