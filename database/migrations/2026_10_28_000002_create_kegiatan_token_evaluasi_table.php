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
        Schema::create('kegiatan_token_evaluasi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('kegiatan_id');
            $table->string('token', 64)->unique();
            $table->string('jenis', 32)->default('kegiatan'); // 'kegiatan' | 'narasumber'
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->string('used_by')->nullable();
            $table->timestamps();

            $table->foreign('kegiatan_id')
                ->references('id')
                ->on('kegiatan')
                ->onDelete('cascade');

            $table->index('kegiatan_id');
            $table->index('token');
            $table->index('is_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kegiatan_token_evaluasi');
    }
};

