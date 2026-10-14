<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Models\Board\Board;
use App\Models\User;
use App\Support\Board\BoardItems;
use App\Support\Board\BoardRows;
use App\Support\Board\LabelFit;
use App\Support\Board\Shapes;
use App\Support\Board\Templates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * The shape library, the shapes with a structure inside them, and the
 * templates, as a client draws them. The canvas reads the same lists
 * (resources/js/features/boards/lib) and lays rows out by the same sums: its
 * own tests (composables/shapes.test.ts) hold the numbers these do.
 */
class McpBoardShapesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<string, mixed>>
     */
    private function stored(Board $board): array
    {
        return array_column($board->refresh()->content['items'], null, 'id');
    }

    public function test_a_shape_from_the_library_starts_as_the_library_says()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, [
                'title' => 'Shapes',
                'items' => [
                    ['id' => 'love', 'kind' => 'heart', 'text' => 'Thanks'],
                    ['id' => 'who', 'kind' => 'actor'],
                    ['id' => 'wait', 'kind' => 'delay', 'fill' => '#dbeafe'],
                    ['kind' => 'arrow', 'from' => ['item' => 'who'], 'to' => ['item' => 'wait'], 'endHead' => 'triangle', 'startHead' => 'zero_many'],
                ],
            ])
            ->assertOk();

        $items = $this->stored($user->boards()->sole());

        $this->assertEquals([180, 170, '#fecdd3'], [$items['love']['width'], $items['love']['height'], $items['love']['fill']]);
        // A figure comes with its name, written under it
        $this->assertSame('Actor', $items['who']['text']);
        $this->assertGreaterThanOrEqual($items['who']['height'], LabelFit::box($items['who'])['y']);
        $this->assertSame('#dbeafe', $items['wait']['fill']);

        $line = collect($items)->firstWhere('kind', 'arrow');
        $this->assertSame(['zero_many', 'triangle'], [$line['startHead'], $line['endHead']]);
        // A connector may pin itself to any of them
        $this->assertSame(['who', 'wait'], [$line['from']['item'], $line['to']['item']]);
    }

    public function test_every_shape_in_the_library_can_be_drawn_and_pinned_to()
    {
        $this->assertGreaterThan(40, count(Shapes::kinds()));

        foreach (Shapes::all() as $kind => $shape) {
            $this->assertStringStartsWith('M', $shape['path'], $kind);
            $this->assertContains($kind, BoardItems::kinds());
            $this->assertContains($kind, BoardItems::connectable());
        }
    }

    public function test_a_table_holds_its_columns_as_rows_and_is_as_tall_as_they_need()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, [
                'title' => 'Schema',
                'items' => [
                    ['id' => 'customers', 'kind' => 'table', 'text' => 'customers', 'x' => 0, 'y' => 0, 'fontSize' => 13, 'rows' => [
                        ['name' => 'id', 'type' => 'int', 'key' => 'pk'],
                        ['name' => 'email', 'type' => 'varchar', 'key' => 'uk'],
                    ]],
                    ['id' => 'orders', 'kind' => 'table', 'text' => 'orders', 'x' => 400, 'y' => 50, 'fontSize' => 13, 'rows' => [
                        ['name' => 'id', 'type' => 'int', 'key' => 'pk'],
                        ['name' => 'customer_id', 'type' => 'int', 'key' => 'fk'],
                        ['name' => 'note', 'type' => 'text', 'nullable' => true],
                    ]],
                    ['id' => 'fk', 'kind' => 'arrow', 'from' => ['item' => 'orders', 'pin' => 'customer_id'], 'to' => ['item' => 'customers', 'pin' => 'ID'],
                        'startHead' => 'zero_many', 'endHead' => 'one_only'],
                ],
            ])
            ->assertOk();

        $board = $user->boards()->sole();
        $items = $this->stored($board);

        $this->assertSame(
            [['id', 'id', 'int', 'pk', false], ['customer_id', 'customer_id', 'int', 'fk', false], ['note', 'note', 'text', '', true]],
            array_map(fn (array $row) => [$row['id'], $row['name'], $row['type'], $row['mark'], $row['nullable']], $items['orders']['rows']),
        );
        // The numbers the canvas lays rows out by (shapes.test.ts)
        $this->assertEquals(109, $items['orders']['height']);
        $this->assertEquals(109, BoardRows::height($items['orders']));

        // The line holds on to the rows it names: out of the left of orders at
        // its second row, into the right of customers at its first
        $this->assertEquals(['orders', 'customer_id', 400, 50 + 68.5], [$items['fk']['from']['item'], $items['fk']['from']['pin'], $items['fk']['from']['x'], $items['fk']['from']['y']]);
        $this->assertEquals(['customers', 'id', 240, 45.5], [$items['fk']['to']['item'], $items['fk']['to']['pin'], $items['fk']['to']['x'], $items['fk']['to']['y']]);

        // Column names are found by a search of the board
        $this->assertStringContainsString('customer_id', (string) $board->plain_text);

        // ...and read back a row at a time
        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items.1.rows.1', ['id' => 'customer_id', 'name' => 'customer_id', 'type' => 'int', 'key' => 'fk'])
                ->where('items.1.rows.2', ['id' => 'note', 'name' => 'note', 'type' => 'text', 'nullable' => true])
                ->where('items.2.from', ['item' => 'orders', 'pin' => 'customer_id'])
                ->etc());
    }

    public function test_rows_sent_again_replace_the_list_and_keep_their_ids_by_name()
    {
        $user = User::factory()->create();
        $server = UserServer::actingAs($user);

        $server->tool(CreateBoard::class, ['title' => 'Schema', 'items' => [
            ['id' => 't', 'kind' => 'table', 'text' => 'users', 'rows' => [
                ['id' => 'key', 'name' => 'id', 'type' => 'int', 'key' => 'pk'],
                ['name' => 'name', 'type' => 'varchar'],
            ]],
        ]])->assertOk();

        $board = $user->boards()->sole();

        $server->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [
            ['id' => 't', 'rows' => [
                ['name' => 'name', 'type' => 'text'],
                ['name' => 'id', 'type' => 'bigint', 'key' => 'pk'],
                ['name' => 'Joined at', 'type' => 'timestamp'],
            ]],
        ]])->assertOk();

        $table = $this->stored($board)['t'];

        $this->assertSame(
            [['name', 'text'], ['key', 'bigint'], ['joined_at', 'timestamp']],
            array_map(fn (array $row) => [$row['id'], $row['type']], $table['rows']),
        );
        $this->assertEquals(BoardRows::height($table), $table['height']);
    }

    public function test_a_class_lists_its_fields_then_its_methods()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Classes', 'items' => [
                ['id' => 'shape', 'kind' => 'class', 'text' => 'Shape', 'x' => 0, 'y' => 0, 'fontSize' => 13, 'rows' => [
                    ['name' => 'x', 'type' => 'number'],
                    ['name' => 'area()', 'type' => 'number'],
                    ['name' => 'y', 'type' => 'number', 'visibility' => '-'],
                ]],
                ['id' => 'plain', 'kind' => 'class'],
            ]])
            ->assertOk();

        $items = $this->stored($user->boards()->sole());

        $this->assertSame(
            [['+', false], ['+', true], ['-', false]],
            array_map(fn (array $row) => [$row['mark'], $row['method']], $items['shape']['rows']),
        );
        // The canvas's numbers again: the method is drawn under the rule
        $this->assertEquals(117, $items['shape']['height']);
        $this->assertEquals(88 + 11.5, BoardRows::anchor($items['shape'], $items['shape']['rows'][1], 'left')['y']);

        // One drawn with nothing said starts with the library's rows
        $this->assertSame(['field', 'method()'], array_column($items['plain']['rows'], 'name'));
    }

    public function test_a_row_that_is_not_there_is_refused_with_the_rows_that_are()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Schema', 'items' => [
                ['id' => 'a', 'kind' => 'table', 'rows' => [['name' => 'id'], ['name' => 'email']]],
                ['id' => 'b', 'kind' => 'rect'],
                ['kind' => 'arrow', 'from' => ['item' => 'a', 'pin' => 'phone'], 'to' => ['item' => 'b']],
            ]])
            ->assertHasErrors(['names row "phone" of "a", whose rows are id, email']);

        $this->assertSame(0, $user->boards()->count());
    }

    public function test_a_message_keeps_its_place_along_a_lifeline()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Sequence', 'items' => [
                ['id' => 'a', 'kind' => 'lifeline', 'text' => 'Browser', 'x' => 0, 'y' => 0, 'width' => 160, 'height' => 400],
                ['id' => 'b', 'kind' => 'lifeline', 'text' => 'Server', 'x' => 300, 'y' => 0, 'width' => 160, 'height' => 400],
                ['id' => 'ask', 'kind' => 'arrow', 'text' => 'GET /', 'from' => ['item' => 'a', 'along' => 0.25], 'to' => ['item' => 'b', 'along' => 0.25]],
            ]])
            ->assertOk();

        $ask = $this->stored($user->boards()->sole())['ask'];

        // On the line down the middle of each, a quarter of the way down
        $this->assertEquals([0.25, 80, 100], [$ask['from']['along'], $ask['from']['x'], $ask['from']['y']]);
        $this->assertEquals([0.25, 380, 100], [$ask['to']['along'], $ask['to']['x'], $ask['to']['y']]);
    }

    public function test_a_lane_has_its_title_along_its_top_or_down_its_side()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Lanes', 'items' => [
                ['id' => 'top', 'kind' => 'lane', 'text' => 'To do'],
                ['id' => 'side', 'kind' => 'lane', 'text' => 'Customer', 'variant' => 'left', 'width' => 900, 'height' => 160],
            ]])
            ->assertOk();

        $items = $this->stored($user->boards()->sole());

        $this->assertArrayNotHasKey('variant', $items['top']);
        $this->assertSame('left', $items['side']['variant']);
        $this->assertEquals(['x' => 0, 'y' => 0, 'width' => 34, 'height' => 160], LabelFit::box($items['side']));
        $this->assertEquals(34, LabelFit::box($items['top'])['height']);
    }

    public function test_a_rule_in_a_rich_label_takes_a_line_of_its_own()
    {
        $with = ['kind' => 'rect', 'width' => 200, 'height' => 140, 'fontSize' => 16, 'rich' => true, 'text' => "**Name**\n---\nmore"];
        $without = [...$with, 'text' => "**Name**\nmore"];

        $this->assertEqualsWithDelta(16 * 0.7, LabelFit::needed($with) - LabelFit::needed($without), 0.01);
    }

    public function test_a_board_can_be_started_from_a_template()
    {
        $user = User::factory()->create();

        foreach (Templates::ids() as $template) {
            UserServer::actingAs($user)
                ->tool(CreateBoard::class, ['title' => $template, 'template' => $template])
                ->assertOk();

            $items = $user->boards()->where('title', $template)->sole()->content['items'];

            $this->assertCount(count(Templates::specs($template)), $items, $template);
        }

        // The data model's foreign keys are drawn column to column
        $model = $this->stored($user->boards()->where('title', 'data-model')->sole());
        $key = collect($model)->first(fn (array $item) => $item['kind'] === 'arrow' && $item['from']['item'] === 'orders');

        $this->assertSame(['customer_id', 'id'], [$key['from']['pin'], $key['to']['pin']]);

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'None', 'template' => 'no-such-thing'])
            ->assertHasErrors();
    }
}
