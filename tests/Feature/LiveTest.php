<?php

namespace Tests\Feature;

use App\Events\TableChanged;
use App\Models\Board\Board;
use App\Models\Note\Note;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Table\TableStorage;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Working at the same time: who has something open, what they are told as it
 * changes, and who changed what last.
 */
class LiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function table(User $user): Table
    {
        return Table::factory()->for($user)->create()->fresh();
    }

    /**
     * What a channel's own rule answers for the user.
     */
    private function joins(string $channel, User $user, mixed $model): mixed
    {
        $rules = app(BroadcastManager::class)->driver()->getChannels();

        return $rules[$channel]($user, $model);
    }

    public function test_anyone_who_may_see_something_joins_its_channel_by_name()
    {
        $project = Project::factory()->create();
        $viewer = User::factory()->create(['name' => 'Ada']);
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);
        $note = (new Note(['title' => 'Shared']))->ownedBy($project, $viewer);
        $note->save();
        $board = Board::factory()->create();

        $this->assertSame(['id' => $viewer->id, 'name' => 'Ada'], $this->joins('notes.{note}', $viewer, $note));
        $this->assertFalse($this->joins('notes.{note}', User::factory()->create(), $note));
        $this->assertFalse($this->joins('boards.{board}', $viewer, $board));
        $this->assertNotFalse($this->joins('boards.{board}', $board->user, $board));
    }

    public function test_cell_row_and_delete_changes_are_told_to_the_others()
    {
        Event::fake([TableChanged::class]);

        $user = User::factory()->create();
        $table = $this->table($user);
        $this->actingAs($user);

        $row = $this->postJson(route('tables.rows.store', $table))->json('row');
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->change === 'row'
            && $event->details['row']['id'] === $row['id']);

        $column = TableStorage::newColumn($table, ['label' => 'Note', 'type' => 'varchar'])->name;
        $this->patchJson(route('tables.rows.update', [$table, $row['id']]), ['column' => $column, 'value' => 'Hello']);
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->change === 'row'
            && ($event->details['row'][$column] ?? null) === 'Hello');

        $this->deleteJson(route('tables.rows.destroy', $table), ['ids' => [$row['id']]]);
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->change === 'rows.deleted'
            && $event->details['ids'] === [$row['id']]);
    }

    public function test_a_new_column_asks_the_others_to_reload_and_a_rename_sends_the_title()
    {
        Event::fake([TableChanged::class]);

        $user = User::factory()->create();
        $table = $this->table($user);
        $this->actingAs($user);

        $this->postJson(route('tables.columns.store', $table), ['label' => 'Owner', 'type' => 'user']);
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->change === 'reload');

        $this->patchJson(route('tables.update', $table), ['title' => 'Budget']);
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->change === 'table'
            && $event->details === ['title' => 'Budget', 'density' => 'normal']);
    }

    public function test_a_deleted_table_says_so_on_its_channel()
    {
        Event::fake([TableChanged::class]);

        $table = $this->table(User::factory()->create());
        $table->delete();

        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->change === 'deleted'
            && $event->broadcastOn()->name === "presence-tables.{$table->ref_id}");
    }

    public function test_a_save_records_who_made_it()
    {
        $project = Project::factory()->create();
        $editor = User::factory()->create();
        $project->members()->attach($editor, ['role' => Project::EDITOR]);
        $note = (new Note(['title' => 'Shared']))->ownedBy($project, User::factory()->create());
        $note->save();

        $this->actingAs($editor)->patchJson(route('notes.update', $note), ['title' => 'Changed']);

        $this->assertSame($editor->id, $note->fresh()->updated_by);
    }

    public function test_a_projects_list_says_who_changed_each_thing_last()
    {
        $project = Project::factory()->create();
        $editor = User::factory()->create(['name' => 'Ada']);
        $project->members()->attach($editor, ['role' => Project::EDITOR]);
        $note = (new Note(['title' => 'Shared']))->ownedBy($project, $editor);
        $note->save();
        $this->actingAs($editor)->patchJson(route('notes.update', $note), ['title' => 'Changed']);

        $this->actingAs($editor)
            ->get(route('projects.notes.index', $project))
            ->assertInertia(fn (Assert $page) => $page->where('notes.0.edited_by', 'Ada'));

        // One's own are all one's own, so the list doesn't say
        Note::factory()->for($editor)->create();
        $this->actingAs($editor)
            ->get(route('notes.index'))
            ->assertInertia(fn (Assert $page) => $page->missing('notes.0.edited_by'));
    }

    public function test_a_tables_user_column_offers_the_projects_members()
    {
        $project = Project::factory()->create();
        $ada = User::factory()->create(['name' => 'Ada']);
        $lin = User::factory()->create(['name' => 'Lin']);
        $project->members()->attach($ada, ['role' => Project::OWNER]);
        $project->members()->attach($lin, ['role' => Project::VIEWER]);
        $table = Table::make()->ownedBy($project, $ada);
        $table->save();
        TableStorage::create($table);

        $this->actingAs($lin)
            ->get(route('tables.show', $table))
            ->assertInertia(fn (Assert $page) => $page->where('people', ['Ada', 'Lin']));

        $mine = $this->table($ada);
        $this->actingAs($ada)
            ->get(route('tables.show', $mine))
            ->assertInertia(fn (Assert $page) => $page->where('people', ['Ada']));
    }
}
