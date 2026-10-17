<?php

namespace Tests\Feature;

use App\Models\Board\Board;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Table\TableStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A project's prototypes: boards drawn as screens at a device's size, listed
 * apart from its boards, and clicked through in Play.
 */
class PrototypeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->owner = User::factory()->create();
        $this->project = Project::factory()->withMember($this->owner)->create();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->project->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private function make(string $device = 'phone', string $title = 'Booking flow'): Board
    {
        $this->actingAs($this->owner)
            ->post(route('projects.prototypes.store', $this->project), ['title' => $title, 'device' => $device])
            ->assertSessionHasNoErrors();

        return $this->project->prototypes()->latest('id')->firstOrFail();
    }

    public function test_a_new_prototype_starts_with_three_screens_at_its_devices_size()
    {
        $response = $this->actingAs($this->owner)
            ->post(route('projects.prototypes.store', $this->project), ['title' => 'Booking flow', 'device' => 'tablet']);

        $prototype = $this->project->prototypes()->firstOrFail();
        $response->assertRedirect(route('prototypes.show', $prototype));

        $this->assertSame(Board::PROTOTYPE, $prototype->kind);
        $this->assertSame(['device' => 'tablet', 'start' => 'screen-1'], $prototype->prototype);

        $screens = $prototype->content['items'];
        $this->assertSame(['Home', 'List', 'Detail'], array_column($screens, 'text'));
        $this->assertSame([834], array_values(array_unique(array_column($screens, 'width'))));
        $this->assertSame([1194], array_values(array_unique(array_column($screens, 'height'))));
        // Side by side, none on another
        $this->assertSame([0, 994, 1988], array_column($screens, 'x'));
    }

    public function test_it_is_listed_with_prototypes_and_not_with_boards()
    {
        $prototype = $this->make();
        $board = (new Board)->ownedBy($this->project, $this->owner);
        $board->title = 'Plain board';
        $board->save();

        $this->assertSame(['Plain board'], $this->project->boards()->pluck('title')->all());

        $this->actingAs($this->owner)
            ->get(route('projects.prototypes.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('prototypes/Index')
                ->has('prototypes', 1)
                ->where('prototypes.0.ref_id', $prototype->ref_id)
                ->where('prototypes.0.device', 'phone')
                ->where('prototypes.0.screens', 3)
                ->has("previews.board:{$prototype->ref_id}"));

        // It lives at its own address; a board's sends it there
        $this->actingAs($this->owner)
            ->get(route('prototypes.show', $prototype))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('boards/Show', false)
                ->where('prototype.device', 'phone'));
        $this->actingAs($this->owner)
            ->get(route('boards.show', $prototype))
            ->assertRedirect(route('prototypes.show', $prototype));
        $this->actingAs($this->owner)
            ->get(route('prototypes.show', $board))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->get(route('boards.show', $board))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('prototype', null)
                ->where('tables', []));
    }

    public function test_play_gives_its_screens_in_reading_order()
    {
        $prototype = $this->make();
        $items = $prototype->content['items'];
        // A screen added under the first row comes after it
        $items[] = [...$items[0], 'id' => 'below', 'text' => 'Settings', 'x' => 0, 'y' => 1200];
        $items[] = ['id' => 'button', 'kind' => 'rect', 'x' => 20, 'y' => 20, 'width' => 100, 'height' => 40, 'link' => ['to' => 'screen-2']];
        $prototype->content = ['items' => $items];
        $prototype->save();

        $this->actingAs($this->owner)
            ->get(route('prototypes.play', $prototype))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('prototypes/Play', false)
                ->where('board.ref_id', $prototype->ref_id)
                ->where('prototype.start', 'screen-1')
                ->where('screens', [
                    ['id' => 'screen-1', 'title' => 'Home'],
                    ['id' => 'screen-2', 'title' => 'List'],
                    ['id' => 'screen-3', 'title' => 'Detail'],
                    ['id' => 'below', 'title' => 'Settings'],
                ])
                ->has('items', 5)
                ->where('edit', route('prototypes.show', $prototype))
                ->where('project.ref_id', $this->project->ref_id));
    }

    public function test_its_device_and_start_screen_change()
    {
        $prototype = $this->make();

        $this->actingAs($this->owner)
            ->patch(route('prototypes.update', $prototype), ['device' => 'desktop'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)
            ->patch(route('prototypes.update', $prototype), ['start' => 'screen-2'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['device' => 'desktop', 'start' => 'screen-2'], $prototype->fresh()->prototype);

        $this->actingAs($this->owner)
            ->patch(route('prototypes.update', $prototype), ['device' => 'watch'])
            ->assertSessionHasErrors('device');
    }

    public function test_viewers_play_but_do_not_make_or_change_and_outsiders_see_nothing()
    {
        $prototype = $this->make();
        $viewer = $this->member('viewer');
        $outsider = User::factory()->create();

        $this->actingAs($viewer)->get(route('projects.prototypes.index', $this->project))->assertOk();
        $this->actingAs($viewer)->get(route('prototypes.play', $prototype))->assertOk();
        $this->actingAs($viewer)
            ->post(route('projects.prototypes.store', $this->project), ['device' => 'phone'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->patch(route('prototypes.update', $prototype), ['device' => 'tablet'])
            ->assertForbidden();

        $this->actingAs($outsider)->get(route('projects.prototypes.index', $this->project))->assertForbidden();
        $this->actingAs($outsider)->get(route('prototypes.play', $prototype))->assertForbidden();
    }

    public function test_a_plain_board_has_no_play_and_a_deleted_prototype_goes_back_to_the_list()
    {
        $board = (new Board)->ownedBy($this->project, $this->owner);
        $board->save();

        $this->actingAs($this->owner)->get(route('prototypes.play', $board))->assertNotFound();

        $prototype = $this->make();

        $this->actingAs($this->owner)
            ->delete(route('boards.destroy', $prototype))
            ->assertRedirect(route('projects.prototypes.index', $this->project));
    }

    public function test_its_page_lists_its_projects_tables_with_their_shown_columns()
    {
        $prototype = $this->make();
        $table = (new Table)->ownedBy($this->project, $this->owner);
        $table->title = 'Tickets';
        $table->save();
        TableStorage::create($table);
        foreach (['Title' => 'varchar', 'Secret' => 'varchar', 'Priority' => 'select'] as $label => $type) {
            TableStorage::newColumn($table, ['label' => $label, 'type' => $type, 'hidden' => $label === 'Secret']);
        }
        // Another project's is none of its business
        $elsewhere = (new Table)->ownedBy(Project::factory()->withMember($this->owner)->create(), $this->owner);
        $elsewhere->title = 'Elsewhere';
        $elsewhere->save();

        $this->actingAs($this->owner)
            ->get(route('prototypes.show', $prototype))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tables', 1)
                ->where('tables.0.ref_id', $table->ref_id)
                ->where('tables.0.title', 'Tickets')
                ->where('tables.0.columns', fn ($columns) => collect($columns)->pluck('label')->intersect(['Title', 'Priority'])->count() === 2
                    && ! collect($columns)->pluck('label')->contains('Secret')));
    }
}
