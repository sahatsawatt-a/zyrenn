<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keeping an eye on workflows: when each step was queued, so the wait for
     * a worker can be measured; whether someone was told a run went wrong;
     * and a record of who changed what -- published, switched on, ran by
     * hand, added a connection -- kept for its owner to look back on.
     */
    public function up(): void
    {
        Schema::table('workflow_node_runs', function (Blueprint $table) {
            $table->timestamp('queued_at', 3)->nullable()->after('attempt');
            $table->index(['status', 'queued_at']);
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            // Someone was told it went wrong, or is stuck: told once
            $table->timestamp('alerted_at')->nullable()->after('finished_at');
            $table->index(['status', 'finished_at']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            // Whose it is: a person's or a project's, as for what it is about
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            // Who did it; none for the app itself
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // workflow.published, connection.added, ...
            $table->string('action', 40);
            $table->string('subject_type', 20);
            $table->string('subject_ref', 16);
            // Its name then, so the record reads well after it is renamed or gone
            $table->string('subject_name')->nullable();
            // What changed, never a secret
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index(['subject_type', 'subject_ref', 'id']);
            $table->index(['user_id', 'id']);
            $table->index(['project_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');

        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'finished_at']);
            $table->dropColumn('alerted_at');
        });

        Schema::table('workflow_node_runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'queued_at']);
            $table->dropColumn('queued_at');
        });
    }
};
