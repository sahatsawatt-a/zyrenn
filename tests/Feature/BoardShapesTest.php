<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\ListMyShapes;
use App\Mcp\Tools\Boards\PlaceMyShape;
use App\Mcp\Tools\Boards\SaveMyShape;
use App\Models\Board\Board;
use App\Models\Board\BoardShape;
use App\Models\Project;
use App\Models\User;
use App\Support\Board\BoardShapes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shapes of one's own: what was drawn on a board kept to put on any board,
 * as one group -- a person's, or a project's for all its members.
 */
class BoardShapesTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIEW = 'data:image/png;base64,iVBORw0KGgo=';

    /** A face: two eyes and a line between them, and a box off to the side. */
    private function drawn(): array
    {
        return [
            ['id' => 'i1', 'kind' => 'ellipse', 'x' => 100, 'y' => 200, 'width' => 40, 'height' => 40, 'text' => ''],
            ['id' => 'i2', 'kind' => 'ellipse', 'x' => 180, 'y' => 200, 'width' => 40, 'height' => 40, 'text' => ''],
            ['id' => 'i3', 'kind' => 'arrow', 'x' => 0, 'y' => 0, 'width' => 0, 'height' => 0,
                'from' => ['item' => 'i1', 'side' => 'right', 'x' => 140, 'y' => 220], 'to' => ['item' => 'i2', 'side' => 'left', 'x' => 180, 'y' => 220]],
            ['id' => 'i4', 'kind' => 'rect', 'x' => 600, 'y' => 600, 'width' => 80, 'height' => 40, 'text' => 'Away'],
        ];
    }

    private function boardOf(User|Project $owner, User $by): Board
    {
        $board = (new Board(['title' => 'Faces', 'content' => ['items' => $this->drawn()]]))->ownedBy($owner, $by);
        $board->save();

        return $board;
    }

    /** A shape kept by `$user`, made of `$items`. */
    private function shapeOf(User $user, string $name, array $items): BoardShape
    {
        $shape = (new BoardShape([...BoardShapes::fromItems($items), 'name' => $name]))->ownedBy($user, $user);
        $shape->save();

        return $shape;
    }

    public function test_what_is_picked_is_kept_as_a_shape_at_the_top_left_of_its_own_box()
    {
        $user = User::factory()->create();
        $board = $this->boardOf($user, $user);
        [$left, $right, $line] = $this->drawn();
        // A line to something not picked ends where it was, on nothing
        $loose = ['id' => 'i5', 'kind' => 'arrow', 'from' => ['item' => 'i2', 'side' => 'bottom', 'x' => 200, 'y' => 240], 'to' => ['item' => 'i4', 'x' => 640, 'y' => 600]];

        $made = $this->actingAs($user)->postJson(route('boards.shapes.store', $board), [
            'name' => 'Eyes', 'items' => [$left, $right, $line, $loose], 'preview' => self::PREVIEW,
        ])->assertCreated()
            ->assertJsonPath('name', 'Eyes')
            ->assertJsonPath('shared', false)
            ->assertJsonPath('preview', self::PREVIEW);

        $shape = BoardShape::where('ref_id', $made->json('ref_id'))->sole();
        $this->assertSame([540, 400], [$shape->width, $shape->height]);
        $this->assertEquals([0.0, 0.0], [$shape->items[0]['x'], $shape->items[0]['y']]);
        $this->assertEquals(80.0, $shape->items[1]['x']);
        $this->assertSame(['i1', 'i2'], [$shape->items[2]['from']['item'], $shape->items[2]['to']['item']]);
        $this->assertNull($shape->items[3]['to']['item']);
        $this->assertEquals([540.0, 400.0], [$shape->items[3]['to']['x'], $shape->items[3]['to']['y']]);
        $this->assertTrue($user->is($shape->user));
    }

    public function test_a_board_offers_ones_own_and_its_projects_shapes_and_nobody_elses()
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $project = Project::start($owner, 'Studio');
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);
        $board = $this->boardOf($project, $owner);
        $items = array_slice($this->drawn(), 0, 2);

        $this->actingAs($owner)->postJson(route('boards.shapes.store', $board), ['name' => 'Logo', 'items' => $items, 'for_project' => true])
            ->assertCreated()->assertJsonPath('shared', true);
        $this->actingAs($viewer)->postJson(route('boards.shapes.store', $board), ['name' => 'Mine', 'items' => $items])->assertCreated();
        // A viewer keeps one for themselves, not for everyone
        $this->actingAs($viewer)->postJson(route('boards.shapes.store', $board), ['name' => 'Ours', 'items' => $items, 'for_project' => true])
            ->assertJsonValidationErrors('for_project');
        $this->shapeOf(User::factory()->create(), 'Someone else', $items);

        $this->actingAs($viewer)->getJson(route('boards.shapes.index', $board))
            ->assertOk()
            ->assertJsonPath('shapes.0.name', 'Mine')
            ->assertJsonPath('shapes.0.can_change', true)
            ->assertJsonPath('shapes.1.name', 'Logo')
            ->assertJsonPath('shapes.1.can_change', false)
            ->assertJsonCount(2, 'shapes');

        // On a board of their own, only their own
        $theirs = $this->boardOf($viewer, $viewer);
        $this->getJson(route('boards.shapes.index', $theirs))->assertJsonCount(1, 'shapes');
    }

    public function test_only_whoever_keeps_a_shape_renames_or_deletes_it()
    {
        $user = User::factory()->create();
        $shape = $this->shapeOf($user, 'Star', [['id' => 'i1', 'kind' => 'star', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]]);

        $this->actingAs(User::factory()->create())->patchJson(route('board-shapes.update', $shape), ['name' => 'Mine now'])->assertForbidden();
        $this->deleteJson(route('board-shapes.destroy', $shape))->assertForbidden();

        $this->actingAs($user)->patchJson(route('board-shapes.update', $shape), ['name' => 'Gold star'])->assertJsonPath('name', 'Gold star');
        $this->deleteJson(route('board-shapes.destroy', $shape))->assertOk();
        $this->assertSame(0, BoardShape::count());
    }

    public function test_a_shape_is_put_down_as_one_new_group_and_can_be_made_larger()
    {
        $user = User::factory()->create();
        $shape = $this->shapeOf($user, 'Eyes', array_slice($this->drawn(), 0, 3));

        $placed = BoardShapes::placed($shape, ['i1', 'i2', 'i3', 'i9'], 1000, 500, width: 240);

        $ids = array_column($placed, 'id');
        $this->assertSame([], array_intersect($ids, ['i1', 'i2', 'i3', 'i9']));
        $this->assertCount(1, array_unique(array_column($placed, 'group')));
        // Twice as wide: each part twice as large, from where it was put
        $this->assertSame([1000.0, 500.0, 80.0], [$placed[0]['x'], $placed[0]['y'], $placed[0]['width']]);
        $this->assertSame(1160.0, $placed[1]['x']);
        // The line joins the copies, not what it was made from
        $this->assertSame([$ids[0], $ids[1]], [$placed[2]['from']['item'], $placed[2]['to']['item']]);
        $this->assertSame([1080.0, 540.0], [$placed[2]['from']['x'], $placed[2]['from']['y']]);
    }

    public function test_nothing_or_too_much_is_not_a_shape()
    {
        $user = User::factory()->create();
        $board = $this->boardOf($user, $user);

        $this->actingAs($user)->postJson(route('boards.shapes.store', $board), ['name' => 'Empty', 'items' => []])->assertJsonValidationErrors('items');
        $this->postJson(route('boards.shapes.store', $board), ['name' => 'Huge', 'items' => [['kind' => 'image', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'src' => 'data:image/png;base64,'.str_repeat('A', 1_100_000)]]])
            ->assertJsonValidationErrors('items');
        $this->postJson(route('boards.shapes.store', $board), ['name' => 'Odd', 'items' => [['kind' => 'rect']], 'preview' => 'javascript:alert(1)'])
            ->assertJsonValidationErrors('preview');
    }

    public function test_an_assistant_keeps_items_as_a_shape_lists_it_and_puts_it_on_another_board()
    {
        $user = User::factory()->create();
        $board = $this->boardOf($user, $user);
        $other = (new Board(['title' => 'Empty', 'content' => ['items' => [['id' => 'i1', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 50]]]]))->ownedBy($user, $user);
        $other->save();

        UserServer::actingAs($user)->tool(SaveMyShape::class, ['ref_id' => $board->ref_id, 'items' => ['i1', 'i2'], 'name' => 'Eyes'])
            ->assertOk()->assertSee('Eyes');
        // The line between the eyes came with them
        $this->assertCount(3, BoardShape::sole()->items);

        UserServer::actingAs($user)->tool(ListMyShapes::class, [])->assertOk()->assertSee('"name":"Eyes"', false);

        UserServer::actingAs($user)->tool(PlaceMyShape::class, ['ref_id' => $other->ref_id, 'shape' => 'eyes'])->assertOk();
        $items = $other->fresh()->content['items'];
        $this->assertCount(4, $items);
        // Under what was there, each with an id of its own, all one group
        $this->assertSame(['i1'], array_values(array_intersect(array_column($items, 'id'), ['i1'])));
        $this->assertGreaterThan(50, $items[1]['y']);
        $this->assertCount(1, array_unique(array_column(array_slice($items, 1), 'group')));

        UserServer::actingAs($user)->tool(PlaceMyShape::class, ['ref_id' => $other->ref_id, 'shape' => 'Nope'])->assertHasErrors(['There is no shape']);
        UserServer::actingAs($user)->tool(SaveMyShape::class, ['ref_id' => $board->ref_id, 'items' => ['i404'], 'name' => 'Ghost'])->assertHasErrors(['no item "i404"']);
    }
}
