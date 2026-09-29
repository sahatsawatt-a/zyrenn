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
        // 1. Independent Table Folder Management Shell
        Schema::create('table_folders', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique(); // Secure uniform tracking hashes
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('table_folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
        });

        // 2. Spreadsheet Core Tables Registry Tracker
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Deleting a folder deletes its children explicitly via controller cleanup tree hooks;
            // null here handles edge-case orphans safely
            $table->foreignId('folder_id')->nullable()->constrained('table_folders')->nullOnDelete();
            $table->string('title')->default('');
            $table->string('density')->default('normal');
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
            $table->index(['user_id', 'folder_id']);
        });

        // 3. Dynamic Structural Grid Column Fields Configurator Parameters Matrix
        Schema::create('table_columns', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('table_id')->constrained('tables')->cascadeOnDelete();
            $table->string('name'); // The real column name matching physical PostgreSQL keys
            $table->string('label'); // Friendly column display header label string text
            $table->string('type'); // varchar, integer, select, multi_select, etc.
            $table->boolean('is_primary')->default(false);
            $table->integer('width')->default(180);
            $table->boolean('hidden')->default(false);
            $table->jsonb('options_meta')->nullable(); // Binary JSON tracking badge tag color choice arrays natively
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['table_id', 'sort_order']);
            $table->unique(['table_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_columns');
        Schema::dropIfExists('tables');
        Schema::dropIfExists('table_folders');
    }
};
