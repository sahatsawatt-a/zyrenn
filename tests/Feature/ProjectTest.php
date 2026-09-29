<?php

namespace Tests\Feature;

use App\Models\Board\Board;
use App\Models\Drive\DriveFile;
use App\Models\Note\Note;
use App\Models\Note\NoteFolder;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Projects as a place: making and running one, and the notes, boards, tables
 * and Drive under /p/{project}, reached by role.
 */
class ProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The settings page is newer than the last asset build
        $this->withoutVite();
    }

    private function member(Project $project, string $role): User
    {
        $user = User::factory()->create();
        $project->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private function projectNote(Project $project, string $title = 'Shared'): Note
    {
        $note = (new Note(['title' => $title]))->ownedBy($project, User::factory()->create());
        $note->save();

        return $note;
    }

    public function test_making_a_project_puts_its_maker_in_charge_and_opens_it()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), ['name' => 'Lakeshore']);

        $project = Project::query()->sole();
        $response->assertRedirect(route('projects.notes.index', $project));
        $this->assertSame('Lakeshore', $project->name);
        $this->assertSame(Project::OWNER, $project->roleOf($user));
        $this->assertSame($user->id, $project->created_by);
    }

    public function test_only_members_get_into_a_project()
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('projects.notes.index', $project))
            ->assertForbidden();

        $this->actingAs($this->member($project, Project::VIEWER))
            ->get(route('projects.notes.index', $project))
            ->assertOk();
    }

    public function test_a_projects_list_shows_its_things_and_not_the_members_own()
    {
        $project = Project::factory()->create();
        $viewer = $this->member($project, Project::VIEWER);
        $shared = $this->projectNote($project);
        Note::factory()->for($viewer)->create();

        $this->actingAs($viewer)
            ->get(route('projects.notes.index', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('notes/Index')
                ->has('notes', 1)
                ->where('notes.0.ref_id', $shared->ref_id)
                ->where('project', ['ref_id' => $project->ref_id, 'name' => $project->name, 'role' => Project::VIEWER])
                ->where('projects', [['ref_id' => $project->ref_id, 'name' => $project->name]])
            );

        $this->actingAs($viewer)
            ->get(route('notes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('notes', 1)
                ->where('project', null)
            );
    }

    public function test_the_page_of_a_project_thing_knows_its_project()
    {
        $project = Project::factory()->create();
        $note = $this->projectNote($project);

        $this->actingAs($this->member($project, Project::EDITOR))
            ->get(route('notes.show', $note))
            ->assertInertia(fn (Assert $page) => $page->where('project.ref_id', $project->ref_id));
    }

    public function test_editors_make_things_in_a_project_and_viewers_cannot()
    {
        $project = Project::factory()->create();
        $editor = $this->member($project, Project::EDITOR);

        $this->actingAs($this->member($project, Project::VIEWER))
            ->post(route('projects.notes.store', $project))
            ->assertForbidden();

        $this->actingAs($this->member($project, Project::VIEWER))
            ->post(route('projects.note-folders.store', $project), ['name' => 'Plans'])
            ->assertForbidden();

        $this->actingAs($editor)->post(route('projects.note-folders.store', $project), ['name' => 'Plans']);
        $folder = $project->noteFolders()->sole();
        $this->assertSame($editor->id, $folder->created_by);

        $response = $this->actingAs($editor)
            ->post(route('projects.notes.store', $project), ['folder' => $folder->ref_id]);

        $note = $project->notes()->sole();
        $response->assertRedirect(route('notes.show', $note));
        $this->assertNull($note->user_id);
        $this->assertSame($editor->id, $note->created_by);
        $this->assertSame($folder->id, $note->folder_id);
        $this->assertSame(0, $editor->notes()->count());
    }

    public function test_a_project_thing_is_made_only_in_the_projects_own_folders()
    {
        $project = Project::factory()->create();
        $editor = $this->member($project, Project::EDITOR);
        $mine = NoteFolder::factory()->for($editor)->create();

        $this->actingAs($editor)
            ->post(route('projects.notes.store', $project), ['folder' => $mine->ref_id])
            ->assertSessionHasErrors('folder');
    }

    public function test_deleting_a_project_thing_goes_back_to_the_projects_list()
    {
        $project = Project::factory()->create();
        $note = $this->projectNote($project);

        $this->actingAs($this->member($project, Project::EDITOR))
            ->delete(route('notes.destroy', $note))
            ->assertRedirect(route('projects.notes.index', $project));
    }

    public function test_uploads_in_a_project_go_to_its_drive_and_its_members_can_open_them()
    {
        Storage::fake(DriveFile::DISK);

        $project = Project::factory()->create();
        $editor = $this->member($project, Project::EDITOR);

        $this->actingAs($editor)
            ->postJson(route('projects.drive.files.store', $project), ['files' => [UploadedFile::fake()->create('brief.pdf', 4, 'application/pdf')]])
            ->assertCreated();

        $file = $project->driveFiles()->sole();
        $this->assertStringStartsWith($project->driveDirectory().'/', $file->path);
        $this->assertSame($editor->id, $file->created_by);

        $this->actingAs($this->member($project, Project::VIEWER))
            ->get(route('drive.files.show', $file))
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('drive.files.show', $file))
            ->assertForbidden();
    }

    public function test_pickers_in_a_project_offer_the_projects_things()
    {
        $project = Project::factory()->create();
        $viewer = $this->member($project, Project::VIEWER);
        $shared = Board::make(['title' => 'Shared'])->ownedBy($project, $viewer);
        $shared->save();
        Board::factory()->for($viewer)->create(['title' => 'Mine']);

        $this->actingAs($viewer)
            ->getJson(route('projects.boards.pick', $project))
            ->assertOk()
            ->assertJsonCount(1, 'boards')
            ->assertJsonPath('boards.0.ref_id', $shared->ref_id);
    }

    public function test_only_owners_rename_or_delete_a_project()
    {
        $project = Project::factory()->create(['name' => 'Old']);
        $editor = $this->member($project, Project::EDITOR);
        $owner = $this->member($project, Project::OWNER);

        $this->actingAs($editor)->patch(route('projects.update', $project), ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($editor)->delete(route('projects.destroy', $project))->assertForbidden();

        $this->actingAs($owner)->patch(route('projects.update', $project), ['name' => 'New']);
        $this->assertSame('New', $project->fresh()->name);

        $this->actingAs($owner)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect(route('notes.index'));
        $this->assertModelMissing($project);
    }

    public function test_settings_show_the_members_and_what_the_user_may_do()
    {
        $project = Project::factory()->create();
        $editor = $this->member($project, Project::EDITOR);

        $this->actingAs($editor)
            ->get(route('projects.edit', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Settings')
                ->where('settings.ref_id', $project->ref_id)
                ->has('settings.members', 1)
                ->where('settings.members.0.role', Project::EDITOR)
                ->where('settings.can', ['update' => false, 'delete' => false])
            );

        $this->actingAs(User::factory()->create())
            ->get(route('projects.edit', $project))
            ->assertForbidden();
    }

    public function test_an_account_cant_be_deleted_while_it_alone_runs_a_project()
    {
        $user = User::factory()->create();
        $project = Project::factory()->withMember($user)->create(['name' => 'Lakeshore']);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors(['projects' => 'You still run “Lakeshore”. Delete it first.']);
        $this->assertModelExists($user);

        // With someone else to run it, the project can do without them
        $this->member($project, Project::OWNER);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertModelMissing($user);
        $this->assertModelExists($project);
    }
}
