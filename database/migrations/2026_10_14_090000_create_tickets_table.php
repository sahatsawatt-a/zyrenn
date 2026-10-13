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
        // What a project has been asked to fix or to add, each from the day
        // it is asked to the day it is done
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Counted up within the project: what people call it, "#12"
            $table->unsignedInteger('number');
            $table->string('type', 16);
            $table->string('status', 16)->default('open');
            $table->string('priority', 16)->default('normal');
            $table->string('title');
            // The part of the product it is about, in the project's own words
            $table->string('unit', 64)->default('');
            $table->text('body')->nullable();
            // Pictures of it, each where it lives -- the Drive, or a link -- and its name
            $table->json('pictures')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // Where it is talked over: one of the project's groups, once somebody starts it
            $table->foreignId('chat_room_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
