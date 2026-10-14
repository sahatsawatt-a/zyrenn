<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A ticket made from a customer's chat says where it was asked: the
     * chat, and the message in it, so whoever does it can read what the
     * customer said, and the chat lists what came of it.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('asked_in_room_id')->nullable()->after('chat_room_id')->constrained('chat_rooms')->nullOnDelete();
            $table->foreignId('asked_in_message_id')->nullable()->after('asked_in_room_id')->constrained('chat_messages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asked_in_message_id');
            $table->dropConstrainedForeignId('asked_in_room_id');
        });
    }
};
