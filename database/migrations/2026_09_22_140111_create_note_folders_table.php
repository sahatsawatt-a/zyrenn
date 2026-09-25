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
        Schema::create('note_folders', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('note_folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
        });

        Schema::table('notes', function (Blueprint $table) {
            // Deleting a folder deletes its notes explicitly (NoteFolder::deleteTree);
            // null here only guards against a folder removed some other way
            $table->foreignId('folder_id')->nullable()->after('user_id')->constrained('note_folders')->nullOnDelete();
            $table->index(['user_id', 'folder_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'folder_id']);
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('note_folders');
    }
};
