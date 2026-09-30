<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who changed each note, board, table and file last, for "edited by" in
     * a project's lists. Kept when they leave; cleared when their account goes.
     *
     * @var list<string>
     */
    private const EDITED = ['notes', 'boards', 'tables', 'drive_files'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::EDITED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::EDITED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('updated_by');
            });
        }
    }
};
