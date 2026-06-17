<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kegiatan_pegawai', function (Blueprint $table) {
            $table->string('nomor_sertifikat')->nullable()->after('link_sertifikat');
        });
    }

    public function down(): void
    {
        Schema::table('kegiatan_pegawai', function (Blueprint $table) {
            $table->dropColumn('nomor_sertifikat');
        });
    }
};