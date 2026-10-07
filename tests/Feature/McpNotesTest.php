<?php

namespace Tests\Feature;

use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Notes\CreateNote;
use App\Mcp\Tools\Notes\DeleteNote;
use App\Mcp\Tools\Notes\GetNote;
use App\Mcp\Tools\Notes\ListFolders;
use App\Mcp\Tools\Notes\ListNotes;
use App\Mcp\Tools\Notes\UpdateNote;
use App\Mcp\Tools\Users\ListUsers;
use App\Models\Note\Note;
use App\Models\User;
use App\Support\Markdown\TiptapMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class McpNotesTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ User server

    public function test_user_server_does_not_expose_user_selection()
    {
        UserServer::tools()->assertNotRegistered(ListUsers::class);

        $user = User::factory()->create();
        $other = Note::factory()->create();

        // A user_id argument is ignored: the token owner is always the target
        UserServer::actingAs($user)
            ->tool(ListNotes::class, ['user_id' => $other->user_id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('notes', 0));
    }

    public function test_user_can_list_only_their_own_notes()
    {
        $user = User::factory()->create();
        Note::factory()->for($user)->create(['title' => 'Groceries']);
        Note::factory()->for($user)->create(['title' => 'Work plan']);
        Note::factory()->create(['title' => 'Someone else']);

        UserServer::actingAs($user)
            ->tool(ListNotes::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('notes', 2));

        UserServer::actingAs($user)
            ->tool(ListNotes::class, ['search' => 'gRoC'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('notes', 1)
                ->where('notes.0.title', 'Groceries')
                ->etc());
    }

    public function test_user_cannot_read_update_or_delete_another_users_note()
    {
        $user = User::factory()->create();
        $note = Note::factory()->create(['title' => 'Private']);

        UserServer::actingAs($user)->tool(GetNote::class, ['ref_id' => $note->ref_id])
            ->assertHasErrors(["Note {$note->ref_id} was not found."]);
        UserServer::actingAs($user)->tool(UpdateNote::class, ['ref_id' => $note->ref_id, 'title' => 'Hacked'])
            ->assertHasErrors();
        UserServer::actingAs($user)->tool(DeleteNote::class, ['ref_id' => $note->ref_id])
            ->assertHasErrors();

        $this->assertSame('Private', $note->refresh()->title);
    }

    public function test_user_can_create_read_update_and_delete_notes_as_markdown()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateNote::class, ['title' => 'Plan', 'markdown' => "# Goals\n\n- [ ] Ship MCP"])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('title', 'Plan')
                ->where('user_id', $user->id)
                // Which note it made, not the note again: get-note reads it
                ->missing('markdown')
                ->etc());

        $note = $user->notes()->sole();
        $this->assertSame('taskList', $note->content['content'][1]['type']);
        $this->assertSame("# Goals\n\n- [ ] Ship MCP\n", TiptapMarkdown::toMarkdown($note->content));

        UserServer::actingAs($user)
            ->tool(UpdateNote::class, ['ref_id' => $note->ref_id, 'markdown' => '- [x] Ship MCP', 'is_wide' => true])
            ->assertOk();

        $note->refresh();
        $this->assertSame('Plan', $note->title);
        $this->assertTrue($note->is_wide);
        $this->assertSame("- [x] Ship MCP\n", TiptapMarkdown::toMarkdown($note->content));

        UserServer::actingAs($user)
            ->tool(GetNote::class, ['ref_id' => $note->ref_id])
            ->assertOk()
            ->assertSee('Ship MCP');

        UserServer::actingAs($user)->tool(DeleteNote::class, ['ref_id' => $note->ref_id])->assertOk();
        $this->assertModelMissing($note);
    }

    public function test_create_note_validates_input()
    {
        UserServer::actingAs(User::factory()->create())
            ->tool(CreateNote::class, ['markdown' => 'no title'])
            ->assertHasErrors(['The title field is required.']);
    }

    // ---------------------------------------------------------- Global server

    public function test_global_server_requires_choosing_a_user()
    {
        GlobalServer::tools()->assertRegistered(ListUsers::class);

        GlobalServer::tool(ListNotes::class)->assertHasErrors(['The user id field is required.']);
        GlobalServer::tool(ListNotes::class, ['user_id' => 999])->assertHasErrors(['No user exists with that user_id.']);
    }

    public function test_global_server_can_list_users_and_act_on_any_users_notes()
    {
        $ada = User::factory()->create(['name' => 'Ada Lovelace']);
        $lin = User::factory()->create(['name' => 'Lin']);
        $adaNote = Note::factory()->for($ada)->create();

        GlobalServer::tool(ListUsers::class, ['search' => 'ada'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('users', 1)
                ->where('users.0.id', $ada->id)
                ->where('users.0.notes_count', 1)
                ->etc());

        GlobalServer::tool(GetNote::class, ['user_id' => $ada->id, 'ref_id' => $adaNote->ref_id])->assertOk();

        GlobalServer::tool(CreateNote::class, ['user_id' => $lin->id, 'title' => 'Hello Lin'])->assertOk();
        $this->assertSame('Hello Lin', $lin->notes()->sole()->title);
    }

    public function test_global_server_scopes_notes_to_the_chosen_user()
    {
        $ada = User::factory()->create();
        $lin = User::factory()->create();
        $adaNote = Note::factory()->for($ada)->create();

        // Ada's note is not reachable when Lin is the chosen user
        GlobalServer::tool(DeleteNote::class, ['user_id' => $lin->id, 'ref_id' => $adaNote->ref_id])->assertHasErrors();
        $this->assertModelExists($adaNote);
    }

    public function test_notes_can_be_created_in_and_moved_between_folders_by_path()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateNote::class, ['title' => 'Step 4', 'folder' => 'KT Plan/Lakeshore'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('folder', 'KT Plan/Lakeshore')->etc());

        // Missing folders were made, nested
        $lakeshore = $user->noteFolders()->where('name', 'Lakeshore')->sole();
        $this->assertSame('KT Plan', $lakeshore->parent->name);
        $note = $user->notes()->sole();
        $this->assertSame($lakeshore->id, $note->folder_id);

        // An existing folder is reused, whatever the capitals or spacing
        UserServer::actingAs($user)->tool(CreateNote::class, ['title' => 'Step 5', 'folder' => ' kt plan / LAKESHORE ']);
        $this->assertSame(2, $user->noteFolders()->count());
        $this->assertSame(2, $lakeshore->notes()->count());

        UserServer::actingAs($user)
            ->tool(UpdateNote::class, ['ref_id' => $note->ref_id, 'folder' => ''])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('folder', null)->etc());
        $this->assertNull($note->refresh()->folder_id);
    }

    public function test_notes_can_be_listed_by_folder_and_searched_by_text()
    {
        $user = User::factory()->create();
        UserServer::actingAs($user)->tool(CreateNote::class, ['title' => 'Step 4', 'folder' => 'KT Plan/Lakeshore', 'markdown' => 'The watchdog runs every 6 hours.']);
        UserServer::actingAs($user)->tool(CreateNote::class, ['title' => 'Loose note']);

        UserServer::actingAs($user)
            ->tool(ListNotes::class, ['folder' => 'KT Plan/Lakeshore'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('notes', 1)
                ->where('notes.0.title', 'Step 4')
                ->where('notes.0.folder', 'KT Plan/Lakeshore')
                ->etc());

        UserServer::actingAs($user)
            ->tool(ListNotes::class, ['folder' => ''])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('notes', 1)->where('notes.0.title', 'Loose note')->etc());

        UserServer::actingAs($user)
            ->tool(ListNotes::class, ['search' => 'WATCHDOG'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('notes', 1)->where('notes.0.title', 'Step 4')->etc());

        // Listing never creates folders
        UserServer::actingAs($user)->tool(ListNotes::class, ['folder' => 'Nope'])->assertHasErrors();
        $this->assertSame(0, $user->noteFolders()->where('name', 'Nope')->count());
    }

    public function test_folders_are_listed_as_paths_with_counts()
    {
        $user = User::factory()->create();
        UserServer::actingAs($user)->tool(CreateNote::class, ['title' => 'A', 'folder' => 'KT Plan/Lakeshore']);
        UserServer::actingAs($user)->tool(CreateNote::class, ['title' => 'B']);
        UserServer::actingAs(User::factory()->create())->tool(CreateNote::class, ['title' => 'C', 'folder' => 'Private']);

        UserServer::actingAs($user)
            ->tool(ListFolders::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('folders', [
                    ['path' => 'KT Plan', 'notes_count' => 0],
                    ['path' => 'KT Plan/Lakeshore', 'notes_count' => 1],
                ])
                ->where('top_level_notes_count', 1));
    }

    // ------------------------------------------------------------------ HTTP

    /**
     * @return array<string, mixed>
     */
    private function toolsListRequest(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];
    }

    public function test_user_endpoint_requires_a_token_with_the_mcp_ability()
    {
        $user = User::factory()->create();

        $this->postJson('/mcp/user', $this->toolsListRequest())->assertUnauthorized();

        $wrongAbility = $user->createToken('other', ['something-else'])->plainTextToken;
        $this->withToken($wrongAbility)->postJson('/mcp/user', $this->toolsListRequest())->assertForbidden();

        // The test app keeps the guard (and its resolved user) between requests
        $this->app['auth']->forgetGuards();

        $token = $user->createToken('claude', ['mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/user', $this->toolsListRequest())
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'list-projects');
    }

    public function test_global_endpoint_is_disabled_without_a_configured_token()
    {
        config(['services.mcp.global_token' => null]);

        $this->withToken('anything')->postJson('/mcp/global', $this->toolsListRequest())->assertNotFound();
    }

    public function test_global_endpoint_requires_the_global_token()
    {
        config(['services.mcp.global_token' => 'secret-global-token']);

        $this->postJson('/mcp/global', $this->toolsListRequest())->assertUnauthorized();
        $this->withToken('wrong')->postJson('/mcp/global', $this->toolsListRequest())->assertUnauthorized();

        // A user's personal token must not unlock the global server
        $userToken = User::factory()->create()->createToken('claude', ['mcp'])->plainTextToken;
        $this->withToken($userToken)->postJson('/mcp/global', $this->toolsListRequest())->assertUnauthorized();

        $this->withToken('secret-global-token')->postJson('/mcp/global', $this->toolsListRequest())
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'list-users');
    }
}
