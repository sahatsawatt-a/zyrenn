<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A run can be only a try: done and followed as any other, then kept
     * nowhere -- not in a suite's history or health, no tickets, nothing in
     * the Drive -- and taken away a while after it ends.
     */
    public function up(): void
    {
        Schema::table('browser_runs', function (Blueprint $table) {
            $table->boolean('trial')->default(false)->after('state');
            $table->index(['trial', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::table('browser_runs', function (Blueprint $table) {
            $table->dropIndex(['trial', 'ended_at']);
            $table->dropColumn('trial');
        });
    }
};
