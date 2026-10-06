<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A list of saved places shows an icon -- a fork for "Food", a bed for
     * "Hotels" -- in its colour, named as the app's icon set names it.
     */
    public function up(): void
    {
        Schema::table('place_lists', function (Blueprint $table) {
            $table->string('icon', 40)->default('bookmark');
        });
    }

    public function down(): void
    {
        Schema::table('place_lists', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
