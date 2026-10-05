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
 * An agent keeping a table that does its own sums: a value used everywhere is
 * set once, as a parameter, and every formula follows it.
 */
class McpTableFormulasTest extends TestCase
{
    use RefreshDatabase;

    public function test_parameters_and_formula_columns_are_set_once_and_every_row_follows()
    {
        $user = User::factory()->create();
        $server = UserServer::actingAs($user);

        $server->tool(CreateTable::class, [
            'title' => 'Shanghai budget',
            'parameters' => ['rate' => 5, 'people' => 2],
            'columns' => [
                ['label' => 'Item'],
                ['label' => 'CNY', 'type' => 'currency', 'summary' => 'sum'],
                ['label' => 'THB', 'type' => 'formula', 'expression' => 'cny * rate', 'summary' => 'sum'],
                ['label' => 'Each', 'type' => 'formula', 'expression' => '[THB] / people'],
            ],
            'rows' => [['Item' => 'Disney', 'CNY' => 475], ['Item' => 'Metro', 'CNY' => 25]],
        ])->assertOk();

        $table = Table::query()->where('title', 'Shanghai budget')->sole();

        $server->tool(GetTable::class, ['ref_id' => $table->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('parameters', ['rate' => 5, 'people' => 2])
                ->where('columns.3.expression', 'cny * rate')
                ->where('rows.0.thb', 2375)
                ->where('rows.0.each', 1187.5)
                ->where('totals', ['cny' => 500, 'thb' => 2500])
                ->etc());

        // The rate moves once; every row and the total follow
        $server->tool(UpdateTable::class, [
            'ref_id' => $table->ref_id,
            'parameters' => ['rate' => 4.9, 'people' => null],
            'update_columns' => [['column' => 'Each', 'expression' => 'round([THB])', 'label' => 'Rounded']],
        ])->assertOk();

        $server->tool(GetTable::class, ['ref_id' => $table->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('parameters', ['rate' => 4.9])
                ->where('columns.4.label', 'Rounded')
                ->where('rows.0.each', 2328)
                ->where('totals.thb', 2450)
                ->etc());
    }

    public function test_what_cant_be_done_with_a_formula_is_said_and_nothing_is_kept()
    {
        $user = User::factory()->create();
        $server = UserServer::actingAs($user);

        $server->tool(CreateTable::class, [
            'title' => 'Broken',
            'columns' => [['label' => 'CNY', 'type' => 'numeric'], ['label' => 'THB', 'type' => 'formula', 'expression' => 'cny * rate']],
        ])->assertHasErrors(['The formula for "THB": Nothing in this table is called "rate": name a column, a [Column label] or a parameter.']);

        $this->assertSame(0, Table::query()->count());

        $server->tool(CreateTable::class, [
            'title' => 'Budget',
            'parameters' => ['rate' => 5],
            'columns' => [['label' => 'CNY', 'type' => 'numeric'], ['label' => 'THB', 'type' => 'formula', 'expression' => 'cny * rate']],
        ])->assertOk();
        $table = Table::query()->sole();

        $server->tool(UpdateTable::class, ['ref_id' => $table->ref_id, 'add_rows' => [['CNY' => 1, 'THB' => 5]]])
            ->assertHasErrors(['"THB" is worked out by its formula; change what it is worked out from instead.']);

        $server->tool(UpdateTable::class, ['ref_id' => $table->ref_id, 'parameters' => ['cny' => 1]])
            ->assertHasErrors(['"cny" is already a column of this table.']);

        $server->tool(UpdateTable::class, ['ref_id' => $table->ref_id, 'update_columns' => [['column' => 'CNY', 'expression' => '1']]])
            ->assertHasErrors(['"CNY" is not a formula column; only those have an expression.']);

        $this->assertSame([['name' => 'rate', 'value' => 5]], $table->fresh()->parameters);
    }
}
