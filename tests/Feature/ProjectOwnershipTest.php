<?php

namespace Tests\Feature;

use App\Models\Board\Board;
use App\Models\Drive\DriveFile;
use App\Models\Note\Note;
use App\Models\Note\NoteFolder;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Table\TableStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a project owns lives as long as the project, whoever made it; what a
 * user owns lives as long as the user. Members reach a project's things by
 * their role.
 */
class ProjectOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function projectNote(Project $project, ?User $by = null): Note
    {
        $note = (new Note(['title' => 'Shared']))->ownedBy($project, $by ?? User::factory()->create());
        $note->save();

        return $note;
    }

    public function test_a_users_things_and_a_projects_things_stay_apart()
    {
        $user = User::factory()->create();
        $project = Project::factory()->withMember($user)->create();
        $mine = Note::factory()->for($user)->create();
        $shared = $this->projectNote($project, $user);

        $this->assertSame([$mine->id], $user->notes()->pluck('id')->all());
        $this->assertSame([$shared->id], $project->notes()->pluck('id')->all());
        $this->assertTrue($shared->owner()->is($project));
        $this->assertTrue($shared->creator->is($user));
        $this->assertNull($shared->user_id);
    }

    public function test_a_users_own_things_record_them_as_the_maker()
    {
        $note = Note::factory()->create();

        $this->assertSame($note->user_id, $note->created_by);
    }

    public function test_project_things_outlive_the_member_who_made_them()
    {
        $maker = User::factory()->create();
        $project = Project::factory()->withMember($maker)->create();
        $shared = $this->projectNote($project, $maker);
        $own = Note::factory()->for($maker)->create();

        $maker->delete();

        $this->assertModelMissing($own);
        $this->assertModelExists($shared);
        $this->assertNull($shared->fresh()->created_by);
    }

    public function test_deleting_a_project_deletes_what_it_owns()
    {
        Storage::fake(DriveFile::DISK);

        $project = Project::factory()->create();
        $note = $this->projectNote($project);
        $folder = NoteFolder::make(['name' => 'Plans'])->ownedBy($project, User::factory()->create());
        $folder->save();
        $table = Table::make()->ownedBy($project, User::factory()->create());
        $table->save();
        TableStorage::create($table);
        $file = DriveFile::store(UploadedFile::fake()->create('brief.txt', 1), $project);

        $project->delete();

        $this->assertModelMissing($note);
        $this->assertModelMissing($folder);
        $this->assertModelMissing($table);
        $this->assertModelMissing($file);
        $this->assertFalse(Schema::hasTable(TableStorage::physicalName($table)));
        Storage::disk(DriveFile::DISK)->assertMissing($file->path);
        $this->assertStringStartsWith('drive/projects/'.$project->id.'/', $file->path);
    }

    public function test_members_see_project_things_and_only_owners_and_editors_change_them()
    {
        $project = Project::factory()->create();
        $note = $this->projectNote($project);

        foreach ([Project::OWNER => true, Project::EDITOR => true, Project::VIEWER => false] as $role => $canChange) {
            $member = User::factory()->create();
            $project->members()->attach($member, ['role' => $role]);

            $this->assertTrue($member->can('view', $note), $role);
            $this->assertSame($canChange, $member->can('update', $note), $role);
            $this->assertSame($canChange, $member->can('delete', $note), $role);
        }

        $outsider = User::factory()->create();
        $this->assertFalse($outsider->can('view', $note));
        $this->assertFalse($outsider->can('update', $note));
    }

    public function test_members_reach_project_things_through_their_pages()
    {
        $project = Project::factory()->create();
        $board = Board::make(['title' => 'Plan'])->ownedBy($project, User::factory()->create());
        $board->save();
        $viewer = User::factory()->create();
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);

        $this->actingAs($viewer)->get(route('boards.show', $board))->assertOk();
        $this->actingAs($viewer)->patchJson(route('boards.update', $board), ['title' => 'Mine now'])->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('boards.show', $board))->assertForbidden();
    }

    public function test_a_project_thing_moves_only_between_the_projects_folders()
    {
        $editor = User::factory()->create();
        $project = Project::factory()->withMember($editor, Project::EDITOR)->create();
        $note = $this->projectNote($project, $editor);
        $theirs = NoteFolder::make(['name' => 'Shared'])->ownedBy($project, $editor);
        $theirs->save();
        $mine = NoteFolder::factory()->for($editor)->create();

        $this->actingAs($editor)
            ->patch(route('notes.update', $note), ['folder' => $mine->ref_id])
            ->assertSessionHasErrors('folder');

        $this->actingAs($editor)
            ->patch(route('notes.update', $note), ['folder' => $theirs->ref_id])
            ->assertSessionHasNoErrors();

        $this->assertSame($theirs->id, $note->fresh()->folder_id);
    }
}
