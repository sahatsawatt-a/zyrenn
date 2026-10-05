<?php

namespace Tests\Feature;

use App\Models\Table\Table;
use App\Models\Table\TableFolder;
use App\Models\User;
use App\Support\Table\TableStorage;
use Database\Seeders\TableSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Adds a column through the endpoint, as the grid does, and returns what came back.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function addColumn(User $user, Table $table, string $label, string $type, array $extra = []): array
    {
        return $this->actingAs($user)
            ->postJson(route('tables.columns.store', $table), ['label' => $label, 'type' => $type, ...$extra])
            ->assertCreated()
            ->json('column');
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('tables.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_only_the_users_own_tables_with_their_size()
    {
        $user = User::factory()->create();
        $mine = Table::factory()->for($user)->create(['title' => 'Leads']);
        Table::factory()->create(['title' => 'Someone else']);

        $this->addColumn($user, $mine, 'Name', 'varchar');
        $this->actingAs($user)->postJson(route('tables.rows.store', $mine))->assertCreated();

        $this->actingAs($user)
            ->get(route('tables.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tables/Index')
                ->has('tables', 1)
                ->where('tables.0.title', 'Leads')
                ->where('tables.0.columns', 1)
                ->where('tables.0.rows', 1)
                ->etc()
            );
    }

    public function test_a_new_table_comes_with_somewhere_to_keep_its_rows()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tables.store'));

        $table = $user->tables()->sole();
        $response->assertRedirect(route('tables.show', $table));
        $this->assertTrue(Schema::hasTable(TableStorage::physicalName($table)));
        $this->assertSame(['id'], $table->columns()->pluck('name')->all());
        $this->assertTrue($table->columns()->sole()->is_primary);
    }

    public function test_the_table_page_shows_its_columns_and_rows()
    {
        $user = User::factory()->create();
        $table = Table::factory()->for($user)->create();
        $this->addColumn($user, $table, 'Full name', 'varchar');
        $row = $this->actingAs($user)->postJson(route('tables.rows.store', $table))->json('row');
        $this->actingAs($user)->patchJson(route('tables.rows.update', [$table, $row['id']]), [
            'column' => 'full_name',
            'value' => 'Ada Lovelace',
        ])->assertOk();

        $this->actingAs($user)
            ->get(route('tables.show', $table))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tables/Show')
                ->where('columns.1.name', 'full_name')
                ->where('columns.1.label', 'Full name')
                ->where('columns.0.hidden', false)
                ->where('columns.1.hidden', false)
                ->where('rows.0.full_name', 'Ada Lovelace')
                ->etc()
            );
    }

    public function test_a_column_is_named_by_the_server_never_by_the_request()
    {
        $user = User::factory()->create();
        $table = Table::factory()->for($user)->create();

        // Whatever the label says, the name is made from it and checked
        $column = $this->addColumn($user, $table, 'Deal value; DROP TABLE users; --', 'currency');
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $column['name']);
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasColumn(TableStorage::physicalName($table), $column['name']));

        // A second column of the same label gets a name of its own
        $again = $this->addColumn($user, $table, 'Deal value; DROP TABLE users; --', 'currency');
        $this->assertNotSame($column['name'], $again['name']);

        // And a label with nothing usable in it still gets a safe name
        $blank = $this->addColumn($user, $table, '!!!', 'varchar');
        $this->assertMatchesRegularExpression('/^column_/', $blank['name']);
    }

    public function test_a_location_cell_keeps_a_place_however_it_was_given()
    {
        $user = User::factory()->create();
        $table = Table::factory()->for($user)->create();
        $this->addColumn($user, $table, 'Where', 'location');
        $row = $this->actingAs($user)->postJson(route('tables.rows.store', $table))->json('row');
        $this->assertNull($row['where']);

        $write = fn (mixed $value) => $this->actingAs($user)
            ->patchJson(route('tables.rows.update', [$table, $row['id']]), ['column' => 'where', 'value' => $value])
            ->assertOk();
        $read = fn () => TableStorage::row($table->fresh(), $row['id'])['where'];

        // As the map writes it
        $write(['lat' => 13.7462, 'lng' => 100.5347, 'label' => 'Siam Paragon']);
        $this->assertSame(['lat' => 13.7462, 'lng' => 100.5347, 'label' => 'Siam Paragon'], $read());

        // As GeoJSON -- [longitude, latitude], the other way round
        $write(['type' => 'Point', 'coordinates' => [121.4921, 31.2272], 'label' => 'Yu Garden']);
        $this->assertSame(['lat' => 31.2272, 'lng' => 121.4921, 'label' => 'Yu Garden'], $read());

        // As typed
        $write('13.75, 100.5');
        $this->assertSame(['lat' => 13.75, 'lng' => 100.5, 'label' => ''], $read());

        // Nowhere real is no place at all
        $write(['lat' => 123, 'lng' => 0]);
        $this->assertNull($read());
    }

    public function test_cells_are_stored_the_way_their_column_is_kind()
    {
        $user = User::factory()->create();
        $table = Table::factory()->for($user)->create();
        $this->addColumn($user, $table, 'Win', 'percent');
        $this->addColumn($user, $table, 'Active', 'boolean');
        $this->addColumn($user, $table, 'Tags', 'multi_select');
        $this->addColumn($user, $table, 'Due', 'date');
        $row = $this->actingAs($user)->postJson(route('tables.rows.store', $table))->json('row');

        foreach (['win' => '75', 'active' => 'true', 'tags' => ['SaaS', 'Priority'], 'due' => '2026-10-15T09:00:00Z'] as $column => $value) {
            $this->actingAs($user)
                ->patchJson(route('tables.rows.update', [$table, $row['id']]), ['column' => $column, 'value' => $value])
                ->assertOk();
        }

        $stored = TableStorage::row($table->fresh(), $row['id']);
        $this->assertSame(75, $stored['win']);
        $this->assertTrue($stored['active']);
        $this->assertSame(['SaaS', 'Priority'], $stored['tags']);
        $this->assertSame('2026-10-15', $stored['due']);
    }

    public function test_a_cell_can_only_be_written_to_a_column_the_table_has()
    {
        $user = User::factory()->create();
        $table = Table::factory()->for($user)->create();
        $row = $this->actingAs($user)->postJson(route('tables.rows.store', $table))->json('row');

        $this->actingAs($user)
            ->patchJson(route('tables.rows.update', [$table, $row['id']]), ['column' => 'created_at', 'value' => 'x'])
            ->assertJsonValidationErrors('column');

        // Nor to the id, which the database gives out
        $this->actingAs($user)
            ->patchJson(route('tables.rows.update', [$table, $row['id']]), ['column' => 'id', 'value' => 99])
            ->assertJsonValidationErrors('column');
    }

    public function test_rows_can_be_duplicated_and_deleted_in_bulk()
    {
        $user = User::factory()->create();
        $table = Table::factory()->for($user)->create();
        $this->addColumn($user, $table, 'Name', 'varchar');
        $first = $this->actingAs($user)->postJson(route('tables.rows.store', $table))->json('row');
        $this->actingAs($user)->patchJson(route('tables.rows.update', [$table, $first['id']]), [
            'column' => 'name',
            'value' => 'Original',
        ]);

        $copy = $this->actingAs($user)
            ->postJson(route('tables.rows.duplicate', [$table, $first['id']]))
            ->assertCreated()
            ->json('row');

        $this->assertNotSame($first['id'], $copy['id']);
        $this->assertSame('Original', $copy['name']);

        $this->actingAs($user)
            ->deleteJson(route('tables.rows.destroy', $table), ['ids' => [$first['id'], $copy['id']]])
            ->assertJsonPath('deleted', 2);

        $this->assertSame(0, TableStorage::count($table));
    }

    public function test_a_column_changes_kind_only_to_one_stored_the_same_way()
    {
        $user = User::factory()->create();
        $table = Table::factory()->for($user)->create();
        $this->addColumn($user, $table, 'Contact', 'varchar');

        $this->actingAs($user)
            ->patchJson(route('tables.columns.update', [$table, 'contact']), ['type' => 'email', 'label' => 'Email'])
            ->assertOk()
            ->assertJsonPath('column.type', 'email')
            ->assertJsonPath('column.label', 'Email');

        $this->actingAs($user)
            ->patchJson(route('tables.columns.update', [$table, 'contact']), ['type' => 'boolean'])
            ->assertJsonValidationErrors('type');
    }

    public function test_a_column_can_be_removed_but_the_id_cannot()
    {
        $user = User::factory()->create();
        $table = Table::factory()->for($user)->create();
        $this->addColumn($user, $table, 'Notes', 'text');

        $this->actingAs($user)
            ->deleteJson(route('tables.columns.destroy', [$table, 'notes']))
            ->assertOk();

        $this->assertFalse(Schema::hasColumn(TableStorage::physicalName($table), 'notes'));

        $this->actingAs($user)
            ->deleteJson(route('tables.columns.destroy', [$table, 'id']))
            ->assertJsonValidationErrors('column');
    }

    public function test_a_column_is_only_found_within_its_own_table()
    {
        $user = User::factory()->create();
        $one = Table::factory()->for($user)->create();
        $two = Table::factory()->for($user)->create();
        $this->addColumn($user, $one, 'Owner', 'varchar');

        $this->actingAs($user)
            ->deleteJson(route('tables.columns.destroy', [$two, 'owner']))
            ->assertNotFound();
    }

    public function test_the_table_can_be_renamed_redrawn_and_moved()
    {
        $user = User::factory()->create();
        $table = Table::factory()->for($user)->create();
        $folder = TableFolder::factory()->for($user)->create();

        $this->actingAs($user)
            ->patchJson(route('tables.update', $table), [
                'title' => 'Pipeline',
                'density' => 'compact',
                'folder' => $folder->ref_id,
            ])
            ->assertOk();

        $table->refresh();
        $this->assertSame('Pipeline', $table->title);
        $this->assertSame('compact', $table->density);
        $this->assertSame($folder->id, $table->folder_id);
    }

    public function test_deleting_a_table_takes_its_rows_with_it()
    {
        $user = User::factory()->create();
        $table = Table::factory()->for($user)->create();
        $storage = TableStorage::physicalName($table);

        $this->actingAs($user)
            ->delete(route('tables.destroy', $table))
            ->assertRedirect(route('tables.index'));

        $this->assertFalse(Schema::hasTable($storage));
        $this->assertSame(0, $user->tables()->count());
    }

    public function test_deleting_a_folder_takes_every_table_in_it_with_its_rows()
    {
        $user = User::factory()->create();
        $parent = TableFolder::factory()->for($user)->create();
        $child = TableFolder::factory()->for($user)->create(['parent_id' => $parent->id]);
        $table = Table::factory()->for($user)->create();
        $table->folder_id = $child->id;
        $table->save();
        $storage = TableStorage::physicalName($table);

        $this->actingAs($user)->delete(route('table-folders.destroy', $parent))->assertRedirect();

        $this->assertFalse(Schema::hasTable($storage));
        $this->assertDatabaseCount('tables', 0);
        $this->assertDatabaseCount('table_folders', 0);
    }

    public function test_users_cannot_reach_another_users_table()
    {
        $user = User::factory()->create();
        $theirs = Table::factory()->create();
        DB::table(TableStorage::physicalName($theirs))->insert(['created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->get(route('tables.show', $theirs))->assertForbidden();
        $this->actingAs($user)->patchJson(route('tables.update', $theirs), ['title' => 'Mine'])->assertForbidden();
        $this->actingAs($user)->delete(route('tables.destroy', $theirs))->assertForbidden();
        $this->actingAs($user)->postJson(route('tables.rows.store', $theirs))->assertForbidden();
        $this->actingAs($user)->postJson(route('tables.columns.store', $theirs), ['label' => 'x', 'type' => 'text'])->assertForbidden();
        $this->actingAs($user)->deleteJson(route('tables.rows.destroy', $theirs), ['ids' => [1]])->assertForbidden();

        $this->assertSame(1, TableStorage::count($theirs));
    }

    public function test_the_sample_table_is_seeded_the_way_the_app_makes_one()
    {
        $user = User::factory()->create(['email' => 'test@example.com']);

        $this->seed(TableSystemSeeder::class);

        $table = $user->tables()->sole();
        $rows = TableStorage::rows($table);

        $this->assertNotNull($table->folder_id);
        $this->assertSame('id', $table->columns->first()->name);
        $this->assertCount(10, $table->columns);
        $this->assertCount(5, $rows);
        $this->assertSame('Alex Mercer', $rows[0]['full_name']);
        $this->assertSame(['Enterprise', 'Priority'], $rows[0]['tags']);
    }
}
