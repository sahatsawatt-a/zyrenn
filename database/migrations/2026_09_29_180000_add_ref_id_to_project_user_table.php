<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A membership is changed and ended by URL, which never shows an internal id.
     */
    public function up(): void
    {
        Schema::table('project_user', function (Blueprint $table) {
            $table->string('ref_id', 16)->nullable()->after('id');
        });

        foreach (DB::table('project_user')->pluck('id') as $id) {
            do {
                $refId = Str::lower(Str::random(10));
            } while (DB::table('project_user')->where('ref_id', $refId)->exists());

            DB::table('project_user')->where('id', $id)->update(['ref_id' => $refId]);
        }

        Schema::table('project_user', function (Blueprint $table) {
            $table->string('ref_id', 16)->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_user', function (Blueprint $table) {
            $table->dropUnique(['ref_id']);
            $table->dropColumn('ref_id');
        });
    }
};
