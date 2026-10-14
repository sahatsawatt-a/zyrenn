<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tools an agent may use, kept with it and with each version of it:
     * Zyrenn's own and those of MCP servers its owner connects, each named
     * by where it is and what it is called.
     */
    public function up(): void
    {
        foreach (['agents', 'agent_versions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // [{server: "zyrenn" | an MCP connection's ref_id, name: the tool's}]
                $table->jsonb('tools')->nullable()->after('knowledge_base_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['agents', 'agent_versions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('tools');
            });
        }
    }
};
