<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A run started by a "For each" step of another run: which step it
     * answers to, so that step can count what has ended and gather what each
     * gave; and the key that keeps one chat's runs in order.
     */
    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->foreignId('caller_node_run_id')->nullable()->after('origin_run_id')
                ->constrained('workflow_node_runs')->nullOnDelete();
            // Where in the caller's list it was started from
            $table->unsignedInteger('caller_index')->nullable()->after('caller_node_run_id');
            // One chat's runs, kept in order or only the latest: "<trigger>:<chat id>"
            $table->string('run_key', 191)->nullable()->after('trigger_node_id');

            $table->index('caller_node_run_id');
            $table->index(['workflow_id', 'run_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropIndex(['workflow_id', 'run_key', 'status']);
            $table->dropIndex(['caller_node_run_id']);
            $table->dropConstrainedForeignId('caller_node_run_id');
            $table->dropColumn(['caller_index', 'run_key']);
        });
    }
};
