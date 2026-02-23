<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the signed_at timestamp column to kegiatan_pegawai.
 *
 * signed_at records when the PDF was last digitally signed.
 * A NULL value means the record was created before digital signing was
 * introduced and should be regenerated.
 *
 * The pdf_hash column is kept (nullable) for backward compatibility with any
 * existing data, but is no longer written or read by the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kegiatan_pegawai', function (Blueprint $table) {
            $table->timestamp('signed_at')->nullable()->after('pdf_hash');
        });
    }

    public function down(): void
    {
        Schema::table('kegiatan_pegawai', function (Blueprint $table) {
            $table->dropColumn('signed_at');
        });
    }
};
