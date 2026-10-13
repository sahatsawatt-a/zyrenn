<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // How each person last sorted each folder they have looked in
        // (App\Support\ListSorts): theirs alone, in a project too
        Schema::create('list_sorts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Which list: a kind of folder and its ref_id, or a kind's top level
            $table->string('list', 96);
            $table->string('sort', 32);
            $table->timestamps();

            $table->unique(['user_id', 'list']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('list_sorts');
    }
};
