<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared Yjs state a note or board was last left in by the
     * collaboration server, base64 encoded, so it reopens exactly as it was --
     * everyone's edits merged, nothing re-seeded. Null until first opened, and
     * again whenever it is changed from elsewhere.
     */
    public function up(): void
    {
        foreach (['notes', 'boards'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->longText('ydoc')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['notes', 'boards'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('ydoc');
            });
        }
    }
};
