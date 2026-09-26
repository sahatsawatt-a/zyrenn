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
use App\Models\Board;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class McpBoardsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A small flowchart, as a client would send it.
     *
     * @return list<array<string, mixed>>
     */
    private function flowchart(): array
    {
        return [
            ['id' => 'start', 'kind' => 'process', 'text' => 'Order placed'],
            ['id' => 'check', 'kind' => 'diamond', 'text' => 'In stock?'],
            ['id' => 'store', 'kind' => 'cylinder', 'text' => 'Warehouse'],
            ['kind' => 'arrow', 'from' => ['item' => 'start'], 'to' => ['item' => 'check']],
            ['kind' => 'arrow', 'from' => ['item' => 'check'], 'to' => ['item' => 'store'], 'text' => 'yes'],
        ];
    }

    /**
     * The items stored on a board, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stored(Board $board): array
    {
        return array_column($board->refresh()->content['items'], null, 'id');
    }

    // ------------------------------------------------------------ User server

    public function test_user_can_list_only_their_own_boards()
    {
        $user = User::factory()->create();
        Board::factory()->for($user)->create(['title' => 'Launch plan']);
        Board::factory()->for($user)->create(['title' => 'Architecture']);
        Board::factory()->create(['title' => 'Someone else']);

        UserServer::actingAs($user)
            ->tool(ListBoards::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('boards', 2));

        UserServer::actingAs($user)
            ->tool(ListBoards::class, ['search' => 'lAuNch'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('boards', 1)
                ->where('boards.0.title', 'Launch plan')
                ->etc());
    }

    public function test_user_cannot_read_update_or_delete_another_users_board()
    {
        $user = User::factory()->create();
        $board = Board::factory()->create(['title' => 'Private']);

        UserServer::actingAs($user)->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertHasErrors(["Board {$board->ref_id} was not found."]);
        UserServer::actingAs($user)->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'title' => 'Hacked'])
            ->assertHasErrors();
        UserServer::actingAs($user)->tool(DeleteBoard::class, ['ref_id' => $board->ref_id])
            ->assertHasErrors();

        $this->assertSame('Private', $board->refresh()->title);
    }

    public function test_a_client_can_draw_a_flowchart_with_connected_shapes()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Ordering', 'items' => $this->flowchart()])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('title', 'Ordering')
                ->where('item_count', 5)
                ->where('items.0.text', 'Order placed')
                ->where('items.3.from.item', 'start')
                ->where('items.3.to.item', 'check')
                ->etc());

        $items = $this->stored($user->boards()->sole());

        // Every shape was given the toolbar's defaults for its kind. Numbers
        // come back from jsonb as ints when they are whole, hence assertEquals
        $this->assertEquals(200, $items['start']['width']);
        $this->assertSame('#e0e7ff', $items['store']['fill']);

        // Connected shapes are laid out as a flow, so the chart reads downwards
        $this->assertLessThan($items['check']['y'], $items['start']['y']);
        $this->assertLessThan($items['store']['y'], $items['check']['y']);

        // Neither end was given a side, so both follow the shapes -- and the
        // point sits on the face that currently looks at the other end
        $connector = $items['i4'];
        $this->assertSame('start', $connector['from']['item']);
        $this->assertNull($connector['from']['side']);
        $this->assertEquals(
            $items['start']['y'] + $items['start']['height'],
            $connector['from']['y'],
        );
        $this->assertNull($connector['to']['side']);
        $this->assertEquals($items['check']['y'], $connector['to']['y']);
    }

    public function test_items_sent_without_coordinates_are_laid_out_in_rows()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Retro',
            'items' => [
                ['kind' => 'sticky', 'text' => 'Went well'],
                ['kind' => 'sticky', 'text' => 'Went badly'],
                ['kind' => 'sticky', 'text' => 'Try next'],
                ['kind' => 'rect', 'text' => 'Placed by hand', 'x' => 2000, 'y' => 900],
            ],
        ])->assertOk();

        $items = $this->stored($user->boards()->sole());

        // Nothing is connected here, so they go in a row, not on top of each other
        $this->assertEquals(0, $items['i1']['x']);
        $this->assertEquals(220, $items['i2']['x']);
        $this->assertEquals(440, $items['i3']['x']);
        $this->assertEquals([0, 0, 0], array_column([$items['i1'], $items['i2'], $items['i3']], 'y'));

        // Stickies come out of the same pack of colours the canvas deals from
        $this->assertSame('#fde68a', $items['i1']['fill']);
        $this->assertSame('#bbf7d0', $items['i2']['fill']);

        // A client that did its own arithmetic is left alone
        $this->assertEquals(2000, $items['i4']['x']);
        $this->assertEquals(900, $items['i4']['y']);
    }

    public function test_a_branch_shares_a_row_and_anything_unconnected_sits_below()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Checkout',
            'items' => [
                ['id' => 'check', 'kind' => 'diamond', 'text' => 'In stock?'],
                ['id' => 'pay', 'kind' => 'process', 'text' => 'Take payment'],
                ['id' => 'sorry', 'kind' => 'document', 'text' => 'Back-order notice'],
                ['kind' => 'arrow', 'from' => ['item' => 'check'], 'to' => ['item' => 'pay'], 'text' => 'yes'],
                ['kind' => 'arrow', 'from' => ['item' => 'check'], 'to' => ['item' => 'sorry'], 'text' => 'no'],
                ['id' => 'aside', 'kind' => 'sticky', 'text' => 'Retries are out of scope'],
            ],
        ])->assertOk();

        $items = $this->stored($user->boards()->sole());

        // Both outcomes of the decision sit on the same row, side by side
        $this->assertEquals($items['pay']['y'], $items['sorry']['y']);
        $this->assertNotEquals($items['pay']['x'], $items['sorry']['x']);
        $this->assertLessThan($items['pay']['y'], $items['check']['y']);

        // The decision is centred over the pair it branches into
        $centre = fn (array $item) => $item['x'] + $item['width'] / 2;
        $this->assertEqualsWithDelta(
            ($centre($items['pay']) + $centre($items['sorry'])) / 2,
            $centre($items['check']),
            1,
        );

        // A sticky no connector touches is put below the chart, out of its way
        $this->assertGreaterThan($items['pay']['y'] + $items['pay']['height'], $items['aside']['y']);
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
                // A loop that has to dip below the pair rather than run between them
                ['id' => 'loop', 'kind' => 'arrow',
                    'from' => ['item' => 'b', 'side' => 'bottom'],
                    'to' => ['item' => 'a', 'side' => 'bottom']],
            ],
        ])->assertOk();

        $items = $this->stored($user->boards()->sole());

        $this->assertNull($items['plain']['from']['side']);
        $this->assertNull($items['plain']['to']['side']);

        $this->assertSame('bottom', $items['loop']['from']['side']);
        $this->assertSame('bottom', $items['loop']['to']['side']);
        // The point goes with the side it was pinned to
        $this->assertEquals(100, $items['loop']['from']['y']);
        $this->assertEquals(500, $items['loop']['from']['x']);
    }

    public function test_a_label_can_be_lined_up_inside_what_it_is_written_on()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Aligned',
            'items' => [
                ['id' => 'card', 'kind' => 'rect', 'text' => 'Top left', 'align' => 'left', 'verticalAlign' => 'top'],
                ['id' => 'plain', 'kind' => 'rect', 'text' => 'Middle of it'],
                ['id' => 'words', 'kind' => 'text', 'text' => 'A heading'],
            ],
        ])->assertOk();

        $items = $this->stored($user->boards()->sole());

        $this->assertSame(['left', 'top'], [$items['card']['align'], $items['card']['verticalAlign']]);
        // Left alone, a shape centres its label and a text item starts top left
        $this->assertSame(['center', 'middle'], [$items['plain']['align'], $items['plain']['verticalAlign']]);
        $this->assertSame(['left', 'top'], [$items['words']['align'], $items['words']['verticalAlign']]);

        // Only the ones that differ from their kind's own default are reported
        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $user->boards()->sole()->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items.0.align', 'left')
                ->where('items.0.verticalAlign', 'top')
                ->missing('items.1.align')
                ->missing('items.2.align')
                ->etc());
    }

    public function test_items_stay_in_the_order_they_were_sent()
    {
        $user = User::factory()->create();

        // Back to front matters on a board, and the items that carry an id are
        // the ones validation is apt to shuffle to the front
        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Order',
            'items' => [
                ['kind' => 'frame', 'text' => 'Slide one'],
                ['id' => 'card', 'kind' => 'rect', 'text' => 'On top of it'],
                ['kind' => 'text', 'text' => 'And above that'],
                ['id' => 'last', 'kind' => 'sticky', 'text' => 'Last of all'],
            ],
        ])->assertOk();

        $this->assertSame(
            ['Slide one', 'On top of it', 'And above that', 'Last of all'],
            array_column($user->boards()->sole()->content['items'], 'text'),
        );
    }

    public function test_labels_on_a_board_are_searchable_and_read_back_as_items()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Ordering', 'items' => $this->flowchart()])
            ->assertOk();

        $board = $user->boards()->sole();
        $this->assertStringContainsString('Warehouse', (string) $board->plain_text);

        UserServer::actingAs($user)
            ->tool(ListBoards::class, ['search' => 'warehouse'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('boards', 1)->etc());

        // get-board reports the short form, not the canvas's twenty-odd fields
        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items.2.kind', 'cylinder')
                ->where('items.2.text', 'Warehouse')
                ->where('items.4.text', 'yes')
                ->where('items.4.to.item', 'store')
                ->missing('items.2.points')
                ->etc());
    }

    public function test_updating_changes_only_what_it_names_and_drops_what_it_leaves_out()
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

        UserServer::actingAs($user)->tool(UpdateBoard::class, [
            'ref_id' => $board->ref_id,
            'title' => 'Ordering v2',
            'items' => [
                // Only the label changes; the colour and the box stay
                ['id' => 'start', 'kind' => 'process', 'text' => 'Order received'],
                ['id' => 'check', 'kind' => 'diamond', 'text' => 'In stock?'],
            ],
        ])->assertOk();

        $items = $this->stored($board);

        $this->assertSame('Ordering v2', $board->refresh()->title);
        $this->assertSame('Order received', $items['start']['text']);
        $this->assertSame('#bfdbfe', $items['start']['fill']);
        // The connector was left out of the list, so it is off the board
        $this->assertArrayNotHasKey('line', $items);
        $this->assertSame(2, $board->itemCount());

        // Title-only updates leave the drawing alone
        UserServer::actingAs($user)->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'title' => 'Ordering v3'])
            ->assertOk();
        $this->assertCount(2, $this->stored($board));
        $this->assertSame('Ordering v3', $board->refresh()->title);
    }

    public function test_a_picture_is_kept_through_a_round_trip_without_being_reported()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create([
            'content' => ['items' => [
                ['id' => 'pic', 'kind' => 'image', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 200,
                    'text' => '', 'src' => 'data:image/png;base64,'.str_repeat('A', 400)],
            ]],
        ]);

        // The bytes are not worth reporting; that it is a picture is
        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items.0.has_picture', true)
                ->missing('items.0.src')
                ->etc());

        UserServer::actingAs($user)->tool(UpdateBoard::class, [
            'ref_id' => $board->ref_id,
            'items' => [['id' => 'pic', 'kind' => 'image', 'text' => 'Floor plan']],
        ])->assertOk();

        $item = $this->stored($board)['pic'];
        $this->assertSame('Floor plan', $item['text']);
        $this->assertStringStartsWith('data:image/png;base64,', $item['src']);
        $this->assertEquals(300, $item['width']);
    }

    public function test_a_drive_picture_can_be_put_on_a_board()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Site',
            'items' => [['kind' => 'image', 'src' => '/drive/files/k3x9m2p7qa', 'width' => 400, 'height' => 300]],
        ])->assertOk();

        $item = $this->stored($user->boards()->sole())['i1'];
        $this->assertSame('/drive/files/k3x9m2p7qa', $item['src']);
        $this->assertEquals(400, $item['width']);
    }

    public function test_a_board_that_could_not_be_drawn_is_refused_with_a_reason()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(CreateBoard::class, ['title' => 'Bad', 'items' => [['kind' => 'octagon']]])
            ->assertHasErrors();

        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Bad',
            'items' => [
                ['id' => 'a', 'kind' => 'rect'],
                ['kind' => 'arrow', 'from' => ['item' => 'a'], 'to' => ['item' => 'nowhere']],
            ],
        ])->assertHasErrors(['A connector points at something it cannot pin to. "to" points at "nowhere", which is not an item on this board.']);

        // A connector cannot hang off a frame or another connector
        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Bad',
            'items' => [
                ['id' => 'a', 'kind' => 'rect'],
                ['id' => 'slide', 'kind' => 'frame'],
                ['kind' => 'arrow', 'from' => ['item' => 'a'], 'to' => ['item' => 'slide']],
            ],
        ])->assertHasErrors();

        UserServer::actingAs($user)->tool(CreateBoard::class, [
            'title' => 'Bad',
            'items' => [['id' => 'a', 'kind' => 'rect'], ['id' => 'a', 'kind' => 'sticky']],
        ])->assertHasErrors(['Every item needs its own id. Used twice: "a".']);

        $this->assertSame(0, $user->boards()->count());
    }

    public function test_boards_can_be_created_in_and_moved_between_folders_by_path()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Q3 launch', 'folder' => 'Plans/Q3'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('folder', 'Plans/Q3')->etc());

        $board = $user->boards()->sole();
        $this->assertSame(['Plans', 'Q3'], $user->boardFolders()->orderBy('id')->pluck('name')->all());
        // Board folders are their own tree, not the note one
        $this->assertSame(0, $user->noteFolders()->count());

        UserServer::actingAs($user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'folder' => 'Archive'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('folder', 'Archive')->etc());

        UserServer::actingAs($user)
            ->tool(ListBoardFolders::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('folders', [
                    ['path' => 'Archive', 'boards_count' => 1],
                    ['path' => 'Plans', 'boards_count' => 0],
                    ['path' => 'Plans/Q3', 'boards_count' => 0],
                ])
                ->where('top_level_boards_count', 0));

        UserServer::actingAs($user)
            ->tool(ListBoards::class, ['folder' => 'Archive'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('boards', 1));

        UserServer::actingAs($user)
            ->tool(ListBoards::class, ['folder' => 'Nowhere'])
            ->assertHasErrors(['There is no board folder "Nowhere". See list-board-folders.']);
    }

    public function test_a_board_can_be_deleted()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create(['title' => 'Old plan']);

        UserServer::actingAs($user)
            ->tool(DeleteBoard::class, ['ref_id' => $board->ref_id])
            ->assertOk()
            ->assertSee("Deleted board {$board->ref_id} (\"Old plan\").");

        $this->assertSame(0, $user->boards()->count());
    }

    // ---------------------------------------------------------- Global server

    public function test_global_server_acts_on_the_chosen_users_boards()
    {
        config(['services.mcp.global_token' => 'secret']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        Board::factory()->for($other)->create(['title' => 'Theirs']);

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
                ->etc());

        GlobalServer::tool(ListBoards::class, ['user_id' => $other->id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('boards', 1)
                ->where('boards.0.title', 'Theirs')
                ->etc());
    }
}
