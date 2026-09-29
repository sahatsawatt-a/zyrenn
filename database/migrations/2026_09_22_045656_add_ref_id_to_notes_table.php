<?php

use App\Models\Note\Note;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->string('ref_id', 16)->nullable()->after('id');
        });

        // Backfill existing notes before making the column required
        DB::table('notes')->whereNull('ref_id')->orderBy('id')->each(function (object $note) {
            DB::table('notes')->where('id', $note->id)->update(['ref_id' => Note::newRefId()]);
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->string('ref_id', 16)->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropUnique(['ref_id']);
            $table->dropColumn('ref_id');
        });
    }
};
