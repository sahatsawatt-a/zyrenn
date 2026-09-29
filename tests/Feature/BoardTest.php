<?php

namespace Tests\Feature;

use App\Models\Board\Board;
use App\Models\Board\BoardFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BoardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A board's contents, as the canvas saves them.
     *
     * @return array<string, mixed>
     */
    private function contents(string $label = 'Kick-off'): array
    {
        return [
            'items' => [
                [
                    'id' => 'i1',
                    'kind' => 'frame',
                    'x' => 0,
                    'y' => 0,
                    'width' => 960,
                    'height' => 540,
                    'text' => $label,
                ],
                [
                    'id' => 'i2',
                    'kind' => 'sticky',
                    'x' => 40,
                    'y' => 60,
                    'width' => 180,
                    'height' => 180,
                    'text' => 'Ship the canvas',
                ],
            ],
        ];
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('boards.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_only_the_users_own_boards()
    {
        $user = User::factory()->create();
        $mine = Board::factory()->for($user)->create();
        Board::factory()->create();

        $this->actingAs($user)
            ->get(route('boards.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('boards/Index')
                ->has('boards', 1)
                ->where('boards.0.ref_id', $mine->ref_id)
                ->missing('boards.0.id')
            );
    }

    public function test_store_creates_a_blank_board_and_opens_it()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('boards.store'));

        $board = $user->boards()->sole();
        $response->assertRedirect(route('boards.show', $board));
        $this->assertSame('', $board->title);
        $this->assertNull($board->content);
    }

    public function test_owner_can_view_and_save_a_board()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('boards.show', $board))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('boards/Show')
                ->where('board.ref_id', $board->ref_id)
            );

        $this->actingAs($user)
            ->patchJson(route('boards.update', $board), [
                'title' => 'Quarter plan',
                'content' => $this->contents(),
            ])
            ->assertOk()
            ->assertJsonStructure(['updated_at']);

        $board->refresh();
        $this->assertSame('Quarter plan', $board->title);
        $this->assertCount(2, $board->content['items']);
    }

    public function test_saving_keeps_the_labels_searchable()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create();

        $this->actingAs($user)->patchJson(route('boards.update', $board), [
            'content' => $this->contents(),
        ])->assertOk();

        $this->assertStringContainsString('Ship the canvas', (string) $board->refresh()->plain_text);

        $this->actingAs($user)
            ->get(route('boards.index', ['q' => 'ship the canvas']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('boards', 1)
                ->where('boards.0.ref_id', $board->ref_id)
                ->etc()
            );
    }

    public function test_the_list_says_how_much_is_on_each_board()
    {
        $user = User::factory()->create();
        Board::factory()->for($user)->create(['content' => $this->contents()]);

        $this->actingAs($user)
            ->get(route('boards.index'))
            ->assertInertia(fn (Assert $page) => $page->where('boards.0.items', 2)->etc());
    }

    public function test_boards_are_addressed_by_ref_id_not_numeric_id()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create();

        $this->assertSame(route('boards.show', $board->ref_id), route('boards.show', $board));
        $this->actingAs($user)->get("/boards/{$board->id}")->assertNotFound();
    }

    public function test_users_cannot_reach_another_users_board()
    {
        $user = User::factory()->create();
        $theirs = Board::factory()->create();

        $this->actingAs($user)->get(route('boards.show', $theirs))->assertForbidden();
        $this->actingAs($user)->patchJson(route('boards.update', $theirs), ['title' => 'Mine'])->assertForbidden();
        $this->actingAs($user)->delete(route('boards.destroy', $theirs))->assertForbidden();
    }

    public function test_owner_can_delete_a_board()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create();

        $this->actingAs($user)
            ->delete(route('boards.destroy', $board))
            ->assertRedirect(route('boards.index'));

        $this->assertNull($user->boards()->first());
    }

    public function test_the_picker_lists_the_users_own_boards()
    {
        $user = User::factory()->create();
        Board::factory()->for($user)->create(['title' => 'Launch plan']);
        Board::factory()->create(['title' => 'Someone else']);

        $this->actingAs($user)
            ->getJson(route('boards.pick'))
            ->assertOk()
            ->assertJsonCount(1, 'boards')
            ->assertJsonPath('boards.0.title', 'Launch plan');

        $this->actingAs($user)
            ->getJson(route('boards.pick', ['q' => 'nothing like it']))
            ->assertJsonCount(0, 'boards');
    }

    public function test_a_boards_contents_are_served_to_its_owner_only()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create(['content' => $this->contents()]);
        $theirs = Board::factory()->create();

        $this->actingAs($user)
            ->getJson(route('boards.content', $board))
            ->assertOk()
            ->assertJsonPath('items.1.text', 'Ship the canvas');

        $this->actingAs($user)
            ->getJson(route('boards.content', $theirs))
            ->assertForbidden();
    }

    public function test_boards_are_deleted_with_their_user()
    {
        $user = User::factory()->create();
        Board::factory()->for($user)->create();
        $folder = BoardFolder::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseCount('boards', 0);
        $this->assertDatabaseMissing('board_folders', ['id' => $folder->id]);
    }
}
