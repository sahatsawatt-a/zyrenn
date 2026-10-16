<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shapes of one's own, made on a board -- a vector drawing, a group of
     * shapes and words -- kept to put on any board: a person's, or a
     * project's for all its members. Each is a copy of the items it was made
     * of, set at the top left of its own box.
     */
    public function up(): void
    {
        Schema::create('board_shapes', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->json('items');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            // A small picture of it, for the library: a PNG data URL
            $table->text('preview')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'name']);
            $table->index(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_shapes');
    }
};
