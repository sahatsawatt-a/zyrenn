<?php

namespace Tests\Feature;

use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Tickets\CreateTicket;
use App\Mcp\Tools\Tickets\GetTicket;
use App\Mcp\Tools\Tickets\ListTickets;
use App\Mcp\Tools\Tickets\UpdateTicket;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * A project's tickets over MCP: listed, read, filed and moved along by the
 * project's name, by those allowed to.
 */
class McpTicketsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->project = Project::factory()->withMember($this->owner)->create(['name' => 'Lakeshore']);
    }

    public function test_tickets_are_filed_read_and_moved_along()
    {
        $editor = User::factory()->create(['email' => 'ada@example.com']);
        $this->project->members()->attach($editor, ['role' => Project::EDITOR]);

        UserServer::actingAs($this->owner)
            ->tool(CreateTicket::class, [
                'project' => 'lakeshore',
                'type' => 'bug',
                'title' => 'Resizing a shape shakes',
                'body' => 'The edge runs ahead of the pointer.',
                'unit' => 'Boards',
                'assignee' => 'ada@example.com',
                'pictures' => ['/drive/files/e3agxrzz0a'],
                // Not the filer's to say: a ticket starts open
                'status' => 'done',
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('number', 1)
                ->where('status', Ticket::OPEN)
                ->where('assignee', 'ada@example.com')
                ->where('pictures', ['/drive/files/e3agxrzz0a'])
                ->where('filed_by', $this->owner->name)
                ->where('url', route('projects.tickets.index', [$this->project, 'ticket' => 1]))
                ->etc()
            );

        UserServer::actingAs($editor)
            ->tool(UpdateTicket::class, ['project' => $this->project->ref_id, 'number' => 1, 'status' => 'done', 'assignee' => ''])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('status', Ticket::DONE)
                ->where('assignee', null)
                // What was not named is as it was
                ->where('title', 'Resizing a shape shakes')
                ->whereNot('closed_at', null)
                ->etc()
            );

        UserServer::actingAs($editor)
            ->tool(GetTicket::class, ['project' => 'Lakeshore', 'number' => 1])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('body', 'The edge runs ahead of the pointer.')
                ->where('unit', 'Boards')
                ->etc()
            );
    }

    public function test_the_list_is_what_is_left_to_do_unless_asked_otherwise()
    {
        Ticket::file($this->project, $this->owner, ['type' => 'bug', 'title' => 'Open bug', 'unit' => 'Boards']);
        Ticket::file($this->project, $this->owner, ['type' => 'feature', 'title' => 'Started feature', 'unit' => 'Notes', 'status' => Ticket::IN_PROGRESS]);
        Ticket::file($this->project, $this->owner, ['type' => 'bug', 'title' => 'Fixed bug', 'unit' => 'Boards', 'status' => Ticket::DONE]);
        Ticket::factory()->create(['title' => 'Another project\'s']);

        UserServer::actingAs($this->owner)
            ->tool(ListTickets::class, ['project' => 'Lakeshore'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('tickets', 2)
                ->where('tickets.0.title', 'Started feature')
                ->where('tickets.1.title', 'Open bug')
                // A line of a list: the body is get-ticket's to give
                ->missing('tickets.0.body')
                ->where('units', ['Boards', 'Notes'])
                ->etc()
            );

        UserServer::actingAs($this->owner)
            ->tool(ListTickets::class, ['project' => 'Lakeshore', 'status' => 'all', 'type' => 'bug', 'unit' => 'boards'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('tickets', 2)->etc());

        UserServer::actingAs($this->owner)
            ->tool(ListTickets::class, ['project' => 'Lakeshore', 'status' => 'all', 'search' => 'FIXED'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('tickets', 1)
                ->where('tickets.0.status', Ticket::DONE)
                ->etc()
            );
    }

    public function test_a_viewer_reads_tickets_and_nobody_outside_reaches_them()
    {
        $viewer = User::factory()->create();
        $this->project->members()->attach($viewer, ['role' => Project::VIEWER]);
        Ticket::file($this->project, $this->owner, ['type' => 'bug', 'title' => 'Open bug']);

        UserServer::actingAs($viewer)->tool(GetTicket::class, ['project' => 'Lakeshore', 'number' => 1])->assertOk();
        UserServer::actingAs($viewer)
            ->tool(UpdateTicket::class, ['project' => 'Lakeshore', 'number' => 1, 'status' => 'done'])
            ->assertHasErrors();
        UserServer::actingAs($viewer)
            ->tool(CreateTicket::class, ['project' => 'Lakeshore', 'type' => 'bug', 'title' => 'Mine'])
            ->assertHasErrors();

        UserServer::actingAs(User::factory()->create())
            ->tool(ListTickets::class, ['project' => $this->project->ref_id])
            ->assertHasErrors();

        $this->assertSame(Ticket::OPEN, $this->project->tickets()->sole()->status);
    }

    public function test_what_cannot_be_is_refused_with_a_reason()
    {
        // Tickets are a project's: there is none without one
        UserServer::actingAs($this->owner)->tool(ListTickets::class)->assertHasErrors();

        UserServer::actingAs($this->owner)
            ->tool(GetTicket::class, ['project' => 'Lakeshore', 'number' => 9])
            ->assertHasErrors();

        UserServer::actingAs($this->owner)
            ->tool(CreateTicket::class, ['project' => 'Lakeshore', 'type' => 'bug', 'title' => 'Mine', 'assignee' => 'stranger@example.com'])
            ->assertHasErrors();

        UserServer::actingAs($this->owner)
            ->tool(CreateTicket::class, ['project' => 'Lakeshore', 'type' => 'bug', 'title' => 'Mine', 'pictures' => ['file:///etc/passwd']])
            ->assertHasErrors();

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_the_admin_server_files_one_as_the_user_it_is_given()
    {
        config(['services.mcp.global_token' => 'secret']);

        GlobalServer::tool(CreateTicket::class, [
            'user_id' => $this->owner->id,
            'project' => 'Lakeshore',
            'type' => 'feature',
            'title' => 'Dark board',
        ])->assertOk();

        $this->assertSame($this->owner->id, $this->project->tickets()->sole()->created_by);
    }
}
