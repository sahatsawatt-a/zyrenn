<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A suite of browser checks that looks after itself: run on a timetable,
 * filing a bug ticket when a check fails, and holding values a step types
 * -- a password -- apart from its steps, kept encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_suites', function (Blueprint $table) {
            // {every: off|hour|day|weekdays, at: "09:00", zone, tickets: bool}
            $table->jsonb('settings')->nullable();
            // [{name, value}], encrypted: what {{ name }} in a step stands for
            $table->text('secret_values')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
        });

        Schema::table('browser_runs', function (Blueprint $table) {
            // Who or what started it: a person, its timetable, or a workflow
            $table->string('started_by', 16)->default('person');
            // The bug tickets it filed
            $table->jsonb('tickets')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('browser_runs', function (Blueprint $table) {
            $table->dropColumn(['started_by', 'tickets']);
        });

        Schema::table('browser_suites', function (Blueprint $table) {
            $table->dropColumn(['settings', 'secret_values', 'next_run_at']);
        });
    }
};
