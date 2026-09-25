<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NoteFolderTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_the_open_folders_contents_only()
    {
        $user = User::factory()->create();
        $work = NoteFolder::factory()->for($user)->create(['name' => 'Work']);
        $inner = NoteFolder::factory()->for($user)->create(['name' => 'Lakeshore', 'parent_id' => $work->id]);
        $top = Note::factory()->for($user)->create(['title' => 'Top']);
        $filed = Note::factory()->for($user)->create(['title' => 'Filed', 'folder_id' => $work->id]);
        NoteFolder::factory()->create();

        $this->actingAs($user)
            ->get(route('notes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('notes/Index')
                ->where('folder', null)
                ->has('folders', 1)
                ->where('folders.0.name', 'Work')
                ->has('notes', 1)
                ->where('notes.0.ref_id', $top->ref_id)
                ->where('allFolders', [
                    ['ref_id' => $work->ref_id, 'path' => 'Work'],
                    ['ref_id' => $inner->ref_id, 'path' => 'Work / Lakeshore'],
                ])
            );

        $this->get(route('notes.index', ['folder' => $work->ref_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('folder.ref_id', $work->ref_id)
                ->where('breadcrumbs', [['ref_id' => $work->ref_id, 'name' => 'Work']])
                ->has('folders', 1)
                ->where('notes.0.ref_id', $filed->ref_id)
            );
    }

    public function test_another_users_folder_cannot_be_opened()
    {
        $folder = NoteFolder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('notes.index', ['folder' => $folder->ref_id]))
            ->assertNotFound();
    }

    public function test_a_new_note_can_be_created_inside_a_folder()
    {
        $user = User::factory()->create();
        $folder = NoteFolder::factory()->for($user)->create();

        $this->actingAs($user)->post(route('notes.store'), ['folder' => $folder->ref_id]);

        $this->assertSame($folder->id, $user->notes()->sole()->folder_id);
    }

    public function test_a_note_cannot_be_created_in_another_users_folder()
    {
        $folder = NoteFolder::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('notes.store'), ['folder' => $folder->ref_id])
            ->assertSessionHasErrors('folder');

        $this->assertSame(0, $user->notes()->count());
    }

    public function test_the_note_page_shows_its_folder_path()
    {
        $user = User::factory()->create();
        $work = NoteFolder::factory()->for($user)->create(['name' => 'Work']);
        $inner = NoteFolder::factory()->for($user)->create(['name' => 'Lakeshore', 'parent_id' => $work->id]);
        $note = Note::factory()->for($user)->create(['folder_id' => $inner->id]);

        $this->actingAs($user)
            ->get(route('notes.show', $note))
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
        $note = Note::factory()->for($user)->create();
        $folder = NoteFolder::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('notes.index'))
            ->patch(route('notes.update', $note), ['folder' => $folder->ref_id, 'title' => 'Moved'])
            ->assertRedirect(route('notes.index'));

        $note->refresh();
        $this->assertSame($folder->id, $note->folder_id);
        $this->assertSame('Moved', $note->title);

        $this->patch(route('notes.update', $note), ['folder' => null]);
        $this->assertNull($note->refresh()->folder_id);
    }

    public function test_autosave_still_answers_with_json()
    {
        $note = Note::factory()->create();

        $this->actingAs($note->user)
            ->patchJson(route('notes.update', $note), ['title' => 'Saved'])
            ->assertOk()
            ->assertJsonStructure(['updated_at']);
    }

    public function test_a_note_cannot_be_moved_into_another_users_folder()
    {
        $note = Note::factory()->create();
        $folder = NoteFolder::factory()->create();

        $this->actingAs($note->user)
            ->patch(route('notes.update', $note), ['folder' => $folder->ref_id])
            ->assertSessionHasErrors('folder');

        $this->assertNull($note->refresh()->folder_id);
    }

    public function test_deleting_a_note_returns_to_its_folder()
    {
        $user = User::factory()->create();
        $folder = NoteFolder::factory()->for($user)->create();
        $note = Note::factory()->for($user)->create(['folder_id' => $folder->id]);

        $this->actingAs($user)
            ->delete(route('notes.destroy', $note))
            ->assertRedirect(route('notes.index', ['folder' => $folder->ref_id]));
    }

    public function test_folders_can_be_created_renamed_and_nested()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('note-folders.store'), ['name' => 'KT Plan'])->assertRedirect();
        $plan = $user->noteFolders()->sole();

        $this->post(route('note-folders.store'), ['name' => 'Lakeshore', 'parent' => $plan->ref_id]);
        $this->assertSame($plan->id, $user->noteFolders()->where('name', 'Lakeshore')->sole()->parent_id);

        $this->patch(route('note-folders.update', $plan), ['name' => 'Handover']);
        $this->assertSame('Handover', $plan->refresh()->name);
    }

    public function test_a_folder_cannot_be_moved_into_itself_or_a_descendant()
    {
        $user = User::factory()->create();
        $parent = NoteFolder::factory()->for($user)->create();
        $child = NoteFolder::factory()->for($user)->create(['parent_id' => $parent->id]);

        $this->actingAs($user)
            ->patch(route('note-folders.update', $parent), ['parent' => $child->ref_id])
            ->assertSessionHasErrors('parent');

        $this->patch(route('note-folders.update', $parent), ['parent' => $parent->ref_id])
            ->assertSessionHasErrors('parent');

        $this->assertNull($parent->refresh()->parent_id);
    }

    public function test_other_users_cannot_change_a_folder()
    {
        $folder = NoteFolder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('note-folders.update', $folder), ['name' => 'Mine'])
            ->assertForbidden();

        $this->delete(route('note-folders.destroy', $folder))->assertForbidden();
    }

    public function test_deleting_a_folder_deletes_everything_inside_it()
    {
        $user = User::factory()->create();
        $parent = NoteFolder::factory()->for($user)->create();
        $child = NoteFolder::factory()->for($user)->create(['parent_id' => $parent->id]);
        $outer = Note::factory()->for($user)->create(['folder_id' => $parent->id]);
        $inner = Note::factory()->for($user)->create(['folder_id' => $child->id]);
        $elsewhere = Note::factory()->for($user)->create();

        $this->actingAs($user)->delete(route('note-folders.destroy', $parent))->assertRedirect();

        $this->assertModelMissing($parent);
        $this->assertModelMissing($child);
        $this->assertModelMissing($outer);
        $this->assertModelMissing($inner);
        $this->assertModelExists($elsewhere);
    }

    private static function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    public function test_the_searchable_text_follows_the_content()
    {
        $note = Note::factory()->create(['content' => self::doc('First draft')]);
        $this->assertStringContainsString('First draft', $note->plain_text);

        $this->actingAs($note->user)->patchJson(route('notes.update', $note), ['content' => self::doc('Second draft')]);
        $this->assertStringContainsString('Second draft', $note->refresh()->plain_text);
    }

    public function test_search_finds_titles_and_body_text_in_every_folder()
    {
        $user = User::factory()->create();
        $work = NoteFolder::factory()->for($user)->create(['name' => 'Work']);
        $lakeshore = NoteFolder::factory()->for($user)->create(['name' => 'Lakeshore', 'parent_id' => $work->id]);
        $byTitle = Note::factory()->for($user)->create(['title' => 'Watchdog notes', 'content' => self::doc('nothing here')]);
        $byBody = Note::factory()->for($user)->create([
            'title' => 'Step 4',
            'folder_id' => $lakeshore->id,
            'content' => self::doc('A run silent for 15 minutes is restarted by the WATCHDOG every 6 hours.'),
        ]);
        Note::factory()->for($user)->create(['title' => 'Unrelated', 'content' => self::doc('nothing')]);
        Note::factory()->create(['title' => 'Someone else’s watchdog']);

        $this->actingAs($user)
            ->get(route('notes.index', ['q' => 'watchdog', 'folder' => $work->ref_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.q', 'watchdog')
                ->has('notes', 2)
                ->where('notes', fn ($notes) => collect($notes)->pluck('ref_id')->sort()->values()->all()
                    === collect([$byTitle->ref_id, $byBody->ref_id])->sort()->values()->all())
                ->where('notes', fn ($notes) => collect($notes)->firstWhere('ref_id', $byBody->ref_id)['path'] === 'Work / Lakeshore')
                ->where('notes', fn ($notes) => str_contains(collect($notes)->firstWhere('ref_id', $byBody->ref_id)['snippet'], 'WATCHDOG every 6 hours'))
                ->has('folders', 0)
            );

        $this->get(route('notes.index', ['q' => 'lake']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('folders', 1)
                ->where('folders.0.path', 'Work / Lakeshore')
            );
    }

    public function test_notes_can_be_sorted()
    {
        $user = User::factory()->create();
        $old = Note::factory()->for($user)->create(['title' => 'Beta', 'created_at' => now()->subDays(3), 'updated_at' => now()]);
        $new = Note::factory()->for($user)->create(['title' => 'Alpha', 'created_at' => now()->subDay(), 'updated_at' => now()->subDays(2)]);

        $order = fn (string $sort) => $this->actingAs($user)->get(route('notes.index', ['sort' => $sort]))
            ->viewData('page')['props']['notes'];

        $this->assertSame([$old->ref_id, $new->ref_id], array_column($order('edited'), 'ref_id'));
        $this->assertSame([$new->ref_id, $old->ref_id], array_column($order('created'), 'ref_id'));
        $this->assertSame([$new->ref_id, $old->ref_id], array_column($order('title'), 'ref_id'));
    }

    public function test_notes_can_be_filtered_by_when_they_were_edited()
    {
        $user = User::factory()->create();
        NoteFolder::factory()->for($user)->create();
        $recent = Note::factory()->for($user)->create(['updated_at' => now()->subDays(2)]);
        Note::factory()->for($user)->create(['updated_at' => now()->subDays(20)]);
        Note::factory()->for($user)->create(['updated_at' => now()->subDays(60)]);

        $count = fn (string $edited) => count($this->actingAs($user)->get(route('notes.index', ['edited' => $edited]))
            ->viewData('page')['props']['notes']);

        $this->assertSame(1, $count('week'));
        $this->assertSame(2, $count('month'));

        $this->get(route('notes.index', ['edited' => 'week']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notes.0.ref_id', $recent->ref_id)
                ->has('folders', 0)
            );
    }

    public function test_unknown_sort_and_filter_values_are_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('notes.index', ['sort' => 'id; drop table']))->assertSessionHasErrors('sort');
        $this->get(route('notes.index', ['edited' => 'forever']))->assertSessionHasErrors('edited');
    }

    public function test_folder_names_cannot_contain_a_slash()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('note-folders.store'), ['name' => 'KT/Plan'])->assertSessionHasErrors('name');
        $this->assertSame(0, $user->noteFolders()->count());
    }
}
