<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workflows: drawn on a canvas, kept in folders like boards, owned by a
     * user or a project. What is being drawn is the draft; publishing freezes
     * it into a version that never changes, which is what runs.
     */
    public function up(): void
    {
        Schema::create('workflow_folders', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $this->owner($table);
            $table->foreignId('parent_id')->nullable()->constrained('workflow_folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
            $table->index(['project_id', 'parent_id']);
        });

        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $this->owner($table);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            // Deleting a folder deletes its workflows explicitly (Folder::deleteTree)
            $table->foreignId('folder_id')->nullable()->constrained('workflow_folders')->nullOnDelete();
            $table->string('title')->default('');
            // The draft: its steps, how they connect, and where they sit on the canvas
            $table->jsonb('definition')->nullable();
            // Counts every save, so one made from an out-of-date copy is turned away
            $table->unsignedInteger('revision')->default(0);
            // Whether its triggers start runs by themselves; pressing Run always does
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'folder_id']);
            $table->index(['user_id', 'updated_at']);
            $table->index(['project_id', 'folder_id']);
            $table->index(['project_id', 'updated_at']);
        });

        // Frozen drafts. Never changed once written: a run names the version it ran
        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            // "published", or "test" for a draft frozen to be tried from the editor
            $table->string('kind', 16);
            // The draft's revision it was frozen from
            $table->unsignedInteger('revision');
            $table->unsignedSmallInteger('schema_version');
            $table->jsonb('definition');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['workflow_id', 'number']);
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->foreignId('published_version_id')->nullable()->after('enabled')
                ->constrained('workflow_versions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_version_id');
        });

        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
        Schema::dropIfExists('workflow_folders');
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    }
};
