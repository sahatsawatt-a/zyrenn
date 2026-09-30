<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Tables\CreateTable;
use App\Mcp\Tools\Tables\GetTable;
use App\Mcp\Tools\Tables\UpdateTable;
use App\Models\Table\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Reading a table a page at a time, with a search and the columns wanted, and
 * hearing back only what a change made -- so a big table costs what is read.
 */
class McpTablePagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        UserServer::actingAs($this->user)->tool(CreateTable::class, [
            'title' => 'People',
            'columns' => [
                ['label' => 'Name'],
                ['label' => 'Team', 'type' => 'select'],
                ['label' => 'Age', 'type' => 'integer'],
            ],
            'rows' => array_map(fn ($n) => ['Name' => "Person {$n}", 'Team' => $n % 2 ? 'Red' : 'Blue', 'Age' => 20 + $n], range(1, 25)),
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json->missing('rows')->etc());

        $this->table = $this->user->tables()->sole();
    }

    public function test_rows_come_a_page_at_a_time_and_say_where_the_next_starts()
    {
        UserServer::actingAs($this->user)
            ->tool(GetTable::class, ['ref_id' => $this->table->ref_id, 'limit' => 10, 'offset' => 10])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('rows', 10)
                ->where('rows.0.name', 'Person 11')
                ->where('total', 25)
                ->where('next_offset', 20)
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(GetTable::class, ['ref_id' => $this->table->ref_id, 'limit' => 10, 'offset' => 20])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('rows', 5)
                ->missing('next_offset')
                ->etc());
    }

    public function test_a_search_keeps_the_rows_whose_text_holds_it()
    {
        UserServer::actingAs($this->user)
            ->tool(GetTable::class, ['ref_id' => $this->table->ref_id, 'search' => 'person 1'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                // Person 1, and 10 to 19
                ->where('total', 11)
                ->has('rows', 11)
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(GetTable::class, ['ref_id' => $this->table->ref_id, 'search' => 'blue'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total', 12)->etc());
    }

    public function test_only_the_columns_asked_for_come_by_label_or_name()
    {
        UserServer::actingAs($this->user)
            ->tool(GetTable::class, ['ref_id' => $this->table->ref_id, 'columns' => ['age'], 'limit' => 1])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('rows.0', fn ($row) => array_keys($row->all()) === ['id', 'age'])
                ->where('columns', fn ($columns) => collect($columns)->pluck('name')->all() === ['id', 'age'])
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(GetTable::class, ['ref_id' => $this->table->ref_id, 'columns' => ['Salary']])
            ->assertHasErrors(['This table has no column "Salary". Its columns are: Name, Team, Age.']);
    }

    public function test_a_change_answers_with_the_new_rows_ids_not_the_table()
    {
        $first = UserServer::actingAs($this->user)
            ->tool(GetTable::class, ['ref_id' => $this->table->ref_id, 'limit' => 1]);
        $firstId = null;
        $first->assertStructuredContent(function (AssertableJson $json) use (&$firstId) {
            $firstId = $json->toArray()['rows'][0]['id'];

            return $json->etc();
        });

        UserServer::actingAs($this->user)
            ->tool(UpdateTable::class, [
                'ref_id' => $this->table->ref_id,
                'add_rows' => [['Name' => 'Ada'], ['Name' => 'Lin']],
                'update_rows' => [['id' => $firstId, 'values' => ['Age' => 99]]],
                'delete_rows' => [$firstId + 1],
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('added_rows', fn ($ids) => count($ids) === 2)
                ->where('updated_rows', 1)
                ->where('deleted_rows', 1)
                ->missing('rows')
                ->etc());
    }
}
