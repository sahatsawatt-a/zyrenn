<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browser checks, kept: a suite of checks -- a customer's site, say -- each a
 * few steps done in a real browser, belonging to a person or a project like
 * anything else; and each run of one, with what every step did and where its
 * pictures and video went in the Drive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_suites', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->default('');
            // [{id, title, steps: [{do, url|target|text|key}]}]
            $table->jsonb('checks');
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
            $table->index(['project_id', 'updated_at']);
        });

        Schema::create('browser_runs', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('suite_id')->constrained('browser_suites')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // How it went: its speed, captions, whether it was recorded and kept
            $table->jsonb('settings');
            // waiting, running, passed, failed
            $table->string('state', 16)->default('waiting');
            // [{id, title, steps: [{ok, took, error?, shot?}], video?, ...}]
            $table->jsonb('results')->nullable();
            // Where its pictures and video were put, when they were kept
            $table->foreignId('drive_folder_id')->nullable()->constrained('drive_folders')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['suite_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_runs');
        Schema::dropIfExists('browser_suites');
    }
};
