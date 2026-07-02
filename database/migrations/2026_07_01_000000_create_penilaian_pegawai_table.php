<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('penilaian_pegawai', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Format periode: YYYY-MM
            $table->string('periode', 7)->index();
            $table->string('nip_pegawai', 20)->index();
            $table->string('nip_penilai', 20)->index();
            $table->string('role', 20)->index();
            $table->json('penilaian')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penilaian_pegawai');
    }
};
