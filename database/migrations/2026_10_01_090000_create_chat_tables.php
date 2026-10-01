<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Where a model is reached: a host and, for a hosted one, the user's own key
        Schema::create('ai_connections', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 20);
            $table->string('base_url');
            $table->text('api_key')->nullable();
            $table->string('default_model')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        // A conversation. The agent that answers in it is optional, so a room with none is a plain log
        Schema::create('chat_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('');
            $table->foreignId('ai_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model')->nullable();
            $table->text('system_prompt')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        // What was said, by whom: a person (user_id) or the agent (none)
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_room_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('content');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['chat_room_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_rooms');
        Schema::dropIfExists('ai_connections');
    }
};
