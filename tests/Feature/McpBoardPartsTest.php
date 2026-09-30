<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Models\Board\Board;
use App\Models\User;
use App\Support\BoardItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Reading and changing part of a board over MCP -- one frame, an outline, a
 * few items by id -- so a big board costs what the part does.
 */
class McpBoardPartsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Two frames side by side, a sticky and a connector on the first, a sticky
     * on the second, and a loose one below both.
     */
    private function board(): Board
    {
        return Board::factory()->for($this->user)->create([
            'content' => ['items' => BoardItems::fromSpec([
                ['id' => 'plan', 'kind' => 'frame', 'text' => 'Plan', 'x' => 0, 'y' => 0, 'width' => 800, 'height' => 450],
                ['id' => 'done', 'kind' => 'frame', 'text' => 'Done', 'x' => 1000, 'y' => 0, 'width' => 800, 'height' => 450],
                ['id' => 'a', 'kind' => 'sticky', 'text' => 'Idea', 'x' => 100, 'y' => 100, 'width' => 150, 'height' => 150, 'fill' => '#bfdbfe'],
                ['id' => 'b', 'kind' => 'rect', 'text' => 'Step', 'x' => 400, 'y' => 100, 'width' => 150, 'height' => 100],
                ['id' => 'line', 'kind' => 'arrow', 'from' => ['item' => 'a'], 'to' => ['item' => 'b']],
                ['id' => 'c', 'kind' => 'sticky', 'text' => 'Shipped', 'x' => 1100, 'y' => 100, 'width' => 150, 'height' => 150],
                ['id' => 'loose', 'kind' => 'text', 'text' => 'Notes', 'x' => 0, 'y' => 800, 'width' => 200, 'height' => 40],
            ])],
        ]);
    }

    public function test_the_frames_are_listed_with_what_sits_on_each()
    {
        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $this->board()->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('frames', [
                    ['id' => 'plan', 'title' => 'Plan', 'items' => 3],
                    ['id' => 'done', 'title' => 'Done', 'items' => 1],
                ])
                ->etc());
    }

    public function test_one_frame_is_read_with_what_is_on_it_and_the_lines_it_holds()
    {
        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $this->board()->ref_id, 'frame' => 'plan'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('frame', 'plan')
                ->where('items', fn ($items) => collect($items)->pluck('id')->all() === ['plan', 'a', 'b', 'line'])
                // In full: its colours too
                ->where('items.1.fill', '#bfdbfe')
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $this->board()->ref_id, 'frame' => 'Nowhere'])
            ->assertHasErrors(['There is no frame "Nowhere" on this board. Its frames are: "Plan" (plan), "Done" (done).']);
    }

    public function test_the_outline_leaves_out_colours_and_says_where_each_thing_sits()
    {
        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $this->board()->ref_id, 'outline' => true])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items.2', ['id' => 'a', 'kind' => 'sticky', 'text' => 'Idea', 'x' => 100, 'y' => 100, 'width' => 150, 'height' => 150, 'frame' => 'plan'])
                ->where('items.4', ['id' => 'line', 'kind' => 'arrow', 'from' => 'a', 'to' => 'b', 'frame' => 'plan'])
                ->where('items.6', ['id' => 'loose', 'kind' => 'text', 'text' => 'Notes', 'x' => 0, 'y' => 800, 'width' => 200, 'height' => 40])
                ->etc());
    }

    public function test_a_big_board_comes_in_outline_unless_asked_otherwise()
    {
        $board = Board::factory()->for($this->user)->create(['content' => ['items' => BoardItems::fromSpec(
            array_map(fn ($n) => ['id' => "s{$n}", 'kind' => 'sticky', 'text' => str_repeat('word ', 30)], range(1, 60)),
        )]]);

        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('items', 60)
                ->missing('items.0.fill')
                ->has('more')
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id, 'outline' => false])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('items.0.fill')->missing('more')->etc());
    }

    public function test_items_are_added_changed_and_deleted_by_id_and_the_rest_left_alone()
    {
        $board = $this->board();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, [
                'ref_id' => $board->ref_id,
                'add_items' => [
                    ['kind' => 'sticky', 'text' => 'New'],
                    ['kind' => 'arrow', 'from' => ['item' => 'b'], 'to' => ['item' => 'c']],
                ],
                'update_items' => [['id' => 'a', 'text' => 'Idea, better']],
                'delete_items' => ['loose'],
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('added', ['i1', 'i2'])
                ->where('changed', ['a'])
                ->where('deleted', ['loose'])
                ->missing('items')
                ->etc());

        $items = collect($board->fresh()->content['items'])->keyBy('id');
        $this->assertSame('Idea, better', $items['a']['text']);
        // Not mentioned, so it kept its colour and place
        $this->assertSame('#bfdbfe', $items['a']['fill']);
        $this->assertEquals(100, $items['a']['x']);
        $this->assertArrayNotHasKey('loose', $items->all());
        $this->assertSame('New', $items['i1']['text']);
        // A new connector pins to items that were there already
        $this->assertSame('b', $items['i2']['from']['item']);
        $this->assertSame('c', $items['i2']['to']['item']);
    }

    public function test_a_change_that_cant_be_made_leaves_the_board_as_it_was()
    {
        $board = $this->board();
        $before = $board->content;

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [['kind' => 'sticky']], 'delete_items' => ['nothere']])
            ->assertHasErrors(['There is no item "nothere" on this board. get-board lists its items.']);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [['kind' => 'sticky']], 'items' => []])
            ->assertHasErrors();

        $this->assertSame($before, $board->fresh()->content);
    }

    public function test_a_board_open_somewhere_is_changed_item_by_item_in_the_live_copy()
    {
        config(['services.collab.url' => 'http://collab.test', 'services.collab.secret' => 'secret']);
        Http::fake([
            'collab.test/flush' => Http::response(['live' => true]),
            'collab.test/apply' => Http::response(['live' => true, 'missing' => []]),
        ]);
        $board = $this->board();
        $before = $board->fresh()->content;

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'c', 'text' => 'Out the door']], 'delete_items' => ['loose']])
            ->assertOk();

        Http::assertSent(function (SentRequest $request) {
            if ($request->url() !== 'http://collab.test/apply') {
                return false;
            }

            [$set, $delete] = $request['edits'];

            // Only the changed item goes, not the board
            return $set['do'] === 'set' && count($set['items']) === 1 && $set['items'][0]['text'] === 'Out the door'
                && $delete === ['do' => 'delete', 'ids' => ['loose']];
        });

        $this->assertSame($before, $board->fresh()->content);
    }
}
