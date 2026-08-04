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
        Schema::create('kegiatan_evaluasi_narasumber', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('kegiatan_id');
            $table->string('nip')->nullable();
            $table->json('isi_form');
            $table->timestamps();

            $table->foreign('kegiatan_id')
                ->references('id')
                ->on('kegiatan')
                ->onDelete('cascade');

            $table->index('kegiatan_id');
            $table->index('nip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kegiatan_evaluasi_narasumber');
    }
};
