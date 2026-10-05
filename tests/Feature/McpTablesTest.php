<?php

namespace Tests\Feature;

use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Tables\CreateTable;
use App\Mcp\Tools\Tables\DeleteTable;
use App\Mcp\Tools\Tables\GetTable;
use App\Mcp\Tools\Tables\ListTableFolders;
use App\Mcp\Tools\Tables\ListTables;
use App\Mcp\Tools\Tables\UpdateTable;
use App\Models\Map\Place;
use App\Models\Map\PlaceList;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Table\TableStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Each of these fires a table tool and checks that what was asked for is there.
 */
class McpTablesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A table made through create-table, as a caller would make one.
     */
    private function budget(User $user): Table
    {
        UserServer::actingAs($user)->tool(CreateTable::class, [
            'title' => 'Budget',
            'folder' => 'Finance/2026',
            'columns' => [
                ['label' => 'Owner'],
                ['label' => 'Amount', 'type' => 'integer'],
                ['label' => 'Status', 'type' => 'select', 'choices' => ['Lead']],
            ],
            'rows' => [
                ['Owner' => 'Ada', 'Amount' => 300, 'Status' => 'Won'],
                ['owner' => 'Grace', 'amount' => '120'],
            ],
        ])->assertOk();

        return $user->tables()->sole();
    }

    public function test_create_table_keeps_the_columns_and_rows_it_was_given()
    {
        $user = User::factory()->create();
        $table = $this->budget($user);

        $rows = TableStorage::rows($table);

        $this->assertSame('Budget', $table->title);
        $this->assertSame(['id', 'owner', 'amount', 'status'], $table->columns->pluck('name')->all());
        $this->assertSame(['Ada', 300, 'Won'], [$rows[0]['owner'], $rows[0]['amount'], $rows[0]['status']]);
        $this->assertSame(['Grace', 120], [$rows[1]['owner'], $rows[1]['amount']]);
        // A choice written that the select didn't offer is added to it
        $this->assertSame(['Lead', 'Won'], array_column($table->columns[3]->toGrid()['options'], 'value'));
    }

    public function test_update_table_adds_changes_and_deletes_rows()
    {
        $user = User::factory()->create();
        $table = $this->budget($user);
        [$ada, $grace] = TableStorage::rows($table);

        UserServer::actingAs($user)->tool(UpdateTable::class, [
            'ref_id' => $table->ref_id,
            'title' => 'Budget 2026',
            'add_columns' => [['label' => 'Tags', 'type' => 'multi_select']],
            'add_rows' => [['Owner' => 'Linus', 'Tags' => ['New']]],
            'update_rows' => [['id' => $ada['id'], 'values' => ['Amount' => 450]]],
            'delete_rows' => [$grace['id']],
        ])->assertOk();

        $rows = TableStorage::rows($table->refresh());

        $this->assertSame('Budget 2026', $table->title);
        $this->assertSame(['Ada', 'Linus'], array_column($rows, 'owner'));
        $this->assertSame(450, $rows[0]['amount']);
        $this->assertSame(['New'], $rows[1]['tags']);
    }

    public function test_a_location_column_takes_a_place_however_it_is_written()
    {
        $user = User::factory()->create();
        $table = $this->budget($user);

        UserServer::actingAs($user)->tool(UpdateTable::class, [
            'ref_id' => $table->ref_id,
            'add_columns' => [['label' => 'Where', 'type' => 'location']],
            'add_rows' => [
                ['Owner' => 'Linus', 'Where' => ['lat' => 31.2397, 'lng' => 121.4906, 'label' => 'The Bund']],
                ['Owner' => 'Ken', 'Where' => '13.7563, 100.5018'],
            ],
        ])->assertOk();

        $rows = TableStorage::rows($table->refresh());

        $this->assertEquals(['lat' => 31.2397, 'lng' => 121.4906, 'label' => 'The Bund'], $rows[2]['where']);
        $this->assertEquals([13.7563, 100.5018], [$rows[3]['where']['lat'], $rows[3]['where']['lng']]);
        $this->assertNull($rows[0]['where']);
    }

    public function test_a_location_can_be_a_saved_place_and_follows_it()
    {
        $user = User::factory()->create();
        $table = $this->budget($user);
        $place = Place::factory()->for(PlaceList::factory()->for($user), 'list')->create(['name' => 'Siam Paragon', 'address' => 'Bangkok', 'lat' => 13.7462, 'lng' => 100.5347]);

        UserServer::actingAs($user)->tool(UpdateTable::class, [
            'ref_id' => $table->ref_id,
            'add_columns' => [['label' => 'Where', 'type' => 'location']],
            'add_rows' => [['Owner' => 'Linus', 'Where' => ['place' => $place->ref_id]]],
        ])->assertOk();

        $this->assertEquals(
            ['lat' => 13.7462, 'lng' => 100.5347, 'label' => 'Siam Paragon, Bangkok', 'place' => $place->ref_id],
            TableStorage::rows($table->refresh())[2]['where'],
        );

        // Moved on the map, the cell is where the place now is
        $place->update(['lat' => 13.75, 'name' => 'Siam Paragon Mall']);
        $this->assertSame([13.75, 'Siam Paragon Mall, Bangkok'], [
            TableStorage::rows($table)[2]['where']['lat'],
            TableStorage::rows($table)[2]['where']['label'],
        ]);
    }

    public function test_a_row_for_a_column_the_table_lacks_is_refused_and_nothing_is_kept()
    {
        $user = User::factory()->create();
        $table = $this->budget($user);

        UserServer::actingAs($user)->tool(UpdateTable::class, [
            'ref_id' => $table->ref_id,
            'add_rows' => [['Owner' => 'Linus'], ['Colour' => 'red']],
        ])->assertHasErrors(['This table has no column "Colour". Its columns are: Owner, Amount, Status.']);

        $this->assertCount(2, TableStorage::rows($table));
    }

    public function test_tables_are_listed_read_and_deleted()
    {
        $user = User::factory()->create();
        $table = $this->budget($user);

        UserServer::actingAs($user)->tool(ListTables::class, [])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('tables.0.title', 'Budget')
                ->where('tables.0.folder', 'Finance/2026')
                ->where('tables.0.column_count', 3)
                ->where('tables.0.row_count', 2)
                ->etc());

        UserServer::actingAs($user)->tool(ListTableFolders::class, [])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('folders.1', ['path' => 'Finance/2026', 'tables_count' => 1])
                ->etc());

        UserServer::actingAs($user)->tool(GetTable::class, ['ref_id' => $table->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('columns.3.choices', ['Lead', 'Won'])
                ->where('rows.1.owner', 'Grace')
                ->etc());

        UserServer::actingAs($user)->tool(DeleteTable::class, ['ref_id' => $table->ref_id])
            ->assertSee("Deleted table {$table->ref_id} (\"Budget\").");

        $this->assertFalse(Schema::hasTable(TableStorage::physicalName($table)));
    }

    public function test_a_token_only_reaches_its_own_tables_and_the_admin_server_picks_the_user()
    {
        $owner = User::factory()->create();
        $table = $this->budget($owner);

        UserServer::actingAs(User::factory()->create())
            ->tool(GetTable::class, ['ref_id' => $table->ref_id])
            ->assertHasErrors(["Table {$table->ref_id} was not found."]);

        GlobalServer::tool(ListTables::class, ['user_id' => $owner->id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('tables.0.ref_id', $table->ref_id)
                ->etc());
    }
}
