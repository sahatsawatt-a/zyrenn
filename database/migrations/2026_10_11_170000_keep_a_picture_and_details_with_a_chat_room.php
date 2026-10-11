<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A room can have a face and things known about it: the picture of
     * whoever an outside chat is with, and details by name -- their id
     * there, their language, anything a workflow or its owner notes. What
     * a workflow keeps is its own to set; the rest is noted by hand.
     */
    public function up(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            // A picture's address, over https
            $table->text('picture')->nullable()->after('link');
            // {name: value}, names a formula can read: trigger.room.details.language
            $table->jsonb('details')->nullable()->after('picture');
            // The names among them a workflow keeps: its to set, so not changed by hand
            $table->jsonb('details_kept')->nullable()->after('details');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropColumn(['picture', 'details', 'details_kept']);
        });
    }
};
