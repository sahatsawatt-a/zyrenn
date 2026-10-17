<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A prototype is a board kept as one: its screens are frames at its
     * device's size. What it is, and its own settings, beside the board.
     */
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->string('kind', 16)->default('board')->after('title')->index();
            $table->json('prototype')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['kind', 'prototype']);
        });
    }
};
