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
        Schema::table('kegiatan_pegawai', function (Blueprint $table) {
            if (!Schema::hasColumn('kegiatan_pegawai', 'kategori_responden')) {
                $table->string('kategori_responden')->nullable()->after('nip');
                $table->index('kategori_responden');
            }
        });

        Schema::table('kegiatan_evaluasi_narasumber', function (Blueprint $table) {
            if (!Schema::hasColumn('kegiatan_evaluasi_narasumber', 'kategori_responden')) {
                $table->string('kategori_responden')->nullable()->after('nip');
                $table->index('kategori_responden');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kegiatan_pegawai', function (Blueprint $table) {
            if (Schema::hasColumn('kegiatan_pegawai', 'kategori_responden')) {
                $table->dropIndex(['kategori_responden']);
                $table->dropColumn('kategori_responden');
            }
        });

        Schema::table('kegiatan_evaluasi_narasumber', function (Blueprint $table) {
            if (Schema::hasColumn('kegiatan_evaluasi_narasumber', 'kategori_responden')) {
                $table->dropIndex(['kategori_responden']);
                $table->dropColumn('kategori_responden');
            }
        });
    }
};

