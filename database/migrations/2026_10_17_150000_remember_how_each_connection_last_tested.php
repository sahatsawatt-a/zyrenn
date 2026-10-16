<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a connection's last test went, for its row on the Connections
     * page: whether it worked, what the service said, and when. Nothing
     * here before the first test, which the page shows as "not tested yet".
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->timestamp('checked_at')->nullable();
            $table->boolean('check_ok')->nullable();
            $table->string('check_message', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['checked_at', 'check_ok', 'check_message']);
        });
    }
};
