<?php

namespace Tests\Feature;

use App\Events\Deleted;
use App\Models\Board\Board;
use App\Models\Note\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The app's side of live co-editing: who may open a shared note or board,
 * loading and keeping it for the collaboration server, and changes made
 * elsewhere reaching whoever has it open.
 */
class CollabTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-collab-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.collab.url' => 'http://collab.test', 'services.collab.secret' => self::SECRET]);
        Http::fake(['collab.test/*' => Http::response(['live' => true])]);
        $this->withoutVite();
    }

    private function projectNote(Project $project, array $attributes = ['title' => 'Plan']): Note
    {
        $note = (new Note($attributes))->ownedBy($project, User::factory()->create());
        $note->save();

        return $note;
    }

    private function internal(): array
    {
        return ['X-Collab-Secret' => self::SECRET, 'Accept' => 'application/json'];
    }

    public function test_members_may_open_a_shared_note_and_viewers_only_to_read()
    {
        $project = Project::factory()->create();
        $note = $this->projectNote($project);
        $editor = User::factory()->create(['name' => 'Ada']);
        $viewer = User::factory()->create();
        $project->members()->attach($editor, ['role' => Project::EDITOR]);
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);
        $document = "notes.{$note->ref_id}";

        $this->actingAs($editor)
            ->getJson(route('collab.auth', ['document' => $document]))
            ->assertOk()
            ->assertExactJson(['user' => ['id' => $editor->id, 'name' => 'Ada'], 'read_only' => false]);

        $this->actingAs($viewer)
            ->getJson(route('collab.auth', ['document' => $document]))
            ->assertJsonPath('read_only', true);

        $this->actingAs(User::factory()->create())
            ->getJson(route('collab.auth', ['document' => $document]))
            ->assertForbidden();

        $this->actingAs($editor)
            ->getJson(route('collab.auth', ['document' => 'notes.nothere']))
            ->assertNotFound();

        $this->actingAs($editor)
            ->getJson(route('collab.auth', ['document' => 'tables.anything']))
            ->assertNotFound();
    }

    public function test_nobody_signed_in_opens_anything()
    {
        $note = Note::factory()->create();

        $this->getJson(route('collab.auth', ['document' => "notes.{$note->ref_id}"]))->assertUnauthorized();
    }

    public function test_only_the_collaboration_server_loads_and_keeps_documents()
    {
        $note = Note::factory()->create(['title' => 'Plan']);
        $document = "notes.{$note->ref_id}";

        $this->getJson(route('collab.show', $document), ['X-Collab-Secret' => 'wrong'])->assertForbidden();
        $this->getJson(route('collab.show', $document))->assertForbidden();

        config(['services.collab.secret' => null]);
        $this->getJson(route('collab.show', $document), $this->internal())->assertNotFound();
    }

    public function test_a_document_opens_from_what_the_app_keeps()
    {
        $note = Note::factory()->create(['title' => 'Plan', 'is_wide' => true]);

        $this->getJson(route('collab.show', "notes.{$note->ref_id}"), $this->internal())
            ->assertOk()
            ->assertJson([
                'state' => null,
                'title' => 'Plan',
                'is_wide' => true,
                'content' => $note->content,
            ]);
    }

    public function test_what_a_document_becomes_is_kept_for_search_and_mcp()
    {
        $editor = User::factory()->create();
        $note = Note::factory()->create(['title' => 'Old']);
        $content = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Energy is ']]],
        ]];

        $this->putJson(route('collab.update', "notes.{$note->ref_id}"), [
            'state' => 'c3RhdGU=',
            'content' => $content,
            'title' => 'New',
            'is_wide' => true,
            'updated_by' => $editor->id,
        ], $this->internal())->assertNoContent();

        $note->refresh();
        $this->assertSame('New', $note->title);
        // Whole: the space at the edge of a text node is content, not padding to trim
        $this->assertSame($content, $note->content);
        $this->assertStringContainsString('Energy is', $note->plain_text);
        $this->assertTrue($note->is_wide);
        $this->assertSame('c3RhdGU=', $note->ydoc);
        $this->assertSame($editor->id, $note->updated_by);

        // Keeping it is not a change "from elsewhere": nothing is sent back
        Http::assertNothingSent();
    }

    public function test_a_board_is_kept_as_its_items()
    {
        $board = Board::factory()->create();
        $items = [['id' => 'a', 'kind' => 'sticky', 'text' => 'Hello', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100]];

        $this->putJson(route('collab.update', "boards.{$board->ref_id}"), [
            'state' => 'c3RhdGU=',
            'items' => $items,
            'title' => 'Map',
            'updated_by' => null,
        ], $this->internal())->assertNoContent();

        $board->refresh();
        $this->assertSame(['items' => $items], $board->content);
        $this->assertSame('Hello', $board->plain_text);
        $this->assertSame('Map', $board->title);
    }

    public function test_a_change_made_elsewhere_reaches_whoever_has_it_open()
    {
        $note = Note::factory()->create(['title' => 'Plan']);
        $note->forceFill(['ydoc' => 'c3RhdGU='])->save();

        // e.g. update-note over MCP
        $note->update(['title' => 'Renamed']);

        $this->assertNull($note->fresh()->ydoc);
        Http::assertSent(fn (SentRequest $request) => $request->url() === 'http://collab.test/replace'
            && $request->header('X-Collab-Secret') === [self::SECRET]
            && $request['document'] === "notes.{$note->ref_id}"
            && $request['title'] === 'Renamed'
            && ! isset($request['content']));
    }

    public function test_moving_a_note_to_a_folder_changes_nothing_anyone_is_editing()
    {
        $note = Note::factory()->create();
        $note->forceFill(['ydoc' => 'c3RhdGU='])->save();

        $note->folder_id = null;
        $note->touch();

        $this->assertSame('c3RhdGU=', $note->fresh()->ydoc);
        Http::assertNothingSent();
    }

    public function test_deleting_a_note_or_board_closes_it_for_whoever_has_it_open()
    {
        Event::fake([Deleted::class]);

        $note = Note::factory()->create();
        $board = Board::factory()->create();
        $note->delete();
        $board->delete();

        Event::assertDispatched(Deleted::class, fn (Deleted $event) => $event->broadcastOn()->name === "presence-notes.{$note->ref_id}");
        Event::assertDispatched(Deleted::class, fn (Deleted $event) => $event->broadcastOn()->name === "presence-boards.{$board->ref_id}");
    }

    public function test_pages_know_whether_editing_is_live()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('notes.index'))->assertInertia(fn (Assert $page) => $page->where('collab', true));

        config(['services.collab.url' => null]);
        $this->actingAs($user)->get(route('notes.index'))->assertInertia(fn (Assert $page) => $page->where('collab', false));
    }
}
