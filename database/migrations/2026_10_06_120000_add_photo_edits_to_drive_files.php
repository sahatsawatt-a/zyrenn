<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A picture made in the photo editor is a file of its own, which keeps the
     * original it was made from and the edit -- crop, turn, light -- so it can
     * be edited again from the whole original rather than from the cut one.
     */
    public function up(): void
    {
        Schema::table('drive_files', function (Blueprint $table) {
            $table->foreignId('source_id')->nullable()->constrained('drive_files')->nullOnDelete();
            $table->jsonb('edit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('drive_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
            $table->dropColumn('edit');
        });
    }
};
