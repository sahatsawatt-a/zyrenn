<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What lets a workflow start by itself and reach outside: which published
     * workflows listen for what, every event that came in (so one sent twice
     * starts nothing twice), every send to the outside (so one that may have
     * gone out is never sent again unasked), and the chat rooms and messages
     * a workflow makes.
     */
    public function up(): void
    {
        // Rebuilt whenever a workflow is published, switched on or switched off
        Schema::create('workflow_listeners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            // chat_room, schedule, webhook, telegram, line
            $table->string('kind', 20);
            // What it listens to: a room's ref_id, a webhook's token, a connection's ref_id
            $table->string('key', 80)->default('');
            $table->jsonb('settings')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['kind', 'key']);
        });

        Schema::create('inbound_events', function (Blueprint $table) {
            $table->id();
            // Where it came from: telegram:<connection>, webhook:<token>, chat:<room>, schedule:<workflow>
            $table->string('source', 120);
            // The sender's own id for it, so a second delivery is known for one
            $table->string('event_id', 191);
            $table->jsonb('envelope')->nullable();
            $table->unsignedSmallInteger('runs')->default(0);
            $table->timestamp('received_at', 3)->nullable();

            $table->unique(['source', 'event_id']);
            $table->index('received_at');
        });

        Schema::create('workflow_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_node_run_id')->constrained()->cascadeOnDelete();
            $table->string('service', 20);
            $table->string('target', 191)->nullable();
            // Sent along where the service takes one, so a repeat is not delivered twice
            $table->string('idempotency_key', 80)->nullable();
            // sending, sent, failed -- or unknown, when a worker died between
            $table->string('status', 10);
            $table->jsonb('response')->nullable();
            $table->timestamps(3);

            $table->index(['workflow_node_run_id', 'status']);
        });

        Schema::table('chat_rooms', function (Blueprint $table) {
            // What outside conversation it stands for, e.g. telegram:123456 -- one room each, per owner
            $table->string('external_key', 120)->nullable()->after('title');
        });

        // One room per outside conversation, for each owner. Partial, since
        // a room belongs to a user or to a project and the other is empty
        DB::statement('CREATE UNIQUE INDEX chat_rooms_user_external_key_unique ON chat_rooms (user_id, external_key) WHERE external_key IS NOT NULL AND project_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX chat_rooms_project_external_key_unique ON chat_rooms (project_id, external_key) WHERE external_key IS NOT NULL AND project_id IS NOT NULL');

        Schema::table('chat_messages', function (Blueprint $table) {
            // The workflow step that said it: said once, however often the step is tried
            $table->foreignId('workflow_node_run_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->unique('workflow_node_run_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropUnique(['workflow_node_run_id']);
            $table->dropConstrainedForeignId('workflow_node_run_id');
        });

        DB::statement('DROP INDEX IF EXISTS chat_rooms_user_external_key_unique');
        DB::statement('DROP INDEX IF EXISTS chat_rooms_project_external_key_unique');

        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropColumn('external_key');
        });

        Schema::dropIfExists('workflow_deliveries');
        Schema::dropIfExists('inbound_events');
        Schema::dropIfExists('workflow_listeners');
    }
};
