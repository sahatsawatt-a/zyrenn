<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Models\Board\Board;
use App\Models\User;
use App\Support\Board\BoardItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A board's frames made into a deck: its look, what every slide says, and
 * slides laid out from a layout -- over MCP, live, and on the page.
 */
class BoardDeckTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function byId(Board $board): array
    {
        return array_column($board->fresh()->content['items'], null, 'id');
    }

    public function test_a_deck_is_made_with_its_look_and_slides_laid_out_one_after_another()
    {
        UserServer::actingAs($this->user)
            ->tool(CreateBoard::class, [
                'title' => 'Q3',
                'deck' => ['theme' => 'ivory', 'footer' => '{title} · {section}', 'numbers' => true],
                'slides' => [
                    ['layout' => 'title', 'title' => 'Q3 in review', 'subtitle' => 'What changed'],
                    ['layout' => 'title-body', 'title' => 'Sign-ups doubled', 'body' => "- one\n- two", 'notes' => 'Pause here'],
                ],
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('deck', ['theme' => 'ivory', 'footer' => '{title} · {section}', 'numbers' => true])
                ->etc());

        $board = Board::query()->sole();
        $items = $this->byId($board);

        $this->assertSame(['theme' => 'ivory', 'footer' => '{title} · {section}', 'numbers' => true], $board->content['deck']);

        // A frame each, side by side, in the theme's background
        $this->assertEquals([0.0, 1040.0], [$items['slide1']['x'], $items['slide2']['x']]);
        $this->assertSame('#faf7f0', $items['slide1']['fill']);
        $this->assertEquals([960.0, 540.0], [$items['slide1']['width'], $items['slide1']['height']]);
        $this->assertTrue($items['slide1']['bare']);
        $this->assertArrayNotHasKey('bare', $items['slide2']);
        $this->assertSame('Pause here', $items['slide2']['notes']);
        $this->assertSame('Sign-ups doubled', $items['slide2']['text']);

        // Each part where its layout puts it, set in the theme's type
        $title = $items['slide1-title'];
        $this->assertEquals(['text', 56.0, 206.0, 44.0], [$title['kind'], $title['x'], $title['y'], $title['fontSize']]);
        $this->assertSame(['serif', '#1c1917'], [$title['fontFamily'], $title['color']]);
        $this->assertSame(['sans', '#78716c'], [$items['slide1-subtitle']['fontFamily'], $items['slide1-subtitle']['color']]);
        $this->assertTrue($items['slide2-body']['rich']);
        $this->assertEquals(1040.0 + 56, $items['slide2-body']['x']);

        // A part said nothing for is left off
        $this->assertArrayNotHasKey('slide1-kicker', $items);
    }

    public function test_slides_are_added_after_the_last_frame_under_the_ids_asked_for()
    {
        $board = Board::factory()->for($this->user)->create([
            'content' => ['items' => BoardItems::fromSpec([
                ['id' => 'old', 'kind' => 'frame', 'text' => 'Old', 'x' => 200, 'y' => 100, 'width' => 960, 'height' => 540],
            ])],
        ]);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_slides' => [
                ['id' => 'results', 'layout' => 'section', 'kicker' => 'Part 2', 'title' => 'Results'],
                ['layout' => 'big-number', 'number' => '42%', 'caption' => 'of sign-ups came from search'],
                ['layout' => 'picture-right', 'title' => 'The new page', 'body' => 'Shorter', 'picture' => 'https://example.com/a.png'],
            ]])
            ->assertOk();

        $items = $this->byId($board);

        $this->assertEquals([1240.0, 100.0], [$items['results']['x'], $items['results']['y']]);
        // A section slide begins a part of the deck with its title
        $this->assertSame('Results', $items['results']['section']);
        $this->assertSame('Part 2', $items['results-kicker']['text']);
        $this->assertTrue($items['results-kicker']['caps']);
        $this->assertSame('#4f46e5', $items['results-kicker']['color']);

        $this->assertEquals(2280.0, $items['slide1']['x']);
        $this->assertEquals(['42%', 120.0], [$items['slide1-number']['text'], $items['slide1-number']['fontSize']]);
        $this->assertSame(['image', 'https://example.com/a.png', 'cover'], [
            $items['slide2-picture']['kind'], $items['slide2-picture']['src'], $items['slide2-picture']['fit'],
        ]);

        // Whatever is on a slide is drawn above it
        $order = array_column($board->fresh()->content['items'], 'id');
        $this->assertLessThan(array_search('slide1-number', $order), array_search('slide1', $order));
    }

    public function test_a_deck_is_changed_a_field_at_a_time_and_kept_through_item_changes()
    {
        $board = Board::factory()->for($this->user)->create([
            'content' => [
                'items' => BoardItems::fromSpec([['id' => 'one', 'kind' => 'frame', 'x' => 0, 'y' => 0]]),
                'deck' => ['theme' => 'night', 'header' => '{section}'],
            ],
        ]);
        $server = UserServer::actingAs($this->user);

        $server->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'deck' => ['footer' => 'Source: survey', 'header' => '', 'numbers' => true]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('deck', ['theme' => 'night', 'footer' => 'Source: survey', 'numbers' => true])
                ->etc());

        // Changing what is on it leaves the deck as it was
        $server->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'one', 'text' => 'Intro']]])->assertOk();
        $server->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'items' => [['id' => 'one', 'kind' => 'frame']]])->assertOk();
        $this->assertSame(['theme' => 'night', 'footer' => 'Source: survey', 'numbers' => true], $board->fresh()->content['deck']);

        $server->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('deck.footer', 'Source: survey')
                ->etc());

        $server->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'deck' => null])->assertOk();
        $this->assertArrayNotHasKey('deck', $board->fresh()->content);
    }

    public function test_a_frame_can_be_left_bare_of_the_header_and_footer()
    {
        $board = Board::factory()->for($this->user)->create([
            'content' => ['items' => BoardItems::fromSpec([['id' => 'one', 'kind' => 'frame', 'x' => 0, 'y' => 0]])],
        ]);
        $server = UserServer::actingAs($this->user);

        $server->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'one', 'bare' => true]]])->assertOk();

        $this->assertTrue($this->byId($board)['one']['bare']);
        $server->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('items.0.bare', true)->etc());
    }

    public function test_a_deck_or_slide_that_is_not_one_is_turned_away()
    {
        $board = Board::factory()->for($this->user)->create();
        $server = UserServer::actingAs($this->user);

        $server->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'deck' => ['theme' => 'neon']])->assertHasErrors();
        $server->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'deck' => ['font' => 'comic']])->assertHasErrors();
        $server->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_slides' => [['layout' => 'collage']]])->assertHasErrors();
        $server->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_slides' => [['layout' => 'picture-right', 'picture' => 'file:///etc/passwd']]])->assertHasErrors();
    }

    public function test_a_deck_changed_while_the_board_is_open_changes_in_the_live_copy()
    {
        config(['services.collab.url' => 'http://collab.test', 'services.collab.secret' => 'secret']);
        Http::fake([
            'collab.test/flush' => Http::response(['live' => true]),
            'collab.test/apply' => Http::response(['live' => true, 'missing' => []]),
        ]);
        $board = Board::factory()->for($this->user)->create([
            'content' => ['items' => BoardItems::fromSpec([['id' => 'one', 'kind' => 'frame', 'x' => 0, 'y' => 0]])],
        ]);

        UserServer::actingAs($this->user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'deck' => ['theme' => 'forest']])
            ->assertOk();

        Http::assertSent(fn (SentRequest $request) => $request->url() === 'http://collab.test/apply'
            && $request['edits'] === [['do' => 'deck', 'deck' => ['theme' => 'forest']]]);

        // The collaboration server keeps it, as it keeps everything drawn live
        $this->assertArrayNotHasKey('deck', $board->fresh()->content);
    }

    public function test_the_collaboration_server_opens_and_keeps_a_boards_deck()
    {
        config(['services.collab.secret' => 'secret']);
        $items = [['id' => 'a', 'kind' => 'frame', 'x' => 0, 'y' => 0, 'width' => 960, 'height' => 540]];
        $board = Board::factory()->create(['content' => ['items' => $items, 'deck' => ['theme' => 'mono']]]);
        $internal = ['X-Collab-Secret' => 'secret'];

        $this->getJson(route('collab.show', "boards.{$board->ref_id}"), $internal)
            ->assertJsonPath('deck', ['theme' => 'mono']);

        // A server that says nothing of the deck leaves it be...
        $this->putJson(route('collab.update', "boards.{$board->ref_id}"), [
            'state' => 'c3RhdGU=', 'items' => $items, 'title' => '', 'updated_by' => null,
        ], $internal)->assertNoContent();
        $this->assertSame(['theme' => 'mono'], $board->fresh()->content['deck']);

        // ...and one that does keeps what it says
        $this->putJson(route('collab.update', "boards.{$board->ref_id}"), [
            'state' => 'c3RhdGU=', 'items' => $items, 'deck' => ['theme' => 'night', 'numbers' => true], 'title' => '', 'updated_by' => null,
        ], $internal)->assertNoContent();
        $this->assertSame(['theme' => 'night', 'numbers' => true], $board->fresh()->content['deck']);

        $this->putJson(route('collab.update', "boards.{$board->ref_id}"), [
            'state' => 'c3RhdGU=', 'items' => $items, 'deck' => null, 'title' => '', 'updated_by' => null,
        ], $internal)->assertNoContent();
        $this->assertSame(['items' => $items], $board->fresh()->content);
    }

    public function test_the_presenters_window_opens_for_whoever_may_see_the_board()
    {
        $board = Board::factory()->for($this->user)->create(['title' => 'Q3']);

        $this->actingAs($this->user)
            ->get(route('boards.presenter', $board))
            ->assertInertia(fn (Assert $page) => $page
                ->component('boards/Presenter')
                ->where('board', ['ref_id' => $board->ref_id, 'title' => 'Q3']));

        $this->actingAs(User::factory()->create())
            ->get(route('boards.presenter', $board))
            ->assertForbidden();
    }

    public function test_a_board_shown_elsewhere_comes_with_its_deck()
    {
        $board = Board::factory()->for($this->user)->create(['content' => ['items' => [], 'deck' => ['numbers' => true]]]);

        $this->actingAs($this->user)
            ->getJson(route('boards.content', $board))
            ->assertJsonPath('deck', ['numbers' => true]);
    }
}
