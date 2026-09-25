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
        Schema::create('drive_folders', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('drive_folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
        });

        Schema::create('drive_files', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('drive_folders')->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->string('ext', 32)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('kind', 16)->default('other');
            $table->timestamps();

            $table->index(['user_id', 'folder_id']);
            $table->index(['user_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drive_files');
        Schema::dropIfExists('drive_folders');
    }
};
