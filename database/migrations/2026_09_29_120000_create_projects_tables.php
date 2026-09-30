<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything that can be owned: notes, boards, tables, Drive files, and
     * the folders each is kept in.
     *
     * @var list<string>
     */
    private const OWNED = [
        'note_folders', 'notes',
        'board_folders', 'boards',
        'table_folders', 'tables',
        'drive_folders', 'drive_files',
    ];

    /**
     * Folders are listed by parent, things by folder and by when they were edited.
     *
     * @var array<string, list<list<string>>>
     */
    private const INDEXES = [
        'note_folders' => [['project_id', 'parent_id']],
        'notes' => [['project_id', 'folder_id'], ['project_id', 'updated_at']],
        'board_folders' => [['project_id', 'parent_id']],
        'boards' => [['project_id', 'folder_id'], ['project_id', 'updated_at']],
        'table_folders' => [['project_id', 'parent_id']],
        'tables' => [['project_id', 'folder_id'], ['project_id', 'updated_at']],
        'drive_folders' => [['project_id', 'parent_id']],
        'drive_files' => [['project_id', 'folder_id'], ['project_id', 'kind']],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });

        // A thing is a user's own (user_id) or a project's (project_id), never both.
        // Each goes with its owner; created_by only says who made it, and outlives them.
        foreach (self::OWNED as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->foreignId('user_id')->nullable()->change();
                $table->foreignId('project_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->after('project_id')->constrained('users')->nullOnDelete();

                foreach (self::INDEXES[$name] as $columns) {
                    $table->index($columns);
                }
            });

            DB::table($name)->update(['created_by' => DB::raw('user_id')]);

            // SQLite can't add a check to a table that already exists
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("alter table {$name} add constraint {$name}_one_owner check ((user_id is null) <> (project_id is null))");
            }
        }
    }

    /**
     * Reverse the migrations. Project content has no user to fall back to, so it goes.
     */
    public function down(): void
    {
        foreach (array_reverse(self::OWNED) as $name) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("alter table {$name} drop constraint {$name}_one_owner");
            }

            DB::table($name)->whereNull('user_id')->delete();

            Schema::table($name, function (Blueprint $table) use ($name) {
                foreach (self::INDEXES[$name] as $columns) {
                    $table->dropIndex($columns);
                }

                $table->dropConstrainedForeignId('created_by');
                $table->dropConstrainedForeignId('project_id');
                $table->foreignId('user_id')->nullable(false)->change();
            });
        }

        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
    }
};
