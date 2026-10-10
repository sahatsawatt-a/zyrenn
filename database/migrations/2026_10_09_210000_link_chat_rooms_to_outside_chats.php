<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A room made for an outside conversation can say which: the service,
     * the connection (bot, channel) it came through, and the chat there --
     * so what is written in the room can go back the same way.
     */
    public function up(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            // {service, connection: ref_id, chat: the outside chat's id}
            $table->jsonb('link')->nullable()->after('external_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropColumn('link');
        });
    }
};
