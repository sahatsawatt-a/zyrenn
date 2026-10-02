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
        // A room is someone's own (with an agent, maybe), between two people, or a group in a project
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->string('kind', 20)->default('personal')->after('ref_id');
            $table->foreignId('user_id')->nullable()->change();
            // A group belongs to a project, and goes with it
            $table->foreignId('project_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->after('project_id')->constrained('users')->nullOnDelete();
            // The two people of a direct room, lower id first, so a pair only ever has one
            $table->string('direct_key', 40)->nullable()->unique()->after('created_by');

            $table->index(['project_id', 'updated_at']);
        });

        // Who is in a direct room or a group
        Schema::create('chat_room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['chat_room_id', 'user_id']);
            $table->index('user_id');
        });

        // How far each person has read in each room
        Schema::create('chat_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();

            $table->unique(['chat_room_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_reads');
        Schema::dropIfExists('chat_room_members');

        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'updated_at']);
            $table->dropUnique(['direct_key']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['kind', 'direct_key']);
        });
    }
};
