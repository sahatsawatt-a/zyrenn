<?php

namespace Tests\Feature;

use App\Models\Note\Note;
use App\Models\Note\NoteVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteVersionTest extends TestCase
{
    use RefreshDatabase;

    private function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    public function test_editing_snapshots_once_per_stretch_of_editing()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)->patchJson(route('notes.update', $note), ['content' => $this->doc('one')])->assertOk();
        $this->actingAs($user)->patchJson(route('notes.update', $note), ['content' => $this->doc('two')])->assertOk();

        $this->assertSame(1, $note->versions()->count());

        $this->travel(NoteVersion::INTERVAL_MINUTES + 1)->minutes();
        $this->actingAs($user)->patchJson(route('notes.update', $note), ['content' => $this->doc('three')])->assertOk();

        $this->assertSame(2, $note->versions()->count());
    }

    public function test_a_version_can_be_pinned_unpinned_and_labelled()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $ref = $this->actingAs($user)
            ->postJson(route('notes.versions.store', $note), ['label' => 'Draft 1'])
            ->assertCreated()
            ->assertJsonPath('pinned', true)
            ->json('ref_id');

        $version = $note->versions()->sole();
        $this->assertSame($ref, $version->ref_id);

        $this->actingAs($user)
            ->patchJson(route('notes.versions.update', [$note, $version]), ['pinned' => false])
            ->assertOk()
            ->assertJsonPath('pinned', false)
            ->assertJsonPath('label', null);

        $this->actingAs($user)
            ->patchJson(route('notes.versions.update', [$note, $version]), ['pinned' => true, 'label' => 'Final'])
            ->assertJsonPath('pinned', true)
            ->assertJsonPath('label', 'Final');
    }

    public function test_pruning_keeps_pinned_versions()
    {
        $note = Note::factory()->create();
        $pinned = $note->snapshot(true, 'keep');

        foreach (range(1, NoteVersion::KEEP_UNPINNED + 5) as $ignored) {
            $note->snapshot();
        }

        $this->assertSame(NoteVersion::KEEP_UNPINNED, $note->versions()->whereNull('pinned_at')->count());
        $this->assertTrue($pinned->fresh()->exists);
    }

    public function test_restore_replaces_the_note_and_snapshots_what_it_replaced()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create(['title' => 'Old', 'content' => $this->doc('old')]);
        $version = $note->snapshot(true);
        $note->update(['title' => 'New', 'content' => $this->doc('new')]);

        $this->actingAs($user)->postJson(route('notes.versions.restore', [$note, $version]))->assertOk();

        $note->refresh();
        $this->assertSame('Old', $note->title);
        $this->assertSame($this->doc('old'), $this->withoutBlockIds($note->content));
        $this->assertSame('New', $note->versions()->first()->title);
    }

    public function test_other_users_cannot_touch_versions()
    {
        $note = Note::factory()->create();
        $version = $note->snapshot();
        $other = User::factory()->create();

        $this->actingAs($other)->getJson(route('notes.versions.index', $note))->assertForbidden();
        $this->actingAs($other)->postJson(route('notes.versions.store', $note))->assertForbidden();
        $this->actingAs($other)->postJson(route('notes.versions.restore', [$note, $version]))->assertForbidden();
        $this->actingAs($other)->deleteJson(route('notes.versions.destroy', [$note, $version]))->assertForbidden();
    }

    public function test_a_version_of_another_note_is_not_found()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();
        $foreign = Note::factory()->for($user)->create()->snapshot();

        $this->actingAs($user)->deleteJson(route('notes.versions.destroy', [$note, $foreign]))->assertNotFound();
    }
}
