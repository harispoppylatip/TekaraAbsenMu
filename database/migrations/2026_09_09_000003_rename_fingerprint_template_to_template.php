<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fingerprints', function (Blueprint $table): void {
            $table->renameColumn('fingerprint_template', 'template');
        });

        Schema::table('fingerprints', function (Blueprint $table): void {
            $table->longText('template')->change();
        });
    }

    public function down(): void
    {
        Schema::table('fingerprints', function (Blueprint $table): void {
            $table->text('template')->change();
        });

        Schema::table('fingerprints', function (Blueprint $table): void {
            $table->renameColumn('template', 'fingerprint_template');
        });
    }
};
