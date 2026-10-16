<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each step of a run met in the browser -- its console, its page
     * errors, the requests that failed -- for the app's admins to read.
     */
    public function up(): void
    {
        Schema::table('browser_runs', function (Blueprint $table) {
            $table->jsonb('log')->nullable()->after('results');
        });
    }

    public function down(): void
    {
        Schema::table('browser_runs', function (Blueprint $table) {
            $table->dropColumn('log');
        });
    }
};
