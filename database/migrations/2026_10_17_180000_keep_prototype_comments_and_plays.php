<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What people make of a prototype: a comment pinned to a spot on one of
     * its screens, and each time someone played it -- the screens they went
     * through, and where they tapped and nothing happened.
     */
    public function up(): void
    {
        Schema::create('prototype_comments', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The screen, by its frame's id, and where on it, 0 to 1 across and down
            $table->string('frame_id', 64);
            $table->float('x');
            $table->float('y');
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['board_id', 'created_at']);
        });

        Schema::create('prototype_plays', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            // The window it was played in, in pixels
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // [{screen, at}] and [{screen, x, y, at}], at in seconds from the start
            $table->json('path');
            $table->json('misses');
            $table->timestamps();

            $table->index(['board_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prototype_plays');
        Schema::dropIfExists('prototype_comments');
    }
};
