<?php

namespace Tests\Feature;

use App\Models\Browser\BrowserRun;
use App\Models\Chat\ChatRoom;
use App\Models\Drive\DriveFile;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_it_takes_pictures_and_videos_from_the_drive_or_the_web_and_nothing_else()
    {
        $ticket = Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'Resize shakes']);
        $film = DriveFile::factory()->create(['name' => 'shakes.mp4', 'mime' => 'video/mp4', 'ext' => 'mp4', 'kind' => 'video']);

        $this->actingAs($this->owner)
            ->patch(route('projects.tickets.update', [$this->project, $ticket]), [
                'pictures' => [
                    ['src' => '/drive/files/e3agxrzz0a', 'name' => 'image.png'],
                    ['src' => 'https://example.com/shot.png'],
                    // Said to be a picture: what it is goes by the Drive
                    ['src' => "/drive/files/{$film->ref_id}", 'name' => 'shakes.mp4', 'kind' => 'image'],
                    ['src' => 'https://example.com/clip.webm?x=1'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([
            ['src' => '/drive/files/e3agxrzz0a', 'name' => 'image.png', 'kind' => 'image'],
            ['src' => 'https://example.com/shot.png', 'name' => '', 'kind' => 'image'],
            ['src' => "/drive/files/{$film->ref_id}", 'name' => 'shakes.mp4', 'kind' => 'video'],
            ['src' => 'https://example.com/clip.webm?x=1', 'name' => '', 'kind' => 'video'],
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

    public function test_a_bug_says_how_to_make_it_happen_again_and_which_check_does()
    {
        $suite = $this->browserSuite($this->project, $this->owner);
        $elsewhere = $this->browserSuite(Project::factory()->withMember($this->owner)->create(), $this->owner);

        $this->actingAs($this->owner)
            ->post(route('projects.tickets.store', $this->project), [
                'type' => Ticket::BUG,
                'title' => 'Logging in fails',
                'repro' => ['steps' => [' Open the shop ', '', 'Click "Log in"'], 'expected' => 'Signed in', 'happens' => ''],
                'check' => ['suite' => $suite->ref_id, 'check' => 'login'],
            ])
            ->assertSessionHasNoErrors();

        $ticket = Ticket::sole();
        // Blank steps left out, the rest kept in order
        $this->assertSame(['steps' => ['Open the shop', 'Click "Log in"'], 'expected' => 'Signed in', 'happens' => ''], $ticket->repro);
        $this->assertSame($suite->id, $ticket->browser_suite_id);
        $this->assertSame('login', $ticket->browser_check);

        $this->actingAs($this->owner)
            ->get(route('projects.tickets.index', $this->project))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tickets.0.check.title', 'Logging in')
                ->where('tickets.0.check.run', null)
                ->where('suites.0.checks.0.id', 'login')
            );

        // Only the project's own checks, and only ones its suite has
        foreach ([['suite' => $elsewhere->ref_id, 'check' => 'login'], ['suite' => $suite->ref_id, 'check' => 'gone']] as $check) {
            $this->actingAs($this->owner)
                ->patch(route('projects.tickets.update', [$this->project, $ticket]), ['check' => $check])
                ->assertSessionHasErrors('check');
        }

        // Nothing said is nothing kept
        $this->actingAs($this->owner)
            ->patch(route('projects.tickets.update', [$this->project, $ticket]), ['check' => null, 'repro' => ['steps' => [''], 'expected' => ' ']])
            ->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertNull($ticket->repro);
        $this->assertNull($ticket->browser_suite_id);
    }

    public function test_run_it_says_whether_the_bug_still_happens_and_files_nothing_new()
    {
        Storage::fake(DriveFile::DISK);
        $suite = $this->browserSuite($this->project, $this->owner);
        // Failures of its checks are filed as tickets: not when a ticket asked
        $suite->settings = ['tickets' => true];
        $suite->save();
        $ticket = Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'Logging in fails', 'browser_suite_id' => $suite->id, 'browser_check' => 'login']);

        $this->actingAs($this->member(Project::VIEWER))
            ->post(route('projects.tickets.replay', [$this->project, $ticket]))
            ->assertForbidden();

        // The bug happens the first time, and is gone the second
        $answer = fn (bool $passes) => [
            'steps' => [['ok' => true, 'took' => 300], ['ok' => $passes, 'took' => 50, 'error' => $passes ? null : 'Nothing showing "Log in" turned up.']],
            'url' => 'https://shop.example.com/',
            'title' => 'Shop',
            'refused' => [],
            'video' => null,
        ];
        Http::fake(['chrome:3000/check' => Http::sequence()->push($answer(false))->push($answer(true))]);

        foreach ([false, true] as $passes) {
            $this->actingAs($this->owner)
                ->post(route('projects.tickets.replay', [$this->project, $ticket]))
                ->assertSessionHasNoErrors();

            $run = BrowserRun::query()->latest('id')->firstOrFail();
            // Only its check, filmed, and said to be the ticket's
            $this->assertSame(['login'], $run->settings['checks']);
            $this->assertTrue($run->settings['record']);
            $this->assertSame($ticket->number, $run->settings['ticket']);

            $this->work();

            $this->actingAs($this->owner)
                ->get(route('projects.tickets.index', $this->project))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('tickets.0.check.run.ref_id', $run->ref_id)
                    ->where('tickets.0.check.run.ok', $passes)
                );
        }

        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_run_it_needs_a_check_whose_suite_still_has_it()
    {
        $suite = $this->browserSuite($this->project, $this->owner);
        $ticket = Ticket::file($this->project, $this->owner, ['type' => Ticket::BUG, 'title' => 'Logging in fails']);

        $this->actingAs($this->owner)
            ->post(route('projects.tickets.replay', [$this->project, $ticket]))
            ->assertSessionHasErrors('check');

        $ticket->update(['browser_suite_id' => $suite->id, 'browser_check' => 'login']);
        $suite->delete();

        $this->assertSame(['gone' => true, 'suite' => null, 'check' => 'login'], $ticket->refresh()->summary()['check']);
        $this->assertSame(0, BrowserRun::query()->count());
    }
}
