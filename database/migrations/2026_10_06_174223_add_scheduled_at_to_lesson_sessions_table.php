<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_sessions', function (Blueprint $table): void {
            $table->timestamp('opened_at')->nullable()->change();
            $table->timestamp('scheduled_at')->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_sessions', function (Blueprint $table): void {
            $table->dropColumn('scheduled_at');
            $table->timestamp('opened_at')->nullable(false)->change();
        });
    }
};
