<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A step can wait: for a time (Delay), or for a person (Approval). It
     * says what for, until when, and keeps what it needs to carry on -- a
     * person's answer, once given.
     */
    public function up(): void
    {
        Schema::table('workflow_node_runs', function (Blueprint $table) {
            $table->string('waiting_for', 20)->nullable()->after('handle');
            $table->timestamp('resume_at', 3)->nullable()->after('waiting_for');
            $table->jsonb('wait')->nullable()->after('resume_at');

            $table->index(['status', 'resume_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workflow_node_runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'resume_at']);
            $table->dropColumn(['waiting_for', 'resume_at', 'wait']);
        });
    }
};
