<?php

namespace Tests\Feature;

use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\DeleteBoard;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\ListBoardFolders;
use App\Mcp\Tools\Boards\ListBoards;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Models\Board\Board;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Each of these fires a board tool and checks what came back is what was asked
 * for. How the drawing itself is worked out belongs to the canvas, not here.
 */
class McpBoardsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The items stored on a board, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stored(Board $board): array
    {
        return array_column($board->refresh()->content['items'], null, 'id');
    }

    public function test_a_video_goes_on_a_board_as_a_16_by_9_player()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, [
                'title' => 'Demo',
                'items' => [
                    ['id' => 'clip', 'kind' => 'video', 'src' => '/drive/files/abc'],
                    ['id' => 'note', 'kind' => 'sticky', 'text' => 'Watch this'],
                    ['kind' => 'arrow', 'from' => ['item' => 'note'], 'to' => ['item' => 'clip']],
                ],
            ])
            ->assertOk();

        $items = $this->stored($user->boards()->sole());

        $this->assertSame('/drive/files/abc', $items['clip']['src']);
        $this->assertEquals([480, 270], [$items['clip']['width'], $items['clip']['height']]);
        // A connector can point at it like any other box
        $this->assertSame('clip', collect($items)->firstWhere('kind', 'arrow')['to']['item']);
    }

    public function test_create_board_draws_what_it_was_given()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, [
                'title' => 'Ordering',
                'folder' => 'Plans/Q3',
                'items' => [
                    ['id' => 'start', 'kind' => 'process', 'text' => 'Order placed'],
                    ['id' => 'check', 'kind' => 'diamond', 'text' => 'In stock?', 'align' => 'left'],
                    ['id' => 'sum', 'kind' => 'math', 'text' => 'e^{i\pi} + 1 = 0'],
                    ['kind' => 'arrow', 'from' => ['item' => 'start'], 'to' => ['item' => 'check'], 'text' => 'then'],
                ],
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('title', 'Ordering')
                ->where('folder', 'Plans/Q3')
                ->where('item_count', 4)
                // Which board it made, not the board again: get-board reads it
                ->missing('items')
                ->etc()
            );

        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $user->boards()->sole()->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                // In the order they were sent, which is the order they are drawn in
                ->where('items.0.text', 'Order placed')
                ->where('items.1.align', 'left')
                ->where('items.2.kind', 'math')
                ->where('items.3.from.item', 'start')
                ->where('items.3.to.item', 'check')
                ->etc()
            );

        $items = $this->stored($user->boards()->sole());

        // Nothing was sent with coordinates, so everything was given a place
        $this->assertNotSame(
            [$items['start']['x'], $items['start']['y']],
            [$items['check']['x'], $items['check']['y']],
        );
    }

    public function test_get_board_reads_back_what_was_drawn()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create([
            'content' => ['items' => [
                ['id' => 'pic', 'kind' => 'image', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 200,
                    'text' => 'Floor plan', 'src' => 'data:image/png;base64,'.str_repeat('A', 400)],
            ]],
        ]);

        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('ref_id', $board->ref_id)
                ->where('items.0.text', 'Floor plan')
                ->where('items.0.width', 300)
                // A picture's bytes are not worth reporting; that it has one is
                ->where('items.0.has_picture', true)
                ->missing('items.0.src')
                ->etc()
            );
    }

    public function test_update_board_changes_what_it_names_and_leaves_the_rest()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Ordering',
            'items' => [
                ['id' => 'start', 'kind' => 'process', 'text' => 'Order placed', 'fill' => '#bfdbfe'],
                ['id' => 'check', 'kind' => 'diamond', 'text' => 'In stock?'],
                ['id' => 'line', 'kind' => 'arrow', 'from' => ['item' => 'start'], 'to' => ['item' => 'check']],
            ],
        ])->assertOk();

        $board = $user->boards()->sole();

        UserServer::actingAs($user)
            ->tool(UpdateBoard::class, [
                'ref_id' => $board->ref_id,
                'title' => 'Ordering v2',
                'folder' => 'Archive',
                'items' => [
                    ['id' => 'start', 'kind' => 'process', 'text' => 'Order received'],
                    ['id' => 'check', 'kind' => 'diamond', 'text' => 'In stock?'],
                ],
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('title', 'Ordering v2')
                ->where('folder', 'Archive')
                ->where('item_count', 2)
                ->etc()
            );

        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items.0.text', 'Order received')
                // Not mentioned, so it kept the colour it was given
                ->where('items.0.fill', '#bfdbfe')
                ->etc()
            );

        // The connector was left out of the list, so it is off the board
        $this->assertArrayNotHasKey('line', $this->stored($board));
    }

    public function test_an_end_given_a_side_keeps_it_and_one_without_follows_the_shapes()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Loop back',
            'items' => [
                ['id' => 'a', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 200, 'height' => 100],
                ['id' => 'b', 'kind' => 'rect', 'x' => 400, 'y' => 0, 'width' => 200, 'height' => 100],
                ['id' => 'plain', 'kind' => 'arrow', 'from' => ['item' => 'a'], 'to' => ['item' => 'b']],
                ['id' => 'loop', 'kind' => 'arrow',
                    'from' => ['item' => 'b', 'side' => 'bottom'],
                    'to' => ['item' => 'a', 'side' => 'bottom']],
            ],
        ])->assertOk();

        $items = $this->stored($user->boards()->sole());

        $this->assertNull($items['plain']['from']['side']);
        $this->assertSame('bottom', $items['loop']['from']['side']);
        // The point goes with the face it was pinned to
        $this->assertEquals([500, 100], [$items['loop']['from']['x'], $items['loop']['from']['y']]);
    }

    public function test_boards_are_listed_searched_and_filed_in_folders()
    {
        $user = User::factory()->create();
        Board::factory()->create(['title' => 'Someone else']);

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Launch plan', 'folder' => 'Plans/Q3', 'items' => [
                ['kind' => 'sticky', 'text' => 'Pick the date'],
            ]])->assertOk();

        UserServer::actingAs($user)
            ->tool(ListBoards::class, ['search' => 'pick the date'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('boards', 1)
                ->where('boards.0.title', 'Launch plan')
                ->where('boards.0.folder', 'Plans/Q3')
                ->where('boards.0.item_count', 1)
                ->etc()
            );

        UserServer::actingAs($user)
            ->tool(ListBoardFolders::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('folders', [
                    ['path' => 'Plans', 'boards_count' => 0],
                    ['path' => 'Plans/Q3', 'boards_count' => 1],
                ])
                ->where('top_level_boards_count', 0)
            );

        UserServer::actingAs($user)
            ->tool(ListBoards::class, ['folder' => 'Nowhere'])
            ->assertHasErrors(['There is no board folder "Nowhere". See list-board-folders.']);
    }

    public function test_delete_board_removes_it()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create(['title' => 'Old plan']);

        UserServer::actingAs($user)
            ->tool(DeleteBoard::class, ['ref_id' => $board->ref_id])
            ->assertOk()
            ->assertSee("Deleted board {$board->ref_id} (\"Old plan\").");

        $this->assertSame(0, $user->boards()->count());
    }

    public function test_a_board_that_could_not_be_drawn_is_refused_with_a_reason()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Bad', 'items' => [['kind' => 'octagon']]])
            ->assertHasErrors();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Bad', 'items' => [
                ['id' => 'a', 'kind' => 'rect'],
                ['kind' => 'arrow', 'from' => ['item' => 'a'], 'to' => ['item' => 'nowhere']],
            ]])
            ->assertHasErrors(['A connector points at something it cannot pin to. "to" points at "nowhere", which is not an item on this board.']);

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Bad', 'items' => [
                ['id' => 'a', 'kind' => 'rect'],
                ['id' => 'a', 'kind' => 'sticky'],
            ]])
            ->assertHasErrors(['Every item needs its own id. Used twice: "a".']);

        $this->assertSame(0, $user->boards()->count());
    }

    public function test_a_token_only_reaches_its_own_boards()
    {
        $user = User::factory()->create();
        $theirs = Board::factory()->create(['title' => 'Private']);

        UserServer::actingAs($user)->tool(GetBoard::class, ['ref_id' => $theirs->ref_id])
            ->assertHasErrors(["Board {$theirs->ref_id} was not found."]);
        UserServer::actingAs($user)->tool(UpdateBoard::class, ['ref_id' => $theirs->ref_id, 'title' => 'Hacked'])
            ->assertHasErrors();
        UserServer::actingAs($user)->tool(DeleteBoard::class, ['ref_id' => $theirs->ref_id])
            ->assertHasErrors();

        $this->assertSame('Private', $theirs->refresh()->title);
    }

    public function test_a_circuit_is_drawn_with_symbols_wired_pin_to_pin()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, [
                'title' => 'LED',
                'items' => [
                    ['id' => 'v1', 'kind' => 'symbol', 'symbol' => 'battery', 'text' => "BT1\n9 V", 'x' => 0, 'y' => 40],
                    ['id' => 'r1', 'kind' => 'symbol', 'symbol' => 'resistor', 'text' => "R1\n330 Ω", 'x' => 80, 'y' => 0],
                    ['id' => 'd1', 'kind' => 'symbol', 'symbol' => 'led', 'text' => 'D1', 'x' => 260, 'y' => 40, 'rotation' => 90],
                    ['id' => 'w1', 'kind' => 'arrow', 'from' => ['item' => 'v1', 'pin' => '+'], 'to' => ['item' => 'r1', 'pin' => '1']],
                    // Another name for a pin, and no pin at all: the nearest is taken
                    ['id' => 'w2', 'kind' => 'arrow', 'from' => ['item' => 'r1', 'pin' => '2'], 'to' => ['item' => 'd1', 'pin' => 'anode']],
                    ['id' => 'w3', 'kind' => 'arrow', 'from' => ['item' => 'd1', 'pin' => 'k'], 'to' => ['item' => 'v1']],
                ],
            ])
            ->assertOk();

        $board = $user->boards()->sole();
        $items = $this->stored($board);

        // Each part at the size the library draws it
        $this->assertEquals([80, 40], [$items['r1']['width'], $items['r1']['height']]);
        $this->assertEquals([40, 80], [$items['v1']['width'], $items['v1']['height']]);
        $this->assertSame('led', $items['d1']['symbol']);

        // Each wire on the pin it named, where the canvas has that pin
        $this->assertEquals(['item' => 'v1', 'side' => null, 'pin' => '+', 'x' => 20.0, 'y' => 40.0], $items['w1']['from']);
        $this->assertEquals(['item' => 'r1', 'side' => null, 'pin' => '1', 'x' => 80.0, 'y' => 20.0], $items['w1']['to']);
        // A part turned a quarter turn has its pins turned with it
        $this->assertEquals(['item' => 'd1', 'side' => null, 'pin' => 'a', 'x' => 240.0, 'y' => 40.0], $items['w2']['to']);
        $this->assertEquals(['item' => 'd1', 'side' => null, 'pin' => 'k', 'x' => 240.0, 'y' => 120.0], $items['w3']['from']);
        $this->assertSame('-', $items['w3']['to']['pin']);

        // A wire has no head
        $this->assertSame('none', $items['w1']['endHead']);

        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items.1.symbol', 'resistor')
                ->where('items.1.text', "R1\n330 Ω")
                ->where('items.3.from', ['item' => 'v1', 'pin' => '+'])
                ->missing('items.1.fontSize')
                ->etc()
            );
    }

    public function test_a_symbol_has_to_say_which_part_and_a_wire_a_pin_it_has()
    {
        $user = User::factory()->create();
        $draw = fn (array $items) => UserServer::actingAs($user)->tool(CreateBoard::class, ['title' => 'Circuit', 'items' => $items]);

        $draw([['kind' => 'symbol']])
            ->assertHasErrors();
        $draw([['kind' => 'symbol', 'symbol' => 'flux-capacitor']])
            ->assertHasErrors();
        $draw([
            ['id' => 'r1', 'kind' => 'symbol', 'symbol' => 'resistor'],
            ['id' => 'q1', 'kind' => 'symbol', 'symbol' => 'npn'],
            ['kind' => 'arrow', 'from' => ['item' => 'r1', 'pin' => '2'], 'to' => ['item' => 'q1', 'pin' => 'gate']],
        ])->assertHasErrors(['A connector points at something it cannot pin to. "to" names pin "gate" of "q1", a npn, whose pins are b, c, e.']);

        $this->assertSame(0, $user->boards()->count());
    }

    public function test_a_board_or_a_chip_is_wired_by_the_names_of_its_pins()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, [
                'title' => 'Sensor',
                'items' => [
                    ['id' => 'u1', 'kind' => 'symbol', 'symbol' => 'esp32', 'text' => "U1\nESP32", 'x' => 0, 'y' => 0],
                    ['id' => 's1', 'kind' => 'symbol', 'symbol' => 'chip:VCC,DATA,GND', 'text' => "S1\nAM2302", 'x' => 400, 'y' => 180],
                    // The pin as the board prints it, D4, is the pin the library calls GPIO4
                    ['id' => 'w1', 'kind' => 'arrow', 'from' => ['item' => 'u1', 'pin' => 'D4'], 'to' => ['item' => 's1', 'pin' => 'DATA']],
                ],
            ])
            ->assertOk();

        $items = $this->stored($user->boards()->sole());

        $this->assertEquals([160, 320], [$items['u1']['width'], $items['u1']['height']]);
        $this->assertEquals([100, 80], [$items['s1']['width'], $items['s1']['height']]);
        $this->assertEquals(['item' => 'u1', 'side' => null, 'pin' => 'GPIO4', 'x' => 160.0, 'y' => 220.0], $items['w1']['from']);
        $this->assertEquals(['item' => 's1', 'side' => null, 'pin' => 'DATA', 'x' => 400.0, 'y' => 220.0], $items['w1']['to']);

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Bad', 'items' => [
                ['id' => 'u1', 'kind' => 'symbol', 'symbol' => 'esp32'],
                ['id' => 'r1', 'kind' => 'symbol', 'symbol' => 'resistor'],
                ['kind' => 'arrow', 'from' => ['item' => 'u1', 'pin' => 'GPIO99'], 'to' => ['item' => 'r1', 'pin' => '1']],
            ]])
            ->assertHasErrors();
    }

    public function test_a_shape_can_be_see_through_and_have_no_outline()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Outlines',
            'items' => [
                ['id' => 'hollow', 'kind' => 'rect', 'text' => 'Scope', 'fill' => 'transparent', 'lineWidth' => 6],
                ['id' => 'bare', 'kind' => 'ellipse', 'lineWidth' => 0],
                ['id' => 'words', 'kind' => 'text', 'text' => 'A note'],
            ],
        ])->assertOk();

        $board = $user->boards()->sole();
        $stored = $this->stored($board);

        $this->assertSame('transparent', $stored['hollow']['fill']);
        $this->assertSame(6.0, (float) $stored['hollow']['lineWidth']);
        $this->assertSame(0.0, (float) $stored['bare']['lineWidth']);

        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                // Chosen, so said: left out, the shape would read as white
                ->where('items.0.fill', 'transparent')
                ->where('items.0.lineWidth', 6)
                ->where('items.1.lineWidth', 0)
                // Text is never anything else, so it goes without saying
                ->missing('items.2.fill')
                ->etc()
            );
    }

    public function test_a_logic_circuit_keeps_how_its_switches_are_set()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, [
                'title' => 'AND',
                'items' => [
                    ['id' => 'a', 'kind' => 'symbol', 'symbol' => 'input', 'text' => 'A', 'on' => true, 'x' => 0, 'y' => 0],
                    ['id' => 'b', 'kind' => 'symbol', 'symbol' => 'input', 'text' => 'B', 'x' => 0, 'y' => 40],
                    ['id' => 'u1', 'kind' => 'symbol', 'symbol' => 'and', 'text' => 'U1', 'x' => 120, 'y' => 0],
                    ['id' => 'y', 'kind' => 'symbol', 'symbol' => 'output', 'text' => 'Y', 'x' => 260, 'y' => 20],
                    ['kind' => 'arrow', 'from' => ['item' => 'a', 'pin' => 'y'], 'to' => ['item' => 'u1', 'pin' => 'a']],
                    ['kind' => 'arrow', 'from' => ['item' => 'b', 'pin' => 'y'], 'to' => ['item' => 'u1', 'pin' => 'b']],
                    ['kind' => 'arrow', 'from' => ['item' => 'u1', 'pin' => 'out'], 'to' => ['item' => 'y']],
                ],
            ])
            ->assertOk();

        $board = $user->boards()->sole();
        $items = $this->stored($board);

        $this->assertTrue($items['a']['on']);
        $this->assertFalse($items['b']['on']);
        $this->assertEquals([100, 80], [$items['u1']['width'], $items['u1']['height']]);
        // "out" is another name for a gate's y, and the lamp's one pin is taken
        $this->assertSame(['y', 'a'], [collect($items)->last()['from']['pin'], collect($items)->last()['to']['pin']]);

        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items.0.on', true)
                ->missing('items.1.on')
                ->etc()
            );
    }

    public function test_the_admin_server_acts_on_the_user_it_is_given()
    {
        config(['services.mcp.global_token' => 'secret']);
        $user = User::factory()->create();
        Board::factory()->for(User::factory()->create())->create(['title' => 'Theirs']);

        // Without a user there is nobody to act for
        GlobalServer::tool(ListBoards::class)->assertHasErrors();

        GlobalServer::tool(CreateBoard::class, [
            'user_id' => $user->id,
            'title' => 'Mine',
            'items' => [['kind' => 'sticky', 'text' => 'Hello']],
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json->where('user_id', $user->id)->etc());

        GlobalServer::tool(ListBoards::class, ['user_id' => $user->id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('boards', 1)
                ->where('boards.0.title', 'Mine')
                ->etc()
            );
    }
}
