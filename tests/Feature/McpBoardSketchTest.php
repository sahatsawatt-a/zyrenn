<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Models\Board\Board;
use App\Models\User;
use App\Support\Board\LabelFit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * The hand-drawn look as a client asks for it: lines drawn rough
 * ("sketch"), a sketched shape's fill hatched, and the handwritten fonts.
 * The canvas holds the same rules (model/sketch.ts, drawing/sketch.test.ts).
 */
class McpBoardSketchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<string, mixed>>
     */
    private function stored(Board $board): array
    {
        return array_column($board->refresh()->content['items'], null, 'id');
    }

    public function test_a_client_sketches_shapes_lines_and_frames_and_writes_by_hand()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Wireframe', 'items' => [
                ['id' => 'screen', 'kind' => 'frame', 'text' => 'Login', 'sketch' => 1, 'fontFamily' => 'hand'],
                ['id' => 'button', 'kind' => 'rect', 'text' => 'Sign in', 'sketch' => 2, 'hatch' => 'hachure', 'fontFamily' => 'marker'],
                ['id' => 'db', 'kind' => 'cylinder', 'sketch' => true],
                ['id' => 'link', 'kind' => 'arrow', 'from' => ['item' => 'button'], 'to' => ['item' => 'db'], 'sketch' => 1],
                // A picture is not drawn in lines: nothing to sketch
                ['id' => 'logo', 'kind' => 'image', 'src' => 'https://example.com/logo.png', 'sketch' => 1],
            ]])
            ->assertOk();

        $board = $user->boards()->sole();
        $items = $this->stored($board);

        $this->assertSame([1, 'hand'], [$items['screen']['sketch'], $items['screen']['fontFamily']]);
        $this->assertSame([2, 'hachure', 'marker'], [$items['button']['sketch'], $items['button']['hatch'], $items['button']['fontFamily']]);
        $this->assertSame(1, $items['db']['sketch']);
        $this->assertSame(1, $items['link']['sketch']);
        $this->assertArrayNotHasKey('sketch', $items['logo']);

        // Read back as said, to be sent again
        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items.1.sketch', 2)
                ->where('items.1.hatch', 'hachure')
                ->where('items.1.fontFamily', 'marker')
                ->etc());

        // ...and made crisp again
        UserServer::actingAs($user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'button', 'sketch' => 0, 'fontFamily' => 'sans']]])
            ->assertOk();

        $this->assertSame([0, 'sans'], [$this->stored($board)['button']['sketch'], $this->stored($board)['button']['fontFamily']]);

        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->missing('items.1.sketch')
                ->missing('items.1.hatch')
                ->etc());
    }

    public function test_a_roughness_or_fill_that_is_not_one_is_said_and_nothing_is_drawn()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Bad', 'items' => [['kind' => 'rect', 'sketch' => 5]]])
            ->assertHasErrors();
        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Bad', 'items' => [['kind' => 'rect', 'sketch' => 1, 'hatch' => 'dots']]])
            ->assertHasErrors();

        $this->assertSame(0, $user->boards()->count());
    }

    public function test_handwriting_is_measured_as_narrow_as_it_is_drawn()
    {
        $item = ['kind' => 'text', 'text' => 'A login screen with a password box and a button under it, and a link to sign up for those who have no account yet', 'width' => 300, 'height' => 20, 'fontSize' => 28, 'padding' => 0];

        $sans = LabelFit::needed([...$item, 'fontFamily' => 'sans']);
        $hand = LabelFit::needed([...$item, 'fontFamily' => 'hand']);
        $marker = LabelFit::needed([...$item, 'fontFamily' => 'marker']);

        // Caveat sets about three quarters as wide as Arial: fewer lines
        $this->assertLessThan($sans, $hand);
        $this->assertGreaterThanOrEqual($sans, $marker);
    }
}
