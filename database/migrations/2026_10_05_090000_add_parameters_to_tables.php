<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A table's parameters: named values its formulas share, such as an
     * exchange rate or a trip's first day.
     */
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->jsonb('parameters')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropColumn('parameters');
        });
    }
};
