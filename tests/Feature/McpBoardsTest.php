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
