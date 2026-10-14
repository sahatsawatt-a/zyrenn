<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an AI may answer from: a knowledge base is notes, tables and
     * Drive files of its owner's, read and cut into passages, each kept with
     * the numbers that say what it means -- so a question finds the passages
     * nearest it. A chat with a customer says whether AI answers in it.
     */
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            // What it reads: [{kind: notes|table|drive, ref: a folder's path or a table's ref_id}]
            $table->jsonb('sources')->nullable();
            // What turns a passage into numbers; without one, passages are found by their words
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('embed_model', 120)->nullable();
            // idle | reading | failed, and when it was last read
            $table->string('state', 16)->default('idle');
            $table->text('problem')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('project_id');
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            // Where it was read: a note, a table, a file -- and the address to open it at
            $table->string('source_kind', 16);
            $table->string('source_ref', 64);
            $table->string('title', 255);
            $table->string('url', 255)->nullable();
            $table->unsignedInteger('position');
            $table->text('text');
            // The same passage, read again, keeps its numbers
            $table->char('hash', 40);
            $table->jsonb('embedding')->nullable();
            $table->timestamps();

            $table->index(['knowledge_base_id', 'hash']);
        });

        Schema::table('chat_rooms', function (Blueprint $table) {
            // Whether AI answers the customer here, while nobody has taken the chat
            $table->boolean('ai_answers')->default(true)->after('tags');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropColumn('ai_answers');
        });

        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_bases');
    }
};
