<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How to make a bug happen again: the steps, what should happen and what
     * does -- and a browser check of the project's that does them, run from
     * the ticket to see whether it still happens.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->json('repro')->nullable()->after('body');
            $table->foreignId('browser_suite_id')->nullable()->after('repro')->constrained('browser_suites')->nullOnDelete();
            $table->string('browser_check', 16)->nullable()->after('browser_suite_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('browser_suite_id');
            $table->dropColumn(['repro', 'browser_check']);
        });
    }
};
