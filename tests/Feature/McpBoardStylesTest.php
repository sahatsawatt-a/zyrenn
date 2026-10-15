<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Models\Board\Board;
use App\Models\User;
use App\Support\Board\LabelFit;
use App\Support\Board\Shapes;
use App\Support\Board\Styles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Ready-made styles and the designs a shape can be drawn in, as a client
 * asks for them. The canvas reads the same lists (lib/styles.json,
 * lib/shapes.json); its own tests (composables/styles.test.ts) hold the
 * colours these do.
 */
class McpBoardStylesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<string, mixed>>
     */
    private function stored(Board $board): array
    {
        return array_column($board->refresh()->content['items'], null, 'id');
    }

    public function test_a_style_puts_a_colour_on_each_kind_as_the_canvas_does()
    {
        $this->assertSame(
            ['fill' => '#e0e7ff', 'stroke' => '#6366f1', 'color' => '#312e81', 'lineWidth' => 1.5],
            Styles::styled('rect', 'soft', 'indigo'),
        );
        $this->assertSame(['fill' => '#b45309', 'stroke' => '#92400e', 'color' => '#ffffff'], array_slice(Styles::styled('rect', 'solid', 'amber'), 0, 3));
        $this->assertSame(['stroke' => '#9f1239'], Styles::styled('arrow', 'solid', 'rose'));
        $this->assertSame(['color' => '#0369a1'], Styles::styled('text', 'bold', 'sky'));
        $this->assertSame([], Styles::styled('image', 'solid', 'rose'));

        $this->assertSame(['solid', 'indigo'], Styles::parse('Solid:Indigo'));
        $this->assertSame(['soft', 'indigo'], Styles::parse('soft'));
        $this->assertNull(Styles::parse('glossy:indigo'));
        $this->assertNull(Styles::parse('soft:chartreuse'));
    }

    public function test_a_client_draws_with_styles_designs_and_shadows()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, [
                'title' => 'System',
                'items' => [
                    ['id' => 'db', 'kind' => 'cylinder', 'text' => 'Orders', 'style' => 'solid:emerald', 'variant' => 'card', 'shadow' => true],
                    // What is said as well wins over the style
                    ['id' => 'api', 'kind' => 'server', 'text' => 'API', 'style' => 'soft:indigo', 'stroke' => '#000000', 'variant' => 'badge'],
                    ['id' => 'note', 'kind' => 'text', 'text' => 'Live', 'color' => '#be123c'],
                    ['id' => 'link', 'kind' => 'arrow', 'from' => ['item' => 'api'], 'to' => ['item' => 'db'], 'style' => 'solid:slate'],
                ],
            ])
            ->assertOk();

        $board = $user->boards()->sole();
        $items = $this->stored($board);

        $this->assertSame(['#047857', '#065f46', '#ffffff', 'card', true], [
            $items['db']['fill'], $items['db']['stroke'], $items['db']['color'], $items['db']['variant'], $items['db']['shadow'],
        ]);
        $this->assertSame(['#e0e7ff', '#000000', 'badge'], [$items['api']['fill'], $items['api']['stroke'], $items['api']['variant']]);
        $this->assertSame('#be123c', $items['note']['color']);
        $this->assertSame('#1e293b', $items['link']['stroke']);

        // Read back as it was said, for the client to send again
        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('items.0.variant', 'card')
                ->where('items.0.shadow', true)
                ->where('items.0.color', '#ffffff')
                ->where('items.2.color', '#be123c')
                ->etc());

        // A card's words are written inside it, under its icon
        $this->assertGreaterThan(0.5 * $items['db']['height'], LabelFit::box($items['db'])['y']);
    }

    public function test_a_design_the_shape_has_not_got_is_left_at_its_usual_one()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Shapes', 'items' => [
                ['id' => 'a', 'kind' => 'rect', 'variant' => 'card'],
                ['id' => 'b', 'kind' => 'cylinder', 'variant' => 'stacked'],
                ['id' => 'c', 'kind' => 'lane', 'variant' => 'left'],
            ]])
            ->assertOk();

        $items = $this->stored($user->boards()->sole());

        $this->assertArrayNotHasKey('variant', $items['a']);
        $this->assertSame('stacked', $items['b']['variant']);
        $this->assertSame('left', $items['c']['variant']);

        $this->assertSame(['classic', 'stacked', 'shaded', 'card', 'badge', 'icon'], Shapes::designs('cylinder'));
        $this->assertSame([], Shapes::designs('rect'));
    }

    public function test_a_style_that_is_not_one_is_said_and_nothing_is_drawn()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Bad', 'items' => [['kind' => 'rect', 'style' => 'glossy:indigo']]])
            ->assertHasErrors();

        $this->assertSame(0, $user->boards()->count());

        // ...and one can be put on later, by id
        UserServer::actingAs($user)->tool(CreateBoard::class, ['title' => 'Good', 'items' => [['id' => 'a', 'kind' => 'rect']]])->assertOk();
        $board = $user->boards()->sole();

        UserServer::actingAs($user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'update_items' => [['id' => 'a', 'style' => 'outline:rose']]])
            ->assertOk();

        $this->assertSame(['#ffffff', '#e11d48'], [$this->stored($board)['a']['fill'], $this->stored($board)['a']['stroke']]);
    }
}
