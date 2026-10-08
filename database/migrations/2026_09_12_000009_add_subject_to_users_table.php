<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guru tidak punya kelas, jadi kolom akademiknya adalah mata pelajaran.
     * Kolom ini dipisah dari `class_name` supaya nama mata pelajaran tidak
     * ikut tercatat sebagai kelas di halaman Kelas dan filter kelas.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('subject', 50)->nullable()->after('class_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('subject');
        });
    }
};
