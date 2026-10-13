<?php

namespace Tests\Feature;

use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A project's tickets: its owners and editors file them and move them along,
 * everyone in it reads them, and nobody outside it sees them.
 */
class TicketTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->project = Project::factory()->withMember($this->owner)->create();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->project->members()->attach($user, ['role' => $role]);

        return $user;
    }

    public function test_a_ticket_is_filed_and_numbered_within_its_project()
    {
        $other = Project::factory()->withMember($this->owner)->create();
        Ticket::file($other, $this->owner, ['type' => Ticket::BUG, 'title' => 'Elsewhere']);

        foreach (['Resize shakes', 'Pen draws badly'] as $title) {
            $this->actingAs($this->owner)
                ->post(route('projects.tickets.store', $this->project), [
                    'type' => Ticket::BUG,
                    'title' => $title,
                    'unit' => ' Board ',
                    'body' => 'What goes wrong',
                ])
                ->assertSessionHasNoErrors();
        }

        $tickets = $this->project->tickets()->orderBy('number')->get();

        // Each project counts from one, whatever the others have
        $this->assertSame([1, 2], $tickets->pluck('number')->all());
        $this->assertSame('Board', $tickets[0]->unit);
        $this->assertSame(Ticket::OPEN, $tickets[0]->status);
        $this->assertSame($this->owner->id, $tickets[0]->created_by);
    }

    public function test_the_page_lists_the_projects_tickets_and_its_people()
    {
        $editor = $this->member(Project::EDITOR);
        Ticket::file($this->project, $this->owner, ['type' => Ticket::FEATURE, 'title' => 'Dark board', 'assigned_to' => $editor->id]);
        Ticket::factory()->create(['title' => 'Somebody else\'s']);

        $this->actingAs($this->member(Project::VIEWER))
            ->get(route('projects.tickets.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tickets/Index')
                ->has('tickets', 1)
                ->where('tickets.0.title', 'Dark board')
                ->where('tickets.0.number', 1)
                ->where('tickets.0.assigned_to.id', $editor->id)
                ->where('tickets.0.created_by.name', $this->owner->name)
                ->has('people', 3)
            );
    }

    public function test_a_ticket_is_moved_along_and_closes_when_done()
    {
        $ticket = Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'Resize shakes']);

        $this->actingAs($this->member(Project::EDITOR))
            ->patch(route('projects.tickets.update', [$this->project, $ticket]), ['status' => Ticket::DONE])
            ->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(Ticket::DONE, $ticket->status);
        $this->assertNotNull($ticket->closed_at);
        // Only what was named changed
        $this->assertSame('Resize shakes', $ticket->title);

        $this->actingAs($this->owner)
            ->patch(route('projects.tickets.update', [$this->project, $ticket]), ['status' => Ticket::OPEN])
            ->assertSessionHasNoErrors();

        $this->assertNull($ticket->refresh()->closed_at);
    }

    public function test_it_takes_pictures_from_the_drive_or_the_web_and_nothing_else()
    {
        $ticket = Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'Resize shakes']);

        $this->actingAs($this->owner)
            ->patch(route('projects.tickets.update', [$this->project, $ticket]), [
                'pictures' => [
                    ['src' => '/drive/files/e3agxrzz0a', 'name' => 'image.png'],
                    ['src' => 'https://example.com/shot.png'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([
            ['src' => '/drive/files/e3agxrzz0a', 'name' => 'image.png'],
            ['src' => 'https://example.com/shot.png', 'name' => ''],
        ], $ticket->refresh()->pictures);

        $this->actingAs($this->owner)
            ->patch(route('projects.tickets.update', [$this->project, $ticket]), [
                'pictures' => [['src' => 'javascript:alert(1)']],
            ])
            ->assertSessionHasErrors('pictures.0.src');
    }

    public function test_it_is_given_only_to_someone_in_the_project()
    {
        $ticket = Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'Resize shakes']);
        $editor = $this->member(Project::EDITOR);

        $this->actingAs($this->owner)
            ->patch(route('projects.tickets.update', [$this->project, $ticket]), ['assigned_to' => User::factory()->create()->id])
            ->assertSessionHasErrors('assigned_to');

        $this->actingAs($this->owner)
            ->patch(route('projects.tickets.update', [$this->project, $ticket]), ['assigned_to' => $editor->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($editor->id, $ticket->refresh()->assigned_to);
    }

    public function test_a_viewer_reads_tickets_but_changes_none()
    {
        $ticket = Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'Resize shakes']);
        $viewer = $this->member(Project::VIEWER);

        $this->actingAs($viewer)->get(route('projects.tickets.index', $this->project))->assertOk();
        $this->actingAs($viewer)
            ->post(route('projects.tickets.store', $this->project), ['type' => Ticket::BUG, 'title' => 'Mine'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->patch(route('projects.tickets.update', [$this->project, $ticket]), ['status' => Ticket::DONE])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->delete(route('projects.tickets.destroy', [$this->project, $ticket]))
            ->assertForbidden();
    }

    public function test_nobody_outside_the_project_reaches_its_tickets()
    {
        $ticket = Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'Resize shakes']);
        $stranger = User::factory()->create();
        $theirs = Project::factory()->withMember($stranger)->create();

        $this->actingAs($stranger)->get(route('projects.tickets.index', $this->project))->assertForbidden();

        // Nor through a project of their own, with the ticket's id in the URL
        $this->actingAs($stranger)
            ->patch(route('projects.tickets.update', [$theirs, $ticket]), ['status' => Ticket::DONE])
            ->assertNotFound();

        $this->assertSame(Ticket::OPEN, $ticket->refresh()->status);
    }

    public function test_it_is_talked_over_in_a_group_of_the_project_started_once()
    {
        $editor = $this->member(Project::EDITOR);
        $ticket = Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'Resize shakes', 'assigned_to' => $editor->id]);
        $viewer = $this->member(Project::VIEWER);

        $this->actingAs($viewer)
            ->post(route('projects.tickets.chat', [$this->project, $ticket]))
            ->assertRedirect();

        $room = $ticket->refresh()->room;
        $this->assertSame(ChatRoom::GROUP, $room->kind);
        $this->assertSame('#1 Resize shakes', $room->title);
        $this->assertEqualsCanonicalizing(
            [$this->owner->id, $editor->id, $viewer->id],
            $room->members()->pluck('users.id')->all(),
        );

        // Asked again, it is the same group
        $this->actingAs($this->owner)
            ->post(route('projects.tickets.chat', [$this->project, $ticket]))
            ->assertRedirect(route('chats.show', $room));

        $this->assertSame(1, $this->project->chatRooms()->count());
    }

    public function test_a_ticket_is_deleted_and_goes_with_its_project()
    {
        $first = Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'One']);
        Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'Two']);

        $this->actingAs($this->owner)
            ->delete(route('projects.tickets.destroy', [$this->project, $first]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Ticket::query()->count());

        $this->project->delete();

        $this->assertSame(0, Ticket::query()->count());
    }
}
