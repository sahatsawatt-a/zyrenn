<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BoardFolderTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_the_open_folders_contents_only()
    {
        $user = User::factory()->create();
        $work = BoardFolder::factory()->for($user)->create(['name' => 'Work']);
        $inner = BoardFolder::factory()->for($user)->create(['name' => 'Lakeshore', 'parent_id' => $work->id]);
        $top = Board::factory()->for($user)->create(['title' => 'Top']);
        $filed = Board::factory()->for($user)->create(['title' => 'Filed', 'folder_id' => $work->id]);
        BoardFolder::factory()->create();

        $this->actingAs($user)
            ->get(route('boards.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('boards/Index')
                ->where('folder', null)
                ->has('folders', 1)
                ->where('folders.0.name', 'Work')
                ->has('boards', 1)
                ->where('boards.0.ref_id', $top->ref_id)
                ->where('allFolders', [
                    ['ref_id' => $work->ref_id, 'path' => 'Work'],
                    ['ref_id' => $inner->ref_id, 'path' => 'Work / Lakeshore'],
                ])
            );

        $this->get(route('boards.index', ['folder' => $work->ref_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('folder.ref_id', $work->ref_id)
                ->where('breadcrumbs', [['ref_id' => $work->ref_id, 'name' => 'Work']])
                ->has('folders', 1)
                ->where('boards.0.ref_id', $filed->ref_id)
            );
    }

    public function test_another_users_folder_cannot_be_opened()
    {
        $folder = BoardFolder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('boards.index', ['folder' => $folder->ref_id]))
            ->assertNotFound();
    }

    public function test_a_new_note_can_be_created_inside_a_folder()
    {
        $user = User::factory()->create();
        $folder = BoardFolder::factory()->for($user)->create();

        $this->actingAs($user)->post(route('boards.store'), ['folder' => $folder->ref_id]);

        $this->assertSame($folder->id, $user->boards()->sole()->folder_id);
    }

    public function test_a_note_cannot_be_created_in_another_users_folder()
    {
        $folder = BoardFolder::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('boards.store'), ['folder' => $folder->ref_id])
            ->assertSessionHasErrors('folder');

        $this->assertSame(0, $user->boards()->count());
    }

    public function test_the_note_page_shows_its_folder_path()
    {
        $user = User::factory()->create();
        $work = BoardFolder::factory()->for($user)->create(['name' => 'Work']);
        $inner = BoardFolder::factory()->for($user)->create(['name' => 'Lakeshore', 'parent_id' => $work->id]);
        $board = Board::factory()->for($user)->create(['folder_id' => $inner->id]);

        $this->actingAs($user)
            ->get(route('boards.show', $board))
            ->assertInertia(fn (Assert $page) => $page
                ->where('breadcrumbs', [
                    ['ref_id' => $work->ref_id, 'name' => 'Work'],
                    ['ref_id' => $inner->ref_id, 'name' => 'Lakeshore'],
                ])
            );
    }

    public function test_a_note_can_be_moved_and_renamed_from_the_list()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create();
        $folder = BoardFolder::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('boards.index'))
            ->patch(route('boards.update', $board), ['folder' => $folder->ref_id, 'title' => 'Moved'])
            ->assertRedirect(route('boards.index'));

        $board->refresh();
        $this->assertSame($folder->id, $board->folder_id);
        $this->assertSame('Moved', $board->title);

        $this->patch(route('boards.update', $board), ['folder' => null]);
        $this->assertNull($board->refresh()->folder_id);
    }

    public function test_autosave_still_answers_with_json()
    {
        $board = Board::factory()->create();

        $this->actingAs($board->user)
            ->patchJson(route('boards.update', $board), ['title' => 'Saved'])
            ->assertOk()
            ->assertJsonStructure(['updated_at']);
    }

    public function test_a_note_cannot_be_moved_into_another_users_folder()
    {
        $board = Board::factory()->create();
        $folder = BoardFolder::factory()->create();

        $this->actingAs($board->user)
            ->patch(route('boards.update', $board), ['folder' => $folder->ref_id])
            ->assertSessionHasErrors('folder');

        $this->assertNull($board->refresh()->folder_id);
    }

    public function test_deleting_a_note_returns_to_its_folder()
    {
        $user = User::factory()->create();
        $folder = BoardFolder::factory()->for($user)->create();
        $board = Board::factory()->for($user)->create(['folder_id' => $folder->id]);

        $this->actingAs($user)
            ->delete(route('boards.destroy', $board))
            ->assertRedirect(route('boards.index', ['folder' => $folder->ref_id]));
    }

    public function test_folders_can_be_created_renamed_and_nested()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('board-folders.store'), ['name' => 'KT Plan'])->assertRedirect();
        $plan = $user->boardFolders()->sole();

        $this->post(route('board-folders.store'), ['name' => 'Lakeshore', 'parent' => $plan->ref_id]);
        $this->assertSame($plan->id, $user->boardFolders()->where('name', 'Lakeshore')->sole()->parent_id);

        $this->patch(route('board-folders.update', $plan), ['name' => 'Handover']);
        $this->assertSame('Handover', $plan->refresh()->name);
    }

    public function test_a_folder_cannot_be_moved_into_itself_or_a_descendant()
    {
        $user = User::factory()->create();
        $parent = BoardFolder::factory()->for($user)->create();
        $child = BoardFolder::factory()->for($user)->create(['parent_id' => $parent->id]);

        $this->actingAs($user)
            ->patch(route('board-folders.update', $parent), ['parent' => $child->ref_id])
            ->assertSessionHasErrors('parent');

        $this->patch(route('board-folders.update', $parent), ['parent' => $parent->ref_id])
            ->assertSessionHasErrors('parent');

        $this->assertNull($parent->refresh()->parent_id);
    }

    public function test_other_users_cannot_change_a_folder()
    {
        $folder = BoardFolder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('board-folders.update', $folder), ['name' => 'Mine'])
            ->assertForbidden();

        $this->delete(route('board-folders.destroy', $folder))->assertForbidden();
    }

    public function test_deleting_a_folder_deletes_everything_inside_it()
    {
        $user = User::factory()->create();
        $parent = BoardFolder::factory()->for($user)->create();
        $child = BoardFolder::factory()->for($user)->create(['parent_id' => $parent->id]);
        $outer = Board::factory()->for($user)->create(['folder_id' => $parent->id]);
        $inner = Board::factory()->for($user)->create(['folder_id' => $child->id]);
        $elsewhere = Board::factory()->for($user)->create();

        $this->actingAs($user)->delete(route('board-folders.destroy', $parent))->assertRedirect();

        $this->assertModelMissing($parent);
        $this->assertModelMissing($child);
        $this->assertModelMissing($outer);
        $this->assertModelMissing($inner);
        $this->assertModelExists($elsewhere);
    }

    /**
     * A board carrying one sticky with this label.
     *
     * @return array<string, mixed>
     */
    private static function written(string $text): array
    {
        return ['items' => [['id' => 'i1', 'kind' => 'sticky', 'text' => $text]]];
    }

    public function test_the_searchable_text_follows_what_is_written_on_the_board()
    {
        $board = Board::factory()->create(['content' => self::written('First draft')]);
        $this->assertStringContainsString('First draft', (string) $board->plain_text);

        $this->actingAs($board->user)->patchJson(route('boards.update', $board), ['content' => self::written('Second draft')]);
        $this->assertStringContainsString('Second draft', $board->refresh()->plain_text);
    }

    public function test_search_finds_titles_and_body_text_in_every_folder()
    {
        $user = User::factory()->create();
        $work = BoardFolder::factory()->for($user)->create(['name' => 'Work']);
        $lakeshore = BoardFolder::factory()->for($user)->create(['name' => 'Lakeshore', 'parent_id' => $work->id]);
        $byTitle = Board::factory()->for($user)->create(['title' => 'Watchdog boards', 'content' => self::written('nothing here')]);
        $byBody = Board::factory()->for($user)->create([
            'title' => 'Step 4',
            'folder_id' => $lakeshore->id,
            'content' => self::written('A run silent for 15 minutes is restarted by the WATCHDOG every 6 hours.'),
        ]);
        Board::factory()->for($user)->create(['title' => 'Unrelated', 'content' => self::written('nothing')]);
        Board::factory()->create(['title' => 'Someone else’s watchdog']);

        $this->actingAs($user)
            ->get(route('boards.index', ['q' => 'watchdog', 'folder' => $work->ref_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.q', 'watchdog')
                ->has('boards', 2)
                ->where('boards', fn ($boards) => collect($boards)->pluck('ref_id')->sort()->values()->all()
                    === collect([$byTitle->ref_id, $byBody->ref_id])->sort()->values()->all())
                ->where('boards', fn ($boards) => collect($boards)->firstWhere('ref_id', $byBody->ref_id)['path'] === 'Work / Lakeshore')
                ->where('boards', fn ($boards) => str_contains(collect($boards)->firstWhere('ref_id', $byBody->ref_id)['snippet'], 'WATCHDOG every 6 hours'))
                ->has('folders', 0)
            );

        $this->get(route('boards.index', ['q' => 'lake']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('folders', 1)
                ->where('folders.0.path', 'Work / Lakeshore')
            );
    }

    public function test_notes_can_be_sorted()
    {
        $user = User::factory()->create();
        $old = Board::factory()->for($user)->create(['title' => 'Beta', 'created_at' => now()->subDays(3), 'updated_at' => now()]);
        $new = Board::factory()->for($user)->create(['title' => 'Alpha', 'created_at' => now()->subDay(), 'updated_at' => now()->subDays(2)]);

        $order = fn (string $sort) => $this->actingAs($user)->get(route('boards.index', ['sort' => $sort]))
            ->viewData('page')['props']['boards'];

        $this->assertSame([$old->ref_id, $new->ref_id], array_column($order('edited'), 'ref_id'));
        $this->assertSame([$new->ref_id, $old->ref_id], array_column($order('created'), 'ref_id'));
        $this->assertSame([$new->ref_id, $old->ref_id], array_column($order('title'), 'ref_id'));
    }

    public function test_notes_can_be_filtered_by_when_they_were_edited()
    {
        $user = User::factory()->create();
        BoardFolder::factory()->for($user)->create();
        $recent = Board::factory()->for($user)->create(['updated_at' => now()->subDays(2)]);
        Board::factory()->for($user)->create(['updated_at' => now()->subDays(20)]);
        Board::factory()->for($user)->create(['updated_at' => now()->subDays(60)]);

        $count = fn (string $edited) => count($this->actingAs($user)->get(route('boards.index', ['edited' => $edited]))
            ->viewData('page')['props']['boards']);

        $this->assertSame(1, $count('week'));
        $this->assertSame(2, $count('month'));

        $this->get(route('boards.index', ['edited' => 'week']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('boards.0.ref_id', $recent->ref_id)
                ->has('folders', 0)
            );
    }

    public function test_unknown_sort_and_filter_values_are_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('boards.index', ['sort' => 'id; drop table']))->assertSessionHasErrors('sort');
        $this->get(route('boards.index', ['edited' => 'forever']))->assertSessionHasErrors('edited');
    }

    public function test_folder_names_cannot_contain_a_slash()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('board-folders.store'), ['name' => 'KT/Plan'])->assertSessionHasErrors('name');
        $this->assertSame(0, $user->boardFolders()->count());
    }
}
