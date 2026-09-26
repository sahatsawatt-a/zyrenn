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
        Schema::create('board_folders', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('board_folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->index(['user_id', 'parent_id']);
        });

        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Deleting a folder deletes its boards explicitly (BoardFolder::deleteTree);
            // null here only guards against a folder removed some other way
            $table->foreignId('folder_id')->nullable()->constrained('board_folders')->nullOnDelete();
            $table->string('title')->default('');
            // Every item on the board, in paint order
            $table->jsonb('content')->nullable();
            // The labels on those items, so a board can be found by what is written on it
            $table->text('plain_text')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
            $table->index(['user_id', 'folder_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boards');
        Schema::dropIfExists('board_folders');
    }
};
