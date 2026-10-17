<?php

namespace Tests\Feature;

use App\Events\PrototypeCommentChanged;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\GetBoard;
use App\Models\Board\Board;
use App\Models\Project;
use App\Models\Prototype\PrototypePlay;
use App\Models\Ticket\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * What people make of a prototype: comments pinned to its screens, made by
 * anyone in its project and made tickets when they are work; and each time
 * it was played -- the screens gone through and the taps that missed.
 */
class PrototypeFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Board $prototype;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->owner = User::factory()->create();
        $this->project = Project::factory()->withMember($this->owner)->create();

        $this->actingAs($this->owner)
            ->post(route('projects.prototypes.store', $this->project), ['title' => 'Booking flow', 'device' => 'phone']);
        $this->prototype = $this->project->prototypes()->firstOrFail();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->project->members()->attach($user, ['role' => $role]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function comment(User $by, string $body = 'This button should say Book now', string $screen = 'screen-1'): array
    {
        return $this->actingAs($by)
            ->postJson(route('prototypes.comments.store', $this->prototype), ['screen' => $screen, 'x' => 0.25, 'y' => 0.8, 'body' => $body])
            ->assertCreated()
            ->json('comment');
    }

    public function test_a_viewer_pins_a_comment_to_a_spot_on_a_screen_and_everyone_hears()
    {
        Event::fake([PrototypeCommentChanged::class]);
        $viewer = $this->member(Project::VIEWER);

        $said = $this->comment($viewer);

        $this->assertSame(['screen-1', 0.25, 0.8, 'This button should say Book now', false], [$said['screen'], $said['x'], $said['y'], $said['body'], $said['resolved']]);
        $this->assertSame($viewer->name, $said['by']['name']);
        Event::assertDispatched(PrototypeCommentChanged::class, fn ($event) => $event->broadcastOn()->name === "presence-boards.{$this->prototype->ref_id}" && $event->broadcastAs() === 'prototype.comment');

        $this->actingAs($this->owner)
            ->getJson(route('prototypes.comments.index', $this->prototype))
            ->assertOk()
            ->assertJsonPath('comments.0.body', 'This button should say Book now');
    }

    public function test_a_comment_must_be_on_one_of_its_screens_and_from_the_project()
    {
        $this->actingAs($this->owner)
            ->postJson(route('prototypes.comments.store', $this->prototype), ['screen' => 'nowhere', 'x' => 0.5, 'y' => 0.5, 'body' => 'Hm'])
            ->assertStatus(422);
        $this->actingAs($this->owner)
            ->postJson(route('prototypes.comments.store', $this->prototype), ['screen' => 'screen-1', 'x' => 1.5, 'y' => 0.5, 'body' => 'Hm'])
            ->assertJsonValidationErrors('x');

        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->postJson(route('prototypes.comments.store', $this->prototype), ['screen' => 'screen-1', 'x' => 0.5, 'y' => 0.5, 'body' => 'Hm'])
            ->assertForbidden();
        $this->actingAs($stranger)->getJson(route('prototypes.comments.index', $this->prototype))->assertForbidden();

        // Not a prototype: no comments to have
        $board = (new Board)->ownedBy($this->project, $this->owner);
        $board->save();
        $this->actingAs($this->owner)->getJson(route('prototypes.comments.index', $board))->assertNotFound();
    }

    public function test_its_writer_or_an_editor_resolves_it_and_only_its_writer_changes_its_words()
    {
        $viewer = $this->member(Project::VIEWER);
        $other = $this->member(Project::VIEWER);
        $said = $this->comment($viewer);
        $at = route('prototype-comments.update', $said['ref_id']);

        $this->actingAs($other)->patchJson($at, ['resolved' => true])->assertForbidden();
        $this->actingAs($other)->patchJson($at, ['body' => 'Mine now'])->assertForbidden();
        $this->actingAs($this->owner)->patchJson($at, ['body' => 'Mine now'])->assertForbidden();

        $this->actingAs($viewer)->patchJson($at, ['body' => 'Book now, in bold'])->assertJsonPath('comment.body', 'Book now, in bold');
        $this->actingAs($this->owner)->patchJson($at, ['resolved' => true])->assertJsonPath('comment.resolved', true);
        $this->actingAs($viewer)->patchJson($at, ['resolved' => false])->assertJsonPath('comment.resolved', false);
    }

    public function test_a_comment_made_a_ticket_files_one_on_the_project_once_and_links_it()
    {
        $viewer = $this->member(Project::VIEWER);
        $said = $this->comment($viewer);
        $at = route('prototype-comments.ticket', $said['ref_id']);

        // A viewer may say it, not file work
        $this->actingAs($viewer)->postJson($at)->assertForbidden();

        $made = $this->actingAs($this->owner)->postJson($at)->assertOk()->json('comment.ticket');
        $this->actingAs($this->owner)->postJson($at)->assertOk()->assertJsonPath('comment.ticket.number', $made['number']);

        $ticket = Ticket::query()->where('project_id', $this->project->id)->sole();
        $this->assertSame([1, 'open', 'This button should say Book now'], [$ticket->number, $ticket->status, $ticket->title]);
        $this->assertStringContainsString('screen "Home"', (string) $ticket->body);
        $this->assertStringContainsString(route('prototypes.play', $this->prototype).'#screen-1', (string) $ticket->body);
    }

    public function test_a_play_is_kept_as_it_goes_and_read_by_the_project()
    {
        $viewer = $this->member(Project::VIEWER);

        $play = $this->actingAs($viewer)
            ->postJson(route('prototypes.plays.store', $this->prototype), ['screen' => 'screen-1', 'width' => 390, 'height' => 844])
            ->assertCreated()
            ->json('play');
        $at = route('prototype-plays.append', $play);

        $this->actingAs($viewer)->post($at, [
            'path' => [['screen' => 'screen-2', 'at' => 3.2]],
            'misses' => [['screen' => 'screen-1', 'x' => 0.5, 'y' => 0.75, 'at' => 1.5]],
        ])->assertNoContent();
        $this->actingAs($viewer)->post($at, ['path' => [['screen' => 'screen-3', 'at' => 9.04]], 'ended' => true])->assertNoContent();

        // Someone else's play is theirs to add to
        $this->actingAs($this->owner)->post($at, ['path' => [['screen' => 'screen-1', 'at' => 1]]])->assertForbidden();

        $kept = PrototypePlay::query()->sole();
        $this->assertSame(['screen-1', 'screen-2', 'screen-3'], array_column($kept->path, 'screen'));
        $this->assertSame([['screen' => 'screen-1', 'at' => 1.5, 'x' => 0.5, 'y' => 0.75]], $kept->misses);
        $this->assertNotNull($kept->ended_at);

        $this->actingAs($this->owner)
            ->getJson(route('prototypes.plays.index', $this->prototype))
            ->assertOk()
            ->assertJsonPath('plays.0.by.name', $viewer->name)
            ->assertJsonPath('plays.0.path.2.at', 9);
    }

    public function test_a_long_play_is_cut_off_not_refused()
    {
        $play = $this->actingAs($this->owner)->postJson(route('prototypes.plays.store', $this->prototype))->json('play');
        $steps = array_map(fn (int $at) => ['screen' => 'screen-1', 'at' => $at], range(1, 400));

        $this->actingAs($this->owner)->post(route('prototype-plays.append', $play), ['path' => $steps])->assertNoContent();
        $this->actingAs($this->owner)->post(route('prototype-plays.append', $play), ['path' => $steps])->assertNoContent();

        $this->assertCount(PrototypePlay::MOST_STEPS, PrototypePlay::query()->sole()->path);
    }

    public function test_get_board_reads_a_prototypes_open_comments_leanly()
    {
        $this->comment($this->owner, 'Book now, not Submit');
        $done = $this->comment($this->owner, 'Logo too small', 'screen-2');
        $this->actingAs($this->owner)->patchJson(route('prototype-comments.update', $done['ref_id']), ['resolved' => true]);
        $this->comment($this->owner, 'Where is the back button?', 'screen-2');

        UserServer::actingAs($this->owner)
            ->tool(GetBoard::class, ['project' => $this->project->ref_id, 'ref_id' => $this->prototype->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('comments', 2)
                ->where('comments.0.body', 'Book now, not Submit')
                ->where('comments.0.screen', 'screen-1')
                ->where('comments.0.x', 0.25)
                ->where('comments.0.by', $this->owner->name)
                ->missing('comments.0.ticket')
                ->where('resolved_comments', 1)
                ->etc());

        // One screen's, when a frame is asked for
        UserServer::actingAs($this->owner)
            ->tool(GetBoard::class, ['project' => $this->project->ref_id, 'ref_id' => $this->prototype->ref_id, 'frame' => 'screen-2'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('comments', 1)
                ->where('comments.0.body', 'Where is the back button?')
                ->etc());
    }
}
