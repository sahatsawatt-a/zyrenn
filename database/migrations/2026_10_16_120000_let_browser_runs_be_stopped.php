<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A run can be stopped part way: who asked, and when. A waiting run never
     * starts; a running one ends after the step in hand, as "stopped".
     */
    public function up(): void
    {
        Schema::table('browser_runs', function (Blueprint $table) {
            $table->timestamp('stop_asked_at')->nullable()->after('ended_at');
            $table->foreignId('stopped_by')->nullable()->after('stop_asked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('browser_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stopped_by');
            $table->dropColumn('stop_asked_at');
        });
    }
};
