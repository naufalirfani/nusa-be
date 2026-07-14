<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        // Use raw statements to DROP NOT NULL on columns (Postgres)
        DB::statement("ALTER TABLE kegiatan ALTER COLUMN deskripsi DROP NOT NULL;");
        DB::statement("ALTER TABLE kegiatan ALTER COLUMN moderator DROP NOT NULL;");
        DB::statement("ALTER TABLE kegiatan ALTER COLUMN asal_moderator DROP NOT NULL;");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        // WARNING: setting NOT NULL back will fail if NULL values exist.
        DB::statement("ALTER TABLE kegiatan ALTER COLUMN deskripsi SET NOT NULL;");
        DB::statement("ALTER TABLE kegiatan ALTER COLUMN moderator SET NOT NULL;");
        DB::statement("ALTER TABLE kegiatan ALTER COLUMN asal_moderator SET NOT NULL;");
    }
};
