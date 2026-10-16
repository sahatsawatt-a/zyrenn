<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The libraries a workflow's "Run code" step may use, added by the app's
     * admins: JavaScript ones kept bundled, ready to load; Python ones kept
     * by the Python runner itself, so only their name and version here.
     */
    public function up(): void
    {
        Schema::create('code_libraries', function (Blueprint $table) {
            $table->id();
            $table->string('language', 16);
            $table->string('name', 214);
            // As asked for: a version, a range or a tag; the one installed once it is
            $table->string('asked', 64)->nullable();
            $table->string('version', 64)->nullable();
            $table->string('status', 16)->default('installing');
            $table->string('error', 1000)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->longText('source')->nullable();
            $table->boolean('enabled')->default(true);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['language', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_libraries');
    }
};
