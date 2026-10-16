<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Models\Board\Board;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * A slide's animations over MCP: the list on a frame of what each item does
 * as the show goes on, kept clean, read back, and left without the steps of
 * an item taken off the board.
 */
class BoardAnimationsTest extends TestCase
{
    use RefreshDatabase;

    private function slide(User $user): Board
    {
        UserServer::actingAs($user)->tool(CreateBoard::class, ['title' => 'Deck', 'items' => [
            ['id' => 's1', 'kind' => 'frame', 'x' => 0, 'y' => 0, 'width' => 1280, 'height' => 720, 'text' => 'Why'],
            ['id' => 'title', 'kind' => 'text', 'x' => 80, 'y' => 60, 'width' => 600, 'height' => 80, 'text' => 'Why it matters'],
            ['id' => 'a', 'kind' => 'rect', 'x' => 80, 'y' => 200, 'width' => 300, 'height' => 160, 'text' => 'One'],
            ['id' => 'b', 'kind' => 'rect', 'x' => 420, 'y' => 200, 'width' => 300, 'height' => 160, 'text' => 'Two'],
        ]])->assertOk();

        return Board::query()->where('title', 'Deck')->sole();
    }

    /** @return array<string, array<string, mixed>> */
    private function items(Board $board): array
    {
        return array_column($board->fresh()->content['items'], null, 'id');
    }

    public function test_a_slide_keeps_its_animations_and_how_the_show_comes_to_it()
    {
        $user = User::factory()->create();
        $board = $this->slide($user);

        UserServer::actingAs($user)->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [[
            'id' => 's1',
            'transition' => 'fade',
            'build' => [
                ['item' => 'title', 'kind' => 'in', 'effect' => 'fade', 'start' => 'with'],
                ['item' => 'a', 'kind' => 'in', 'effect' => 'fly', 'from' => 'left'],
                ['item' => 'b', 'kind' => 'in', 'effect' => 'wipe', 'start' => 'after', 'duration' => 1000, 'delay' => 200],
                ['item' => 'a', 'kind' => 'emphasis', 'effect' => 'pulse'],
            ],
        ]]])->assertOk();

        $frame = $this->items($board)['s1'];
        $this->assertSame('fade', $frame['transition']);
        $this->assertSame([
            ['item' => 'title', 'kind' => 'in', 'effect' => 'fade', 'start' => 'with', 'duration' => 500, 'delay' => 0],
            ['item' => 'a', 'kind' => 'in', 'effect' => 'fly', 'start' => 'click', 'duration' => 500, 'delay' => 0, 'from' => 'left'],
            // A wipe comes from the bottom unless told a side
            ['item' => 'b', 'kind' => 'in', 'effect' => 'wipe', 'start' => 'after', 'duration' => 1000, 'delay' => 200, 'from' => 'bottom'],
            ['item' => 'a', 'kind' => 'emphasis', 'effect' => 'pulse', 'start' => 'click', 'duration' => 500, 'delay' => 0],
        ], $frame['build']);

        // Read back as it was sent
        UserServer::actingAs($user)->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn ($json) => $json
                ->where('items.0.transition', 'fade')
                ->where('items.0.build.1.effect', 'fly')
                ->etc());
    }

    public function test_an_item_taken_off_the_board_takes_its_animations_with_it()
    {
        $user = User::factory()->create();
        $board = $this->slide($user);
        UserServer::actingAs($user)->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [[
            'id' => 's1',
            'build' => [['item' => 'a', 'kind' => 'in'], ['item' => 'b', 'kind' => 'in']],
        ]]])->assertOk();

        UserServer::actingAs($user)->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'delete_items' => ['a']])->assertOk();

        $this->assertSame(['b'], array_column($this->items($board)['s1']['build'], 'item'));
    }

    public function test_an_effect_a_kind_has_not_got_is_refused_and_an_empty_list_takes_them_off()
    {
        $user = User::factory()->create();
        $board = $this->slide($user);

        $refused = UserServer::actingAs($user)->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [[
            'id' => 's1', 'build' => [['item' => 'a', 'kind' => 'sideways']],
        ]]]);
        $this->assertInstanceOf(TestResponse::class, $refused);
        $refused->assertHasErrors();

        UserServer::actingAs($user)->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [[
            'id' => 's1', 'build' => [['item' => 'a', 'kind' => 'in']],
        ]]])->assertOk();
        UserServer::actingAs($user)->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [[
            'id' => 's1', 'build' => [],
        ]]])->assertOk();

        $this->assertSame([], $this->items($board)['s1']['build']);
    }
}
