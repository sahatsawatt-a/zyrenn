<?php

use App\Support\Note\PageLinks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Which pages each note links to, kept in step with its content
        // (Note::booted), so a page can say what links to it
        Schema::create('note_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            // What is linked to: a note, a board, a table or a trip, by its ref_id
            $table->string('kind', 16);
            $table->string('ref', 32);

            $table->unique(['note_id', 'kind', 'ref']);
            $table->index(['kind', 'ref']);
        });

        // The links notes already hold, written as ordinary links to a page's address
        DB::table('notes')->whereNotNull('content')->orderBy('id')->each(function (object $note) {
            $links = PageLinks::found(json_decode($note->content, true), $note->ref_id);

            if ($links !== []) {
                DB::table('note_links')->insert(array_map(fn (array $link) => ['note_id' => $note->id, ...$link], $links));
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('note_links');
    }
};
