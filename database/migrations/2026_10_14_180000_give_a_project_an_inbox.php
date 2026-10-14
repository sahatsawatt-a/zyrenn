<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A project's chat with a customer -- a room linked to a chat outside --
     * is a kind of its own: everyone in the project may open it, and it
     * says where it stands (open, waiting, closed), who has taken it, and
     * what it is tagged. The project's groups already linked become one.
     */
    public function up(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            // open | waiting | closed, for a customer's chat alone
            $table->string('status', 16)->nullable()->after('kind');
            $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
            // A list of short words
            $table->jsonb('tags')->nullable()->after('assigned_to');

            $table->index(['project_id', 'kind', 'status']);
        });

        DB::table('chat_rooms')
            ->where('kind', 'group')
            ->whereNotNull('project_id')
            ->whereNotNull('link')
            ->update(['kind' => 'customer', 'status' => 'open']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('chat_rooms')->where('kind', 'customer')->update(['kind' => 'group']);

        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'kind', 'status']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['status', 'tags']);
        });
    }
};
