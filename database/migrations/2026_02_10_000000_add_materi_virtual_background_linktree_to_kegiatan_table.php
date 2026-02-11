<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('kegiatan', function (Blueprint $table) {
            $table->string('materi')->nullable()->after('banner');
            $table->string('virtual_background')->nullable()->after('materi');
            $table->string('linktree', 15)->nullable()->after('virtual_background');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kegiatan', function (Blueprint $table) {
            $table->dropColumn(['materi', 'virtual_background', 'linktree']);
        });
    }
};
