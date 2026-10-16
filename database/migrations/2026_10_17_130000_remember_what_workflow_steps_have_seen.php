<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a step that gives out only what is new -- a feed read, "only new
     * items" -- has given out before: one line for each thing, by a hash of
     * what tells it apart, for that step of that workflow.
     */
    public function up(): void
    {
        Schema::create('workflow_seen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            // The step's id in the definition: kept through a change of its key
            $table->string('node_id', 64);
            $table->char('hash', 40);
            // The run that first gave it out: a step run again by that run gives it again
            $table->foreignId('workflow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('seen_at', 3);
            $table->unique(['workflow_id', 'node_id', 'hash']);
            $table->index(['workflow_id', 'node_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_seen');
    }
};
