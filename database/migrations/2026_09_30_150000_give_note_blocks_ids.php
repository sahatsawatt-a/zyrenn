<?php

use App\Support\NoteBlocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every block of every note gets an id (NoteBlocks), so one can be read or
     * changed on its own. A note's shared state is dropped with the change: the
     * collaboration server opens it again from the content, ids and all.
     */
    public function up(): void
    {
        DB::table('notes')->whereNotNull('content')->orderBy('id')->each(function (object $note) {
            $content = json_decode($note->content, true);

            if (! is_array($content)) {
                return;
            }

            $given = NoteBlocks::withIds($content);

            if ($given !== $content) {
                DB::table('notes')->where('id', $note->id)->update([
                    'content' => json_encode($given),
                    'ydoc' => null,
                ]);
            }
        });
    }

    /**
     * The ids do no harm where nothing reads them.
     */
    public function down(): void {}
};
