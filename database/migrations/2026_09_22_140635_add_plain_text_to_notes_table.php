<?php

use App\Support\Markdown\TiptapMarkdown;
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
        Schema::table('notes', function (Blueprint $table) {
            // The note body as Markdown text, kept in step with `content`, for search
            $table->text('plain_text')->nullable()->after('content');
        });

        DB::table('notes')->whereNotNull('content')->orderBy('id')->each(function (object $note) {
            DB::table('notes')->where('id', $note->id)->update([
                'plain_text' => TiptapMarkdown::toMarkdown(json_decode($note->content, true)),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropColumn('plain_text');
        });
    }
};
