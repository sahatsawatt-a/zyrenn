<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maps: trips (filed in folders like boards) and saved places (kept in
     * lists). Each is a user's own or a project's, never both, and goes with
     * its owner -- the same as every other kind of content.
     *
     * @var list<string>
     */
    private const OWNED = ['trip_folders', 'trips', 'place_lists', 'places'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trip_folders', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $this->owner($table);
            $table->foreignId('parent_id')->nullable()->constrained('trip_folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['user_id', 'parent_id']);
            $table->index(['project_id', 'parent_id']);
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $this->owner($table);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            // Deleting a folder deletes its trips explicitly (TripFolder::deleteTree);
            // null here only guards against a folder removed some other way
            $table->foreignId('folder_id')->nullable()->constrained('trip_folders')->nullOnDelete();
            $table->string('title')->default('');
            // The whole plan: dates, flights, hotels, and the days with their stops
            $table->jsonb('content')->nullable();
            // Every place named in it, so a trip can be found by where it goes
            $table->text('plain_text')->nullable();
            // Counts every save, so a save made from an out-of-date copy is
            // turned away instead of quietly undoing someone's newer one
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'folder_id']);
            $table->index(['user_id', 'updated_at']);
            $table->index(['project_id', 'folder_id']);
            $table->index(['project_id', 'updated_at']);
        });

        Schema::create('place_lists', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $this->owner($table);
            $table->string('name');
            $table->string('color', 16);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'sort_order']);
            $table->index(['project_id', 'sort_order']);
        });

        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            // The list's owner, kept on the place too, so a place is found and
            // guarded the same way as everything else
            $this->owner($table);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('list_id')->constrained('place_lists')->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->default('');
            // What kind of place, as OpenStreetMap says: "amenity/restaurant"
            $table->string('kind', 120)->default('');
            $table->text('note')->nullable();
            // WGS84, as OpenStreetMap, Photon and Google all give it
            $table->double('lat');
            $table->double('lng');
            // Google's opening hours, rating and the like, when looked up
            $table->jsonb('details')->nullable();
            $table->timestamps();

            $table->index(['list_id', 'created_at']);
            $table->index(['user_id', 'name']);
            $table->index(['project_id', 'name']);
        });

        // SQLite can't add a check to a table that already exists
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::OWNED as $name) {
                DB::statement("alter table {$name} add constraint {$name}_one_owner check ((user_id is null) <> (project_id is null))");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('places');
        Schema::dropIfExists('place_lists');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('trip_folders');
    }

    /**
     * A user's own (user_id) or a project's (project_id), and who made it.
     */
    private function owner(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    }
};
