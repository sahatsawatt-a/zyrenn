<?php

namespace Tests\Feature;

use App\Models\Note\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('notes.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_only_the_users_own_notes()
    {
        $user = User::factory()->create();
        $mine = Note::factory()->for($user)->create();
        Note::factory()->create();

        $this->actingAs($user)
            ->get(route('notes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('notes/Index')
                ->has('notes', 1)
                ->where('notes.0.ref_id', $mine->ref_id)
                ->missing('notes.0.id')
            );
    }

    public function test_store_creates_a_blank_note_and_redirects_to_it()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('notes.store'));

        $note = $user->notes()->sole();
        $response->assertRedirect(route('notes.show', $note));
        $this->assertSame('', $note->title);
        $this->assertNull($note->content);
    }

    public function test_owner_can_view_and_update_a_note()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('notes.show', $note))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('notes/Show')
                ->where('note.ref_id', $note->ref_id)
                ->missing('note.id')
            );

        $content = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $this->actingAs($user)
            ->patchJson(route('notes.update', $note), ['title' => 'Renamed', 'content' => $content])
            ->assertOk()
            ->assertJsonStructure(['updated_at']);

        $note->refresh();
        $this->assertSame('Renamed', $note->title);
        $this->assertSame($content, $note->content);
    }

    public function test_notes_are_addressed_by_ref_id_not_numeric_id()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $note->ref_id);
        $this->assertStringEndsWith('/notes/'.$note->ref_id, route('notes.show', $note));

        $this->actingAs($user)->get('/notes/'.$note->id)->assertNotFound();
        $this->actingAs($user)->get('/notes/'.$note->ref_id)->assertOk();
    }

    public function test_autosave_keeps_spaces_at_the_edges_of_text_nodes()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();
        $content = ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Energy is '],
                ['type' => 'inlineMath', 'attrs' => ['latex' => 'E = mc^2']],
                ['type' => 'text', 'text' => ' done.'],
            ],
        ]]];

        $this->actingAs($user)
            ->patchJson(route('notes.update', $note), ['content' => $content])
            ->assertOk();

        $this->assertSame($content, $note->refresh()->content);
    }

    public function test_clearing_the_title_stores_an_empty_string()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)
            ->patchJson(route('notes.update', $note), ['title' => null])
            ->assertOk();

        $this->assertSame('', $note->refresh()->title);
    }

    public function test_owner_can_toggle_wide_layout()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->assertFalse($note->is_wide);

        $this->actingAs($user)
            ->patchJson(route('notes.update', $note), ['is_wide' => true])
            ->assertOk();

        $this->assertTrue($note->refresh()->is_wide);

        $this->actingAs($user)
            ->get(route('notes.show', $note))
            ->assertInertia(fn (Assert $page) => $page->where('note.is_wide', true));

        $this->actingAs($user)
            ->patchJson(route('notes.update', $note), ['is_wide' => 'sideways'])
            ->assertUnprocessable();
    }

    public function test_users_cannot_access_other_users_notes()
    {
        $user = User::factory()->create();
        $note = Note::factory()->create();

        $this->actingAs($user)->get(route('notes.show', $note))->assertForbidden();
        $this->actingAs($user)->patchJson(route('notes.update', $note), ['title' => 'x'])->assertForbidden();
        $this->actingAs($user)->delete(route('notes.destroy', $note))->assertForbidden();

        $this->assertModelExists($note);
    }

    public function test_owner_can_delete_a_note()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)
            ->delete(route('notes.destroy', $note))
            ->assertRedirect(route('notes.index'));

        $this->assertModelMissing($note);
    }

    public function test_notes_are_deleted_with_their_user()
    {
        $note = Note::factory()->create();

        $note->user->delete();

        $this->assertModelMissing($note);
    }
}
