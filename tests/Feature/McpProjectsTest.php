<?php

namespace Tests\Feature;

use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Drive\UploadFile;
use App\Mcp\Tools\Notes\CreateNote;
use App\Mcp\Tools\Notes\DeleteNote;
use App\Mcp\Tools\Notes\GetNote;
use App\Mcp\Tools\Notes\ListNotes;
use App\Mcp\Tools\Notes\UpdateNote;
use App\Mcp\Tools\Projects\ListProjects;
use App\Mcp\Tools\Tables\CreateTable;
use App\Models\Drive\DriveFile;
use App\Models\Note\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * The MCP tools in a project: named by "project", read by any member,
 * changed only by its owners and editors.
 */
class McpProjectsTest extends TestCase
{
    use RefreshDatabase;

    private function inProject(Project $project, string $role): User
    {
        $user = User::factory()->create();
        $project->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private function projectNote(Project $project, string $title): Note
    {
        $note = (new Note(['title' => $title]))->ownedBy($project, User::factory()->create());
        $note->save();

        return $note;
    }

    public function test_list_projects_shows_the_users_projects_and_role()
    {
        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $editor = $this->inProject($project, Project::EDITOR);
        Project::factory()->create(['name' => 'Not theirs']);

        UserServer::actingAs($editor)
            ->tool(ListProjects::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('projects', 1)
                ->where('projects.0.ref_id', $project->ref_id)
                ->where('projects.0.name', 'Lakeshore')
                ->where('projects.0.role', Project::EDITOR)
                ->where('projects.0.members_count', 1)
                ->etc());
    }

    public function test_a_project_is_named_by_ref_id_or_name_and_keeps_to_its_own_things()
    {
        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $viewer = $this->inProject($project, Project::VIEWER);
        $shared = $this->projectNote($project, 'Site survey');
        Note::factory()->for($viewer)->create(['title' => 'Mine']);

        foreach ([$project->ref_id, 'lakeshore'] as $named) {
            UserServer::actingAs($viewer)
                ->tool(ListNotes::class, ['project' => $named])
                ->assertOk()
                ->assertStructuredContent(fn (AssertableJson $json) => $json
                    ->has('notes', 1)
                    ->where('notes.0.ref_id', $shared->ref_id)
                    ->where('notes.0.project', $project->ref_id)
                    ->where('notes.0.user_id', null)
                    ->etc());
        }

        UserServer::actingAs($viewer)
            ->tool(ListNotes::class)
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('notes', 1)
                ->where('notes.0.title', 'Mine')
                ->where('notes.0.project', null)
                ->etc());

        // Found only where it lives
        UserServer::actingAs($viewer)
            ->tool(GetNote::class, ['ref_id' => $shared->ref_id])
            ->assertHasErrors(["Note {$shared->ref_id} was not found."]);
    }

    public function test_only_members_reach_a_project()
    {
        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $this->projectNote($project, 'Site survey');

        UserServer::actingAs(User::factory()->create())
            ->tool(ListNotes::class, ['project' => $project->ref_id])
            ->assertHasErrors(["The user is in no project \"{$project->ref_id}\". See list-projects."]);
    }

    public function test_a_viewer_reads_but_changes_nothing()
    {
        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $viewer = $this->inProject($project, Project::VIEWER);
        $shared = $this->projectNote($project, 'Site survey');

        UserServer::actingAs($viewer)
            ->tool(GetNote::class, ['project' => 'Lakeshore', 'ref_id' => $shared->ref_id])
            ->assertOk();

        $refused = 'The user is a viewer in "Lakeshore": only its owners and editors can change what is in it.';

        UserServer::actingAs($viewer)
            ->tool(CreateNote::class, ['project' => 'Lakeshore', 'title' => 'Sneaky'])
            ->assertHasErrors([$refused]);

        UserServer::actingAs($viewer)
            ->tool(UpdateNote::class, ['project' => 'Lakeshore', 'ref_id' => $shared->ref_id, 'title' => 'Changed'])
            ->assertHasErrors([$refused]);

        UserServer::actingAs($viewer)
            ->tool(DeleteNote::class, ['project' => 'Lakeshore', 'ref_id' => $shared->ref_id])
            ->assertHasErrors([$refused]);

        $this->assertSame('Site survey', $shared->fresh()->title);
        $this->assertSame(1, $project->notes()->count());
    }

    public function test_an_editor_makes_things_in_the_project_as_themselves()
    {
        Storage::fake(DriveFile::DISK);

        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $editor = $this->inProject($project, Project::EDITOR);

        UserServer::actingAs($editor)
            ->tool(CreateNote::class, ['project' => 'Lakeshore', 'title' => 'Plan', 'folder' => 'Surveys/2026'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('project', $project->ref_id)
                ->where('folder', 'Surveys/2026')
                ->etc());

        UserServer::actingAs($editor)
            ->tool(CreateBoard::class, ['project' => 'Lakeshore', 'title' => 'Map'])
            ->assertOk();

        UserServer::actingAs($editor)
            ->tool(CreateTable::class, ['project' => 'Lakeshore', 'title' => 'Budget'])
            ->assertOk();

        UserServer::actingAs($editor)
            ->tool(UploadFile::class, ['project' => 'Lakeshore', 'name' => 'minutes.md', 'text' => '# Minutes'])
            ->assertOk();

        $note = $project->notes()->sole();
        $this->assertSame($editor->id, $note->created_by);
        $this->assertNull($note->user_id);
        $this->assertSame([$editor->id, $editor->id], $project->noteFolders()->pluck('created_by')->all());
        $this->assertSame($editor->id, $project->boards()->sole()->created_by);
        $this->assertSame($editor->id, $project->tables()->sole()->created_by);

        $file = $project->driveFiles()->sole();
        $this->assertSame($editor->id, $file->created_by);
        $this->assertStringStartsWith($project->driveDirectory().'/', $file->path);

        $this->assertSame(0, $editor->notes()->count() + $editor->driveFiles()->count());
    }

    public function test_the_admin_server_acts_as_a_member_of_the_project()
    {
        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $editor = $this->inProject($project, Project::EDITOR);
        $outsider = User::factory()->create();

        GlobalServer::tool(ListProjects::class, ['user_id' => $editor->id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('projects', 1)->etc());

        GlobalServer::tool(CreateNote::class, ['user_id' => $editor->id, 'project' => 'Lakeshore', 'title' => 'By the admin'])
            ->assertOk();
        $this->assertSame($editor->id, $project->notes()->sole()->created_by);

        GlobalServer::tool(ListNotes::class, ['user_id' => $outsider->id, 'project' => 'Lakeshore'])
            ->assertHasErrors();
    }

    public function test_two_projects_with_one_name_ask_for_a_ref_id()
    {
        $user = User::factory()->create();
        Project::factory()->withMember($user)->create(['name' => 'Plans']);
        Project::factory()->withMember($user)->create(['name' => 'Plans']);

        UserServer::actingAs($user)
            ->tool(ListNotes::class, ['project' => 'Plans'])
            ->assertHasErrors(['The user is in several projects called "Plans"; pass one\'s ref_id instead (see list-projects).']);
    }
}
