<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\ListBoards;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Models\Board\Board;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Table\TableStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * A prototype's links over MCP: where tapping an item goes in Play -- another
 * screen (a frame), by its id or title, or back -- set, read back, and taken
 * off with the screen it went to. And the board tools reach a prototype.
 */
class McpBoardLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** Two screens, a button on the first. */
    private function prototype(): Board
    {
        $board = Board::factory()->for($this->user)->create([
            'title' => 'Shop',
            'content' => ['items' => [
                ['id' => 'home', 'kind' => 'frame', 'text' => 'Home', 'x' => 0, 'y' => 0, 'width' => 390, 'height' => 844],
                ['id' => 'cart', 'kind' => 'frame', 'text' => 'Cart', 'x' => 550, 'y' => 0, 'width' => 390, 'height' => 844],
                ['id' => 'buy', 'kind' => 'button', 'text' => 'Buy', 'x' => 16, 'y' => 700, 'width' => 358, 'height' => 48],
            ]],
        ]);
        $board->forceFill(['kind' => Board::PROTOTYPE, 'prototype' => ['device' => 'phone', 'start' => 'home']])->save();

        return $board;
    }

    /** @return array<string, array<string, mixed>> */
    private function items(Board $board): array
    {
        return collect($board->fresh()->content['items'])->keyBy('id')->all();
    }

    public function test_an_item_goes_to_a_screen_named_by_its_title_and_reads_back_by_its_id(): void
    {
        $board = $this->prototype();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'buy', 'link' => ['to' => 'cart']]]])
            ->assertOk();
        $this->assertSame(['to' => 'cart'], $this->items($board)['buy']['link']);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'back', 'kind' => 'text', 'text' => 'Back', 'x' => 560, 'y' => 60, 'link' => ['back' => true]],
                ['id' => 'logo', 'kind' => 'text', 'text' => 'Shop', 'x' => 16, 'y' => 60, 'link' => ['to' => 'home']],
            ]])
            ->assertOk();

        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id, 'outline' => false])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items', fn ($items) => collect($items)->keyBy('id')->map(fn ($item) => $item['link'] ?? null)->only(['buy', 'back', 'logo', 'home'])->all() === [
                    'home' => null,
                    'buy' => ['to' => 'cart'],
                    'back' => ['back' => true],
                    'logo' => ['to' => 'home'],
                ])
                ->etc());
    }

    public function test_a_screen_by_its_title_is_found_whatever_its_case(): void
    {
        $board = $this->prototype();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'buy', 'link' => ['to' => 'CART']]]])
            ->assertOk();

        $this->assertSame(['to' => 'cart'], $this->items($board)['buy']['link']);
    }

    public function test_a_link_to_no_screen_or_from_a_screen_is_refused_and_the_board_left_alone(): void
    {
        $board = $this->prototype();
        $before = $board->content;

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'buy', 'link' => ['to' => 'Checkout']]]])
            ->assertHasErrors(['"buy" goes to "Checkout", but no frame (screen) has that id or title. get-board lists the frames.']);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'home', 'link' => ['to' => 'cart']]]])
            ->assertHasErrors(['"home" is a frame: only what is on a screen can go somewhere.']);

        $this->assertEquals($before, $board->fresh()->content);
    }

    public function test_null_takes_a_link_off_and_deleting_its_screen_does_too(): void
    {
        $board = $this->prototype();
        $tool = fn (array $change) => UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, ...$change])
            ->assertOk();

        $tool(['update_items' => [['id' => 'buy', 'link' => ['to' => 'cart']]]]);
        $tool(['update_items' => [['id' => 'buy', 'link' => null]]]);
        $this->assertArrayNotHasKey('link', $this->items($board)['buy']);

        $tool(['update_items' => [['id' => 'buy', 'link' => ['to' => 'cart']]]]);
        $tool(['update_items' => [['id' => 'buy', 'text' => 'Buy now']]]);
        // Changing something else keeps where it goes
        $this->assertSame(['to' => 'cart'], $this->items($board)['buy']['link']);

        $tool(['delete_items' => ['cart']]);
        $this->assertArrayNotHasKey('link', $this->items($board)['buy']);
    }

    public function test_a_new_board_can_have_its_links_set_as_it_is_made(): void
    {
        UserServer::actingAs($this->user)
            ->tool(CreateBoard::class, ['title' => 'Flow', 'items' => [
                ['id' => 'one', 'kind' => 'frame', 'text' => 'One'],
                ['id' => 'two', 'kind' => 'frame', 'text' => 'Two'],
                ['id' => 'next', 'kind' => 'button', 'text' => 'Next', 'link' => ['to' => 'Two']],
            ]])
            ->assertOk();

        $items = collect(Board::query()->where('title', 'Flow')->sole()->content['items'])->keyBy('id');
        $this->assertSame(['to' => 'two'], $items['next']['link']);
    }

    public function test_every_kind_of_action_and_trigger_is_kept_with_screens_found_by_title(): void
    {
        $board = $this->prototype();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'menu', 'kind' => 'button', 'text' => 'Menu', 'x' => 16, 'y' => 60, 'startsHidden' => true, 'fixed' => 'top',
                    'link' => ['to' => 'Cart', 'overlay' => 'bottom', 'transition' => 'up'],
                    'when' => [
                        'long-press' => ['toggle' => ['buy']],
                        'swipe-left' => ['all' => [['set' => 'seen', 'value' => true], ['back' => true, 'transition' => 'fade']]],
                        'hover' => ['if' => 'seen', 'then' => ['show' => ['buy']], 'else' => ['hide' => ['buy']]],
                        'tap' => ['close' => true],
                    ]],
                ['id' => 'name', 'kind' => 'text_field', 'text' => 'Name', 'x' => 16, 'y' => 120, 'field' => 'name'],
                ['id' => 'small', 'kind' => 'radio', 'text' => 'Small', 'x' => 16, 'y' => 200, 'choice' => 'size'],
            ], 'update_items' => [['id' => 'cart', 'screen' => ['after' => ['seconds' => 2, 'do' => ['to' => 'home', 'transition' => 'fade']]]]]])
            ->assertOk();

        $items = $this->items($board);

        $this->assertSame(['to' => 'cart', 'transition' => 'up', 'overlay' => 'bottom'], $items['menu']['link']);
        // An unknown trigger ("tap" is the link) is left out
        $this->assertSame([
            'long-press' => ['toggle' => ['buy']],
            'hover' => ['if' => 'seen', 'then' => ['show' => ['buy']], 'else' => ['hide' => ['buy']]],
            'swipe-left' => ['all' => [['set' => 'seen', 'value' => true], ['back' => true, 'transition' => 'fade']]],
        ], $items['menu']['when']);
        $this->assertTrue($items['menu']['startsHidden']);
        $this->assertSame('top', $items['menu']['fixed']);
        $this->assertSame('name', $items['name']['field']);
        $this->assertSame('size', $items['small']['choice']);
        // (a whole number of seconds comes back from the database as one)
        $this->assertEquals(['after' => ['seconds' => 2, 'do' => ['to' => 'home', 'transition' => 'fade']]], $items['cart']['screen']);

        // ...and get-board says them back as they are kept
        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id, 'outline' => false])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items', fn ($items) => ($menu = collect($items)->firstWhere('id', 'menu'))
                    && $menu['when']['long-press'] === ['toggle' => ['buy']]
                    && $menu['startsHidden'] === true
                    && collect($items)->firstWhere('id', 'cart')['screen']['after']['seconds'] == 2)
                ->etc());
    }

    public function test_what_an_action_names_must_be_there_and_goes_with_it_later(): void
    {
        $board = $this->prototype();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'buy', 'when' => ['long-press' => ['show' => ['nothere']]]]]])
            ->assertHasErrors(['"buy" names "nothere" to show, hide or flip, but no item has that id. get-board lists the items.']);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'buy', 'field' => 'a/b']]])
            ->assertHasErrors(['"buy": a field is named in words, digits, spaces, - and _, up to 40 -- not "a/b".']);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'tip', 'kind' => 'text', 'text' => 'Tip', 'x' => 16, 'y' => 300],
                ['id' => 'help', 'kind' => 'text', 'text' => 'Help', 'x' => 16, 'y' => 340],
            ], 'update_items' => [['id' => 'buy', 'link' => ['all' => [['toggle' => ['tip', 'help']], ['to' => 'cart']]]]]])
            ->assertOk();

        // One of the two it flips goes: it flips the other
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'delete_items' => ['tip']])
            ->assertOk();
        $this->assertSame(['all' => [['toggle' => ['help']], ['to' => 'cart']]], $this->items($board)['buy']['link']);

        // The screen goes too: what is left is the flip
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'delete_items' => ['cart']])
            ->assertOk();
        $this->assertSame(['all' => [['toggle' => ['help']]]], $this->items($board)['buy']['link']);
    }

    /** A table of the user's own, with its columns. */
    private function table(string $title, array $columns): Table
    {
        $table = Table::factory()->for($this->user)->create(['title' => $title]);

        foreach ($columns as $label) {
            TableStorage::newColumn($table, ['label' => $label, 'type' => 'varchar']);
        }

        return $table;
    }

    public function test_a_row_card_and_saving_name_a_table_by_title_and_columns_by_label(): void
    {
        $board = $this->prototype();
        $tickets = $this->table('Tickets', ['Title', 'Status']);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'row', 'kind' => 'ui_card', 'x' => 16, 'y' => 200, 'text' => '{Title}', 'repeat' => [
                    'table' => 'tickets', 'where' => [['column' => 'status', 'is' => 'Open']], 'sort' => ['column' => 'TITLE', 'desc' => true], 'limit' => 120, 'gap' => 8,
                ], 'link' => ['to' => 'cart']],
                ['id' => 'add', 'kind' => 'button', 'x' => 16, 'y' => 600, 'text' => 'Add',
                    'link' => ['all' => [['save' => 'Tickets', 'values' => ['title' => '{name}', 'status' => 'Open']], ['back' => true]]]],
                ['id' => 'drop', 'kind' => 'button', 'x' => 16, 'y' => 650, 'text' => 'Delete', 'when' => ['long-press' => ['remove' => $tickets->ref_id]]],
            ]])
            ->assertOk();

        $items = $this->items($board);

        // (a whole number of pixels comes back from the database as one)
        $this->assertEquals([
            'table' => $tickets->ref_id,
            'where' => [['column' => 'Status', 'is' => 'Open']],
            'sort' => ['column' => 'Title', 'desc' => true],
            'limit' => 120,
            'gap' => 8,
        ], $items['row']['repeat']);
        $this->assertSame(['save' => $tickets->ref_id, 'values' => ['Title' => '{name}', 'Status' => 'Open']], $items['add']['link']['all'][0]);
        $this->assertSame(['remove' => $tickets->ref_id], $items['drop']['when']['long-press']);
    }

    public function test_a_table_or_a_column_that_is_not_there_is_said_so(): void
    {
        $board = $this->prototype();
        $this->table('Tickets', ['Title', 'Status']);
        $update = fn (array $item) => UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'buy', ...$item]]]);

        $update(['repeat' => ['table' => 'Orders']])
            ->assertHasErrors(['"buy" uses the table "Orders", but this project has no table with that ref_id or title. list-tables lists them.']);
        $update(['link' => ['save' => 'Tickets', 'values' => ['Owner' => 'Ada']]])
            ->assertHasErrors(['"buy": the table "Tickets" has no column "Owner". Its columns are: ID, Title, Status.']);
        $update(['repeat' => ['table' => 'Tickets']])->assertOk();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'home', 'repeat' => ['table' => 'Tickets']]]])
            ->assertHasErrors(['"home" is a frame: only a card on a screen can repeat for each row.']);
    }

    public function test_a_table_gone_takes_its_repeat_and_its_saving_with_it(): void
    {
        $board = $this->prototype();
        $tickets = $this->table('Tickets', ['Title']);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'buy',
                'repeat' => ['table' => 'Tickets'], 'link' => ['all' => [['save' => 'Tickets'], ['to' => 'cart']]]]]])
            ->assertOk();

        $tickets->delete();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'buy', 'text' => 'Buy now']]])
            ->assertOk();

        $buy = $this->items($board)['buy'];
        $this->assertArrayNotHasKey('repeat', $buy);
        $this->assertSame(['all' => [['to' => 'cart']]], $buy['link']);
    }

    public function test_the_board_tools_reach_a_prototype_and_say_it_is_one(): void
    {
        $board = $this->prototype();
        Board::factory()->for($this->user)->create(['title' => 'Plain']);

        UserServer::actingAs($this->user)
            ->tool(ListBoards::class, [])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('boards', fn ($boards) => collect($boards)->keyBy('title')->map(fn ($found) => $found['kind'] ?? 'board')->all() === [
                    'Plain' => 'board',
                    'Shop' => 'prototype',
                ] || collect($boards)->keyBy('title')->map(fn ($found) => $found['kind'] ?? 'board')->sortKeys()->all() === [
                    'Plain' => 'board',
                    'Shop' => 'prototype',
                ])
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(ListBoards::class, [])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('boards', fn ($boards) => collect($boards)->firstWhere('title', 'Shop')['url'] === route('prototypes.show', $board))
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertOk();
    }
}
