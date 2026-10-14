<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An agent: a model, through one of the owner's AI connections, with
     * the instructions it answers by and the knowledge it may answer from.
     * Every change of those is kept as a version, so a model or a prompt
     * that did better can be gone back to.
     */
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            // As it now stands: the latest version's
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 160)->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('knowledge_base_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->index('user_id');
            $table->index('project_id');
        });

        Schema::create('agent_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 160)->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('knowledge_base_id')->nullable()->constrained()->nullOnDelete();
            // What changed, in a few words of whoever changed it
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['agent_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_versions');
        Schema::dropIfExists('agents');
    }
};
