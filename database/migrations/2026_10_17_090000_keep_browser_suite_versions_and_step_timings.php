<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A suite's checks as each version of them was, each run marked with the
     * version it ran, and how long each step took and waited on the site in
     * each run -- by the step's own id, so its history follows it through
     * edits -- for telling how long a step usually needs.
     */
    public function up(): void
    {
        Schema::table('browser_suites', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(0)->after('checks');
        });

        Schema::create('browser_suite_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('browser_suite_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('checks');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['browser_suite_id', 'version']);
        });

        Schema::table('browser_runs', function (Blueprint $table) {
            $table->unsignedInteger('suite_version')->nullable()->after('browser_suite_id');
        });

        Schema::create('browser_step_timings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('browser_suite_id')->constrained()->cascadeOnDelete();
            $table->foreignId('browser_run_id')->constrained()->cascadeOnDelete();
            $table->string('check_id', 16);
            $table->string('step_id', 16);
            $table->unsignedInteger('suite_version')->nullable();
            $table->string('kind', 16);
            $table->unsignedInteger('took_ms');
            $table->unsignedInteger('waited_ms')->nullable();
            $table->boolean('ok');
            $table->boolean('timed_out')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->index(['browser_suite_id', 'step_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_step_timings');
        Schema::table('browser_runs', fn (Blueprint $table) => $table->dropColumn('suite_version'));
        Schema::dropIfExists('browser_suite_versions');
        Schema::table('browser_suites', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
