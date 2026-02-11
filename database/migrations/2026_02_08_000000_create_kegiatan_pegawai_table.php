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
        Schema::create('kegiatan_pegawai', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('sequence_number');
            $table->uuid('kegiatan_id');
            $table->string('nip');
            $table->json('isi_form');
            $table->string('link_sertifikat')->nullable();
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('kegiatan_id')
                ->references('id')
                ->on('kegiatan')
                ->onDelete('cascade');

            // Indexes for better query performance
            $table->index('kegiatan_id');
            $table->index('nip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kegiatan_pegawai');
    }
};
