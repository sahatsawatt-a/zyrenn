<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Tables\CreateTable;
use App\Models\Board\Board;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Board\BoardActions;
use App\Support\Prototype\PrototypeTables;
use App\Support\Table\TableStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A project table as a prototype's database: Play gets the tables its items
 * name, rows by column label; and, when the prototype is set to change the
 * tables themselves, saves and deletes rows in them -- only for whoever may
 * change the project, and only in tables of its own that it names.
 */
class PrototypeTablesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Table $tickets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->owner = User::factory()->create();
        $this->project = Project::factory()->withMember($this->owner)->create();
        $this->tickets = $this->table($this->project, 'Tickets');
    }

    private function table(Project $project, string $title): Table
    {
        UserServer::actingAs($this->owner)->tool(CreateTable::class, [
            'project' => $project->ref_id,
            'title' => $title,
            'columns' => [
                ['label' => 'Title'],
                ['label' => 'Points', 'type' => 'integer'],
                ['label' => 'Status', 'type' => 'select', 'choices' => ['Open', 'Done']],
            ],
            'rows' => [
                ['Title' => 'Login page', 'Points' => 3, 'Status' => 'Open'],
                ['Title' => 'Checkout', 'Points' => 5, 'Status' => 'Done'],
            ],
        ])->assertOk();

        return Table::query()->where('title', $title)->latest('id')->firstOrFail();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->project->members()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * A prototype whose list screen repeats a row card for each ticket and
     * whose form saves one, and deletes one from inside an if.
     *
     * @param  array<string, mixed>  $settings
     */
    private function prototype(array $settings = [], ?string $other = null): Board
    {
        $board = (new Board)->ownedBy($this->project, $this->owner);
        $board->title = 'Tracker';
        $board->kind = Board::PROTOTYPE;
        $board->prototype = ['device' => 'phone', 'start' => 'list', ...$settings];
        $board->content = ['items' => [
            ['id' => 'list', 'kind' => 'frame', 'x' => 0, 'y' => 0, 'width' => 390, 'height' => 844, 'text' => 'List'],
            ['id' => 'card', 'kind' => 'rect', 'x' => 20, 'y' => 100, 'width' => 350, 'height' => 80, 'text' => '{Title}', 'repeat' => ['table' => $this->tickets->ref_id]],
            ['id' => 'save', 'kind' => 'rect', 'x' => 20, 'y' => 700, 'width' => 200, 'height' => 60, 'text' => 'Save',
                'link' => ['all' => [['save' => $this->tickets->ref_id, 'values' => ['Title' => '{title}']], ['to' => 'list']]]],
            ['id' => 'drop', 'kind' => 'rect', 'x' => 20, 'y' => 780, 'width' => 200, 'height' => 60, 'text' => 'Delete',
                'when' => ['long-press' => ['if' => 'sure', 'then' => ['remove' => $other ?? $this->tickets->ref_id]]]],
        ]];
        $board->save();

        return $board;
    }

    public function test_the_tables_a_prototype_names_are_found_wherever_they_are_named()
    {
        $board = $this->prototype([], 'tbl-other');

        $this->assertEqualsCanonicalizing([$this->tickets->ref_id, 'tbl-other'], BoardActions::tables($board->content['items']));
    }

    public function test_play_gets_its_tables_rows_by_label_and_saves_to_a_copy_by_default()
    {
        $board = $this->prototype();

        $this->actingAs($this->owner)->get(route('prototypes.play', $board))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("tables.{$this->tickets->ref_id}.title", 'Tickets')
                ->where("tables.{$this->tickets->ref_id}.columns", [
                    ['label' => 'Title', 'type' => 'varchar'],
                    ['label' => 'Points', 'type' => 'integer'],
                    ['label' => 'Status', 'type' => 'select'],
                ])
                ->where("tables.{$this->tickets->ref_id}.rows.0.Title", 'Login page')
                ->where("tables.{$this->tickets->ref_id}.rows.0.Points", 3)
                ->where("tables.{$this->tickets->ref_id}.rows.1.Status", 'Done')
                ->has("tables.{$this->tickets->ref_id}.rows", 2)
                ->where('can_save', false));
    }

    public function test_only_its_own_projects_tables_reach_play_at_most_200_rows_each()
    {
        $elsewhere = Project::factory()->withMember($this->owner)->create();
        $theirs = $this->table($elsewhere, 'Elsewhere');
        $board = $this->prototype([], $theirs->ref_id);

        foreach (range(1, 205) as $n) {
            TableStorage::insertRow($this->tickets, []);
        }

        $this->actingAs($this->owner)->get(route('prototypes.play', $board))
            ->assertInertia(fn (Assert $page) => $page
                ->missing("tables.{$theirs->ref_id}")
                ->has("tables.{$this->tickets->ref_id}.rows", PrototypeTables::MOST_ROWS));
    }

    public function test_saving_to_the_tables_is_for_editors_when_the_prototype_says_so()
    {
        $board = $this->prototype(['data' => 'tables']);
        $viewer = $this->member(Project::VIEWER);

        $this->actingAs($this->owner)->get(route('prototypes.play', $board))->assertInertia(fn (Assert $page) => $page->where('can_save', true));
        // A viewer plays on a copy
        $this->actingAs($viewer)->get(route('prototypes.play', $board))->assertInertia(fn (Assert $page) => $page->where('can_save', false));
        $this->actingAs($viewer)
            ->postJson(route('prototypes.rows.store', $board), ['table' => $this->tickets->ref_id, 'values' => ['Title' => 'Mine']])
            ->assertForbidden();

        // Set to keep each play's own copy, nobody writes to the table
        $copy = $this->prototype();
        $this->actingAs($this->owner)
            ->postJson(route('prototypes.rows.store', $copy), ['table' => $this->tickets->ref_id, 'values' => ['Title' => 'Mine']])
            ->assertForbidden();

        $this->assertSame(2, TableStorage::count($this->tickets));
    }

    public function test_a_row_is_added_changed_and_deleted_by_label_and_comes_back_as_kept()
    {
        $board = $this->prototype(['data' => 'tables']);
        $at = route('prototypes.rows.store', $board);

        $added = $this->actingAs($this->owner)
            ->postJson($at, ['table' => $this->tickets->ref_id, 'values' => ['title' => 'Search', 'Points' => '8', 'Status' => 'Blocked']])
            ->assertCreated()
            ->json('row');

        // Typed as the table keeps it, and a new choice added to the select
        $this->assertSame(['Title' => 'Search', 'Points' => 8, 'Status' => 'Blocked'], array_diff_key($added, ['id' => 0]));
        $this->assertIsInt($added['id']);

        $this->actingAs($this->owner)
            ->postJson($at, ['table' => $this->tickets->ref_id, 'row' => $added['id'], 'values' => ['Status' => 'Done']])
            ->assertOk()
            ->assertJsonPath('row.Status', 'Done')
            ->assertJsonPath('row.Title', 'Search');

        $this->actingAs($this->owner)
            ->deleteJson(route('prototypes.rows.destroy', $board), ['table' => $this->tickets->ref_id, 'row' => $added['id']])
            ->assertNoContent();

        $this->assertSame(2, TableStorage::count($this->tickets));
    }

    public function test_what_play_may_not_write_is_said()
    {
        $elsewhere = Project::factory()->withMember($this->owner)->create();
        $theirs = $this->table($elsewhere, 'Elsewhere');
        $unnamed = $this->table($this->project, 'Unused');
        $board = $this->prototype(['data' => 'tables'], $theirs->ref_id);
        $at = route('prototypes.rows.store', $board);

        // Another project's table, even one the prototype names; and one it doesn't name
        $this->actingAs($this->owner)->postJson($at, ['table' => $theirs->ref_id, 'values' => ['Title' => 'X']])->assertNotFound();
        $this->actingAs($this->owner)->postJson($at, ['table' => $unnamed->ref_id, 'values' => ['Title' => 'X']])->assertNotFound();

        $this->actingAs($this->owner)
            ->postJson($at, ['table' => $this->tickets->ref_id, 'values' => ['Colour' => 'Red']])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $said) => str_contains($said, 'no column "Colour"'));
        $this->actingAs($this->owner)
            ->postJson($at, ['table' => $this->tickets->ref_id, 'row' => 999, 'values' => ['Title' => 'X']])
            ->assertNotFound();

        $this->assertSame(2, TableStorage::count($theirs));
        $this->assertSame(2, TableStorage::count($unnamed));
    }

    public function test_the_prototype_is_set_to_keep_changes_to_each_play_or_save_them_to_the_tables()
    {
        $board = $this->prototype();
        $viewer = $this->member(Project::VIEWER);

        $this->actingAs($viewer)->patch(route('prototypes.update', $board), ['data' => 'tables'])->assertForbidden();
        $this->actingAs($this->owner)->patch(route('prototypes.update', $board), ['data' => 'nowhere'])->assertSessionHasErrors('data');
        $this->actingAs($this->owner)->patch(route('prototypes.update', $board), ['data' => 'tables'])->assertSessionHasNoErrors();

        $this->assertSame('tables', $board->refresh()->prototype['data']);
        $this->assertSame('phone', $board->prototype['device']);
    }
}
