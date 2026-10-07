<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Notes\EditNote;
use App\Mcp\Tools\Notes\GetNote;
use App\Models\Note\Note;
use App\Models\Project;
use App\Models\User;
use App\Support\Markdown\TiptapMarkdown;
use App\Support\Note\NoteBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Reading and changing part of a note over MCP, by its blocks' ids, so a
 * big note costs what the part does rather than the whole of it both ways.
 */
class McpNoteBlocksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function note(string $markdown): Note
    {
        return Note::factory()->for($this->user)->create([
            'title' => 'Plan',
            'content' => TiptapMarkdown::toDoc($markdown),
        ]);
    }

    /**
     * @return list<string>
     */
    private function ids(Note $note): array
    {
        return array_column(NoteBlocks::outline($note->fresh()->content), 'id');
    }

    public function test_a_short_note_comes_whole_with_its_outline()
    {
        $note = $this->note("# Goals\n\nShip it.");

        UserServer::actingAs($this->user)
            ->tool(GetNote::class, ['ref_id' => $note->ref_id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('outline', 2)
                ->where('outline.0.type', 'heading 1')
                ->where('outline.0.id', $this->ids($note)[0])
                ->where('markdown', "# Goals\n\nShip it.\n")
                ->etc());
    }

    public function test_a_long_note_comes_as_its_outline_only()
    {
        $note = $this->note(implode("\n\n", array_map(fn ($n) => "Paragraph {$n} ".str_repeat('words ', 60), range(1, 30))));

        UserServer::actingAs($this->user)
            ->tool(GetNote::class, ['ref_id' => $note->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('outline', 30)
                ->missing('markdown')
                ->has('more')
                ->etc());
    }

    public function test_a_section_or_chosen_blocks_are_read_alone()
    {
        $note = $this->note("# Plan\n\n## Budget\n\nMoney.\n\n## Team\n\n- Ada\n- Lin");
        $ids = $this->ids($note);

        UserServer::actingAs($this->user)
            ->tool(GetNote::class, ['ref_id' => $note->ref_id, 'section' => 'budget'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('blocks', 2)
                ->where('blocks.0.markdown', '## Budget')
                ->where('blocks.1.markdown', 'Money.')
                ->missing('outline')
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(GetNote::class, ['ref_id' => $note->ref_id, 'blocks' => [$ids[4]]])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('blocks.0.type', 'bulletList')
                ->where('blocks.0.items.1.markdown', '- Lin')
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(GetNote::class, ['ref_id' => $note->ref_id, 'section' => 'Nothing'])
            ->assertHasErrors(['There is no heading "Nothing" in this note. get-note without a section lists its blocks.']);
    }

    public function test_blocks_are_changed_by_id_and_only_they_change()
    {
        $note = $this->note("# Plan\n\nOld.\n\n- Ada");
        [$heading, $old, $list] = $this->ids($note);
        $ada = $note->content['content'][2]['content'][0]['attrs']['id'];

        UserServer::actingAs($this->user)
            ->tool(EditNote::class, ['ref_id' => $note->ref_id, 'changes' => [
                ['op' => 'replace', 'block' => $old, 'markdown' => 'New.'],
                ['op' => 'insert_after', 'block' => $ada, 'markdown' => '- Lin'],
                ['op' => 'append', 'markdown' => 'The end.'],
            ]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('ref_id', $note->ref_id)
                ->has('changed', 3)
                ->where('changed.0', $old)
                ->missing('markdown')
                ->etc());

        $note->refresh();
        $this->assertSame("# Plan\n\nNew.\n\n- Ada\n- Lin\n\nThe end.\n", TiptapMarkdown::toMarkdown($note->content));
        $this->assertSame([$heading, $old, $list], array_slice($this->ids($note), 0, 3));
    }

    public function test_a_block_left_out_of_the_pdf_says_so_and_stays_out_when_rewritten()
    {
        $note = $this->note("# Plan\n\nDraft only.\n\nShown.");
        [, $draft] = $this->ids($note);
        $content = $note->content;
        $content['content'][1]['attrs'][NoteBlocks::PDF_HIDDEN] = true;
        $note->update(['content' => $content]);

        $outline = NoteBlocks::outline($note->fresh()->content);
        $this->assertTrue($outline[1]['hidden_in_pdf']);
        $this->assertArrayNotHasKey('hidden_in_pdf', $outline[2]);
        $this->assertTrue(NoteBlocks::present(NoteBlocks::find($note->fresh()->content, [$draft]))[0]['hidden_in_pdf']);

        UserServer::actingAs($this->user)
            ->tool(EditNote::class, ['ref_id' => $note->ref_id, 'changes' => [
                ['op' => 'replace', 'block' => $draft, 'markdown' => "Still a draft.\n\nTwo lines of it."],
            ]])
            ->assertOk();

        $blocks = $note->fresh()->content['content'];
        $this->assertSame([false, true, true, false], array_map(
            fn (array $block) => ($block['attrs'][NoteBlocks::PDF_HIDDEN] ?? false) === true,
            $blocks,
        ));
        $this->assertSame($draft, $blocks[1]['attrs']['id']);
    }

    public function test_a_page_break_is_a_block_of_its_own_in_the_outline()
    {
        $note = $this->note("Cover\n\n<!-- pagebreak -->\n\nChapter one");

        $outline = NoteBlocks::outline($note->fresh()->content);

        $this->assertSame('page break', $outline[1]['type']);
        $this->assertNotEmpty($outline[1]['id']);
    }

    public function test_a_change_that_cant_be_made_leaves_the_note_as_it_was()
    {
        $note = $this->note('Keep.');
        $before = $note->content;

        UserServer::actingAs($this->user)
            ->tool(EditNote::class, ['ref_id' => $note->ref_id, 'changes' => [
                ['op' => 'append', 'markdown' => 'More.'],
                ['op' => 'delete', 'block' => 'nothere'],
            ]])
            ->assertHasErrors(['Change 2 (delete): there is no block "nothere". get-note lists the note\'s blocks and their ids.']);

        $this->assertSame($before, $note->fresh()->content);
    }

    public function test_a_viewer_reads_blocks_but_cant_change_them()
    {
        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $project->members()->attach($this->user, ['role' => Project::VIEWER]);
        $note = (new Note(['title' => 'Shared', 'content' => TiptapMarkdown::toDoc('Words.')]))->ownedBy($project, User::factory()->create());
        $note->save();

        UserServer::actingAs($this->user)
            ->tool(GetNote::class, ['project' => 'Lakeshore', 'ref_id' => $note->ref_id])
            ->assertOk();

        UserServer::actingAs($this->user)
            ->tool(EditNote::class, ['project' => 'Lakeshore', 'ref_id' => $note->ref_id, 'changes' => [['op' => 'append', 'markdown' => 'No.']]])
            ->assertHasErrors();
    }

    public function test_a_note_open_somewhere_is_changed_in_the_live_copy_block_by_block()
    {
        config(['services.collab.url' => 'http://collab.test', 'services.collab.secret' => 'secret']);
        Http::fake([
            'collab.test/flush' => Http::response(['live' => true]),
            'collab.test/apply' => Http::response(['live' => true, 'missing' => []]),
        ]);
        $note = $this->note("A.\n\nB.");
        [$a] = $this->ids($note);
        $before = $note->fresh()->content;

        UserServer::actingAs($this->user)
            ->tool(EditNote::class, ['ref_id' => $note->ref_id, 'changes' => [['op' => 'replace', 'block' => $a, 'markdown' => 'A, changed.']]])
            ->assertOk();

        Http::assertSent(fn (SentRequest $request) => $request->url() === 'http://collab.test/apply'
            && $request['document'] === "notes.{$note->ref_id}"
            && $request['by'] === $this->user->id
            && $request['edits'][0]['do'] === 'replace'
            && $request['edits'][0]['id'] === $a
            && $request['edits'][0]['nodes'][0]['attrs']['id'] === $a);

        // The live copy is the collaboration server's to hand back; the app doesn't write over it
        $this->assertSame($before, $note->fresh()->content);
        Http::assertNotSent(fn (SentRequest $request) => $request->url() === 'http://collab.test/replace');
    }
}
