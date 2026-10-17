<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Models\Board\Board;
use App\Models\User;
use App\Support\Board\BoardItems;
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

        // Taken off and put back in one go would leave nothing of either
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'delete_items' => ['c'], 'add_items' => [['id' => 'c', 'kind' => 'sticky', 'text' => 'Again']]])
            ->assertHasErrors(['"c" is both taken off and added. To change it, send it in update_items; to put something new in its place, give that a new id or leave "id" out.']);

        $this->assertSame($before, $board->fresh()->content);
    }

    public function test_an_id_made_up_is_never_one_taken_off_in_the_same_change()
    {
        $board = $this->board();
        $added = UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [['kind' => 'sticky'], ['kind' => 'sticky']]]);
        $ids = array_column($board->fresh()->content['items'], 'id');
        $highest = end($ids);

        // The newest taken off, and something new without an id put on at once
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'delete_items' => [$highest], 'add_items' => [['kind' => 'sticky', 'text' => 'New']]])
            ->assertOk();

        $items = collect($board->fresh()->content['items'])->keyBy('id');
        $this->assertFalse($items->has($highest));
        $this->assertSame('New', $items->firstWhere('text', 'New')['text']);
        $this->assertNotSame($highest, $items->firstWhere('text', 'New')['id']);
    }

    public function test_items_take_corners_opacity_gradients_a_focus_and_hug_their_words()
    {
        $board = $this->board();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'card', 'kind' => 'rect', 'text' => 'One line', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 400, 'radius' => 20, 'opacity' => 0.5, 'fill' => '#4f46e5', 'gradient' => '#7c3aed', 'gradientAngle' => 135, 'hug' => true],
                ['id' => 'shot', 'kind' => 'image', 'src' => '/drive/files/abc', 'fit' => 'cover', 'focusY' => 0, 'x' => 0, 'y' => 500, 'width' => 300, 'height' => 100],
                ['id' => 'slide', 'kind' => 'frame', 'text' => 'Intro', 'src' => '/drive/files/bg', 'notes' => 'Say hello.', 'section' => 'Part one', 'x' => 1000, 'y' => 0, 'width' => 1600, 'height' => 900],
                ['id' => 'clip', 'kind' => 'video', 'src' => '/drive/files/film', 'poster' => '/drive/files/still', 'posterAt' => 3.5, 'x' => 0, 'y' => 700, 'width' => 320, 'height' => 180],
            ]])
            ->assertOk();

        $items = array_column($board->fresh()->content['items'], null, 'id');
        $this->assertEquals([20, 0.5, '#7c3aed', 135, true], [$items['card']['radius'], $items['card']['opacity'], $items['card']['gradient'], $items['card']['gradientAngle'], $items['card']['hug']]);
        // As tall as one line and its padding, not the 400 asked for
        $this->assertEquals(ceil(16 * 1.3 + 24), $items['card']['height']);
        $this->assertEquals(0.0, $items['shot']['focusY']);
        $this->assertSame(['Say hello.', 'Part one', '/drive/files/bg'], [$items['slide']['notes'], $items['slide']['section'], $items['slide']['src']]);
        $this->assertEquals(['/drive/files/still', 3.5], [$items['clip']['poster'], $items['clip']['posterAt']]);

        // More words: it grows with them
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'card', 'text' => "One line\nTwo lines\nThree"]]])
            ->assertOk();
        $this->assertEquals(ceil(3 * 16 * 1.3 + 24), array_column($board->fresh()->content['items'], null, 'id')['card']['height']);
        // A fraction stays one, through a change to something else
        $this->assertEquals([0.5, 0.0], [array_column($board->fresh()->content['items'], null, 'id')['card']['opacity'], array_column($board->fresh()->content['items'], null, 'id')['shot']['focusY']]);

        // Read back as they were said
        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id, 'outline' => false])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items', fn ($items) => collect($items)->firstWhere('id', 'card')['radius'] == 20
                    && collect($items)->firstWhere('id', 'card')['hug'] === true
                    && collect($items)->firstWhere('id', 'slide')['notes'] === 'Say hello.')
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [['kind' => 'rect', 'opacity' => 2]]])
            ->assertHasErrors();
    }

    public function test_a_connector_pinned_to_nothing_belongs_to_the_frame_its_ends_are_in()
    {
        $board = $this->board();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'inside', 'kind' => 'arrow', 'from' => ['x' => 100, 'y' => 100], 'to' => ['x' => 300, 'y' => 200]],
                ['id' => 'outside', 'kind' => 'arrow', 'from' => ['x' => 5000, 'y' => 5000], 'to' => ['x' => 5200, 'y' => 5000]],
            ]])
            ->assertOk();

        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id, 'frame' => 'plan', 'outline' => true])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items', fn ($items) => collect($items)->pluck('id')->contains('inside') && ! collect($items)->pluck('id')->contains('outside'))
                ->etc());
    }

    public function test_a_path_is_drawn_from_svg_path_data_with_fades_glows_and_effects()
    {
        $board = $this->board();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                // Where its points say: a wave from 100,300 to 500,300 bulging up to 150
                ['id' => 'wave', 'kind' => 'path', 'd' => 'M100 300 C200 100 400 100 500 300', 'lineWidth' => 6, 'strokeStops' => [['at' => 0, 'color' => '#4f46e5'], ['at' => 0.5, 'color' => '#06b6d4'], ['at' => 1, 'color' => '#22c55e']], 'glow' => '#06b6d4', 'glowSize' => 30],
                // Its points measured from the corner given, relative ones too
                ['id' => 'blob', 'kind' => 'path', 'd' => 'm0 50 a50 50 0 1 1 100 0 a50 50 0 1 1 -100 0 z', 'x' => 1000, 'y' => 1000, 'fill' => '#6366f1', 'gradientType' => 'radial', 'gradientStops' => [['at' => 0, 'color' => '#a5b4fc'], ['at' => 1, 'color' => '#312e81']], 'blur' => 4, 'blend' => 'screen'],
                ['id' => 'kicker', 'kind' => 'text', 'text' => 'network intelligence', 'caps' => true, 'letterSpacing' => 3, 'color' => '#6366f1', 'colorTo' => '#06b6d4', 'fontSize' => 14],
            ]])
            ->assertOk();

        $items = array_column($board->fresh()->content['items'], null, 'id');
        $wave = $items['wave'];
        $this->assertSame('M0 150 C100 -50 300 -50 400 150', $wave['d']);
        $this->assertEquals([100, 150, 400, 150], [$wave['x'], $wave['y'], $wave['width'], $wave['height']]);
        $this->assertSame(['#4f46e5', '#06b6d4', '#22c55e'], array_column($wave['strokeStops'], 'color'));
        $this->assertSame('#06b6d4', $wave['glow']);
        // A circle of 100 drawn from 1000,1000 down 0..100
        $blob = $items['blob'];
        $this->assertEquals([1000, 1000, 100, 100], [round($blob['x']), round($blob['y']), round($blob['width']), round($blob['height'])]);
        $this->assertSame(['radial', 'screen', 4.0], [$blob['gradientType'], $blob['blend'], (float) $blob['blur']]);
        $this->assertSame([true, 3.0, '#06b6d4'], [$items['kicker']['caps'], (float) $items['kicker']['letterSpacing'], $items['kicker']['colorTo']]);

        // Scaled by a size of its own; its points stay as they were
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'wave', 'width' => 800]]])
            ->assertOk();
        $wave = array_column($board->fresh()->content['items'], null, 'id')['wave'];
        $this->assertEquals([800, 400, 'M0 150 C100 -50 300 -50 400 150'], [$wave['width'], $wave['viewWidth'], $wave['d']]);

        // New points are where they say, the size they say -- not squeezed
        // into the old box at its old corner
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'wave', 'd' => 'M2000 900 L2600 900 L2600 1300']]])
            ->assertOk();
        $wave = array_column($board->fresh()->content['items'], null, 'id')['wave'];
        $this->assertEquals([2000, 900, 600, 400, 'M0 0 L600 0 L600 400'], [$wave['x'], $wave['y'], $wave['width'], $wave['height'], $wave['d']]);

        // Unless a place or a size is sent with them
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'wave', 'd' => 'M0 0 L100 0', 'x' => 50, 'y' => 60, 'width' => 300]]])
            ->assertOk();
        $wave = array_column($board->fresh()->content['items'], null, 'id')['wave'];
        $this->assertEquals([50, 60, 300, 100], [$wave['x'], $wave['y'], $wave['width'], $wave['viewWidth']]);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [['kind' => 'path', 'd' => 'M0 0 L1 1', 'blend' => 'dissolve']]])
            ->assertHasErrors();
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

    public function test_nothing_is_left_drawn_under_the_frame_it_sits_on()
    {
        config(['services.collab.url' => 'http://collab.test', 'services.collab.secret' => 'secret']);
        // Open nowhere, so the app keeps each change itself; what the live copy
        // would have been sent is still asked
        Http::fake(['collab.test/*' => Http::response(['live' => false])]);
        $board = $this->board();

        // A frame added over what is already there is drawn under it
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'notes', 'kind' => 'frame', 'text' => 'Notes', 'x' => -100, 'y' => 700, 'width' => 800, 'height' => 450],
            ]])
            ->assertOk();

        // ...and the live copy is told the new order, as it would put the frame on top
        Http::assertSent(fn (SentRequest $request) => $request->url() === 'http://collab.test/apply'
            && collect($request['edits'])->last() === ['do' => 'order', 'ids' => ['plan', 'done', 'a', 'b', 'line', 'c', 'notes', 'loose']]);

        // Frames reordered the way the slides go: each still under what is on it
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'frame_order' => ['plan', 'notes', 'done']])
            ->assertOk();

        Http::assertSent(fn (SentRequest $request) => $request->url() === 'http://collab.test/apply'
            && collect($request['edits'])->last() === ['do' => 'order', 'ids' => ['plan', 'notes', 'a', 'b', 'line', 'done', 'c', 'loose']]);

        // A plain change leaves the order to the live copy
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'c', 'text' => 'Out the door']]])
            ->assertOk();

        Http::assertSent(fn (SentRequest $request) => $request->url() === 'http://collab.test/apply' && count($request['edits']) === 2);
    }

    public function test_a_whole_list_never_lands_a_new_item_on_an_old_one()
    {
        $board = $this->board();

        // Sent without ids: once these were "i1", "i2"... and took over
        // whatever the board had under those, labels and all
        $board->update(['content' => ['items' => BoardItems::fromSpec([
            ['id' => 'i1', 'kind' => 'text', 'text' => 'Old title', 'align' => 'right', 'x' => 0, 'y' => 0],
            ['id' => 'i2', 'kind' => 'rect', 'text' => 'Old box', 'x' => 0, 'y' => 100],
        ])]]);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'items' => [
                ['kind' => 'image', 'src' => '/drive/files/abc'],
                ['kind' => 'text', 'text' => 'New title'],
                ['id' => 'i2', 'kind' => 'rect'],
            ]])
            ->assertOk();

        $items = array_column($board->refresh()->content['items'], null, 'id');

        $this->assertSame(['i3', 'i4', 'i2'], array_column($board->content['items'], 'id'));
        $this->assertSame('', $items['i3']['text']);
        // The default for a text item, not the old one's "right"
        $this->assertSame(['New title', 'left'], [$items['i4']['text'], $items['i4']['align']]);
        // Named, and the same kind: it keeps what it had
        $this->assertSame('Old box', $items['i2']['text']);

        // Named, but now another kind of thing: a new one, not the old one changed
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'items' => [['id' => 'i2', 'kind' => 'sticky']]])
            ->assertOk();

        $this->assertSame('', $board->refresh()->content['items'][0]['text']);
    }

    public function test_a_text_item_is_as_tall_as_its_words_and_a_label_that_wont_fit_is_said()
    {
        $board = $this->board();
        $long = "Why it matters\n• First point\n• Second point\n• Third point";

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'notes', 'kind' => 'text', 'text' => $long, 'width' => 400, 'fontSize' => 20],
                ['id' => 'cramped', 'kind' => 'rect', 'text' => $long, 'width' => 200, 'height' => 60],
            ]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                // Four lines at 20 x 1.3: the text item grew; the box can't
                ->where('overflowing', [['id' => 'cramped', 'kind' => 'rect', 'height' => 60, 'needs_height' => 108]])
                ->has('note')
                ->etc());

        $items = array_column($board->refresh()->content['items'], null, 'id');
        $this->assertEquals(104, $items['notes']['height']);

        // More words later: it grows again, unless a height is given
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [
                ['id' => 'notes', 'text' => $long."\n• Fourth point"],
            ]])
            ->assertOk();

        $this->assertEquals(130, array_column($board->refresh()->content['items'], null, 'id')['notes']['height']);
    }

    public function test_a_sticky_grows_to_hold_its_words_and_keeps_room_it_was_given()
    {
        $board = $this->board();
        $long = str_repeat('Customers on a trial, with when it ends. ', 4);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'short', 'kind' => 'sticky', 'text' => $long, 'width' => 220, 'height' => 160, 'fontSize' => 20, 'fontFamily' => 'hand'],
                ['id' => 'roomy', 'kind' => 'sticky', 'text' => 'Idea', 'width' => 150, 'height' => 150],
            ]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->missing('overflowing')->etc());

        $items = array_column($board->refresh()->content['items'], null, 'id');
        $this->assertGreaterThan(160, $grown = $items['short']['height']);
        $this->assertEquals(150, $items['roomy']['height']);

        // More words later: taller again
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [
                ['id' => 'short', 'text' => $long.$long],
            ]])
            ->assertOk();

        $this->assertGreaterThan($grown, array_column($board->refresh()->content['items'], null, 'id')['short']['height']);
    }

    public function test_the_check_lists_labels_that_run_over_and_things_lying_on_each_other()
    {
        $board = $this->board();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'under', 'kind' => 'image', 'src' => '/drive/files/abc', 'x' => 420, 'y' => 120, 'width' => 100, 'height' => 100],
                ['id' => 'long', 'kind' => 'rect', 'text' => str_repeat('word ', 40), 'x' => 600, 'y' => 300, 'width' => 120, 'height' => 50],
                // A slide's backdrop, locked under everything: not an overlap
                ['id' => 'backdrop', 'kind' => 'rect', 'locked' => true, 'fill' => '#f8fafc', 'x' => 0, 'y' => 0, 'width' => 800, 'height' => 450],
            ]])
            ->assertOk();

        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id, 'check' => true])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('overflowing.0.id', 'long')
                ->where('overlapping', [['frame' => 'plan', 'items' => ['b', 'under'], 'overlap' => 0.8]])
                ->etc());

        // Not asked: not there
        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->missing('overflowing')->missing('overlapping')->etc());
    }

    public function test_a_frame_is_deleted_with_everything_on_it_and_frames_can_be_reordered()
    {
        $board = $this->board();

        // A line from the loose note into the first frame goes with it
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'across', 'kind' => 'arrow', 'from' => ['item' => 'loose'], 'to' => ['item' => 'a']],
                ['id' => 'third', 'kind' => 'frame', 'text' => 'Third', 'x' => 2000, 'y' => 0, 'width' => 800, 'height' => 450],
            ]])
            ->assertOk();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'delete_frames' => ['Plan']])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('deleted', fn ($ids) => collect($ids)->sort()->values()->all() === ['a', 'across', 'b', 'line', 'plan'])
                ->etc());

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'frame_order' => ['third', 'done']])
            ->assertOk();

        // "done" takes a later place than what is on it, which comes up over it
        $this->assertSame(['third', 'loose', 'done', 'c'], array_column($board->refresh()->content['items'], 'id'));

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'frame_order' => ['done']])
            ->assertHasErrors(['frame_order needs every frame\'s id, once each. The frames are: "third", "done".']);
    }

    public function test_a_picture_says_how_it_fills_its_box()
    {
        $board = $this->board();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                ['id' => 'photo', 'kind' => 'image', 'src' => '/drive/files/abc', 'fit' => 'cover', 'stroke' => '#0f172a'],
                ['id' => 'plain', 'kind' => 'image', 'src' => '/drive/files/def'],
            ]])
            ->assertOk();

        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id, 'outline' => false])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items', fn ($items) => collect($items)->firstWhere('id', 'photo')['fit'] === 'cover'
                    && ! isset(collect($items)->firstWhere('id', 'plain')['fit']))
                ->etc());
    }

    public function test_a_label_can_be_rich_with_its_own_face_and_room()
    {
        $board = $this->board();

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [
                // A heading at 1.6 x 20 and two bullets at 20, each line 1.3 apart
                ['id' => 'card', 'kind' => 'text', 'rich' => true, 'width' => 400, 'fontSize' => 20,
                    'text' => "# Why\n- one\n- two"],
                ['id' => 'box', 'kind' => 'rect', 'text' => 'Roomy', 'padding' => 30, 'fontFamily' => 'serif'],
            ]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->missing('overflowing')->etc());

        $items = array_column($board->refresh()->content['items'], null, 'id');
        $this->assertEquals(ceil((32 + 20 + 20) * 1.3), $items['card']['height']);

        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id, 'outline' => false])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items', function ($items) {
                    $byId = collect($items)->keyBy('id');

                    return $byId['card']['rich'] === true
                        && $byId['box']['padding'] == 30
                        && $byId['box']['fontFamily'] === 'serif'
                        // Defaults are left unsaid
                        && ! isset($byId['a']['padding'], $byId['a']['fontFamily'], $byId['a']['rich']);
                })
                ->etc());

        // Less room inside: the same words no longer fit
        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [
                ['id' => 'box', 'padding' => 70],
            ]])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('overflowing.0.id', 'box')->etc());
    }

    public function test_a_big_board_in_frames_comes_as_its_frames_to_be_read_one_at_a_time()
    {
        $board = $this->board();
        $words = str_repeat('word ', 30);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => array_map(
                fn ($n) => ['id' => "s{$n}", 'kind' => 'sticky', 'text' => $words, 'x' => 1100 + $n, 'y' => 100],
                range(1, 60),
            )])
            ->assertOk();

        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->missing('items')
                ->where('frames.1', ['id' => 'done', 'title' => 'Done', 'items' => 61])
                ->where('loose_items', 1)
                ->has('more')
                ->etc());

        // What is on no frame, on its own
        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id, 'frame' => 'board'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items', fn ($items) => collect($items)->pluck('id')->all() === ['loose'])
                ->etc());

        // Only the check: no items at all, even on a small board
        UserServer::actingAs($this->user)
            ->tool(GetBoard::class, ['ref_id' => $this->board()->ref_id, 'items' => false, 'check' => true])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->missing('items')
                ->missing('more')
                ->has('overflowing')
                ->has('overlapping')
                ->etc());
    }
}
