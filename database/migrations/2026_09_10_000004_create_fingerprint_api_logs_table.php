<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fingerprint_api_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 20);
            $table->string('device_id', 50)->nullable();
            $table->string('user_identifier', 50)->nullable();
            $table->foreignId('matched_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('template_length')->nullable();
            $table->unsignedInteger('template_nonzero_bytes')->nullable();
            $table->string('template_hash', 64)->nullable();
            $table->string('result_status', 30)->nullable();
            $table->text('message')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_api_logs');
    }
};
