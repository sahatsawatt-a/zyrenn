<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Runs of a workflow: one row for the run, one for each of its steps, and
     * the lines its Log steps write. What changes as a run goes -- statuses,
     * times, counts -- is kept in plain columns; what a step was given and
     * gave out is written once, when it is done.
     */
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            // The frozen version it runs, never the draft
            $table->foreignId('workflow_version_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            // Why it ended as it did, when that wasn't success: node_failed, budget_exceeded, ...
            $table->string('reason', 40)->nullable();
            $table->text('error')->nullable();
            // What started it: "manual" for now
            $table->string('trigger', 20);
            $table->string('trigger_node_id', 64);
            // What it was started with, for its trigger to give out; none for a test run by hand
            $table->jsonb('trigger_data')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            // A run caused by another run, and how many deep that goes
            $table->foreignId('origin_run_id')->nullable()->constrained('workflow_runs')->nullOnDelete();
            $table->unsignedSmallInteger('depth')->default(0);
            // Steps begun, against the most it may begin
            $table->unsignedInteger('executed_steps')->default(0);
            $table->unsignedInteger('max_executed_steps');
            // To the millisecond, so a step's few ms show
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['workflow_id', 'id']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('workflow_node_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            // The step in the version's definition
            $table->string('node_id', 64);
            $table->string('node_key', 40);
            $table->string('node_type', 60);
            $table->unsignedSmallInteger('node_version');
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempt')->default(0);
            // The way out it chose: "out", or "true"/"false" for an If
            $table->string('handle', 40)->nullable();
            // What it was given, its settings worked out, and what it gave out
            $table->jsonb('input')->nullable();
            $table->jsonb('output')->nullable();
            $table->jsonb('error')->nullable();
            // When a worker that took it is presumed gone
            $table->timestamp('lease_until', 3)->nullable();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->timestamps(3);

            // A step happens once in a run (loops come later, with an occurrence number)
            $table->unique(['workflow_run_id', 'node_id']);
            $table->index(['status', 'lease_until']);
        });

        Schema::create('workflow_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_node_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('level', 10);
            $table->text('message');
            $table->timestamp('created_at', 3)->nullable();

            $table->index(['workflow_run_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_run_logs');
        Schema::dropIfExists('workflow_node_runs');
        Schema::dropIfExists('workflow_runs');
    }
};
