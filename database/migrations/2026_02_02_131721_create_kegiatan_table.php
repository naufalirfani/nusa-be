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
        Schema::create('kegiatan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('banner')->nullable();
            $table->string('nama_kegiatan');
            $table->string('judul_tema');
            $table->text('deskripsi');
            $table->string('narasumber');
            $table->enum('asal_narasumber', ['Internal', 'Eksternal']);
            $table->string('moderator');
            $table->enum('asal_moderator', ['Internal', 'Eksternal']);
            $table->string('tempat');
            $table->date('tanggal');
            $table->time('jam_mulai');
            $table->time('jam_selesai');
            $table->json('desain_sertifikat')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kegiatan');
    }
};
