<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowFolder;
use App\Models\Workflow\WorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run by hand → Log: finished, so it can be published.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function finished(string $message = 'Hello {{ trigger.name }}'): array
    {
        return [
            'nodes' => [
                ['id' => 'a1', 'key' => 'start', 'type' => 'trigger.manual', 'position' => ['x' => 0, 'y' => 0], 'config' => ['payload' => '{"name": "Ada"}']],
                ['id' => 'b2', 'key' => 'log', 'type' => 'output.log', 'position' => ['x' => 300, 'y' => 0], 'config' => ['message' => $message]],
            ],
            'edges' => [['id' => 'a1.out.b2', 'source' => 'a1', 'sourceHandle' => 'out', 'target' => 'b2']],
        ];
    }

    private function save(User $user, Workflow $workflow, array $changes)
    {
        return $this->actingAs($user)->patchJson(route('workflows.update', $workflow), [
            'revision' => $workflow->fresh()->revision,
            ...$changes,
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('workflows.index'))->assertRedirect(route('login'));
    }

    public function test_the_list_shows_only_the_users_own()
    {
        $user = User::factory()->create();
        Workflow::factory()->for($user)->create(['title' => 'Mine']);
        Workflow::factory()->create(['title' => 'Theirs']);

        $this->actingAs($user)
            ->get(route('workflows.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workflows/Index')
                ->has('workflows', 1)
                ->where('workflows.0.title', 'Mine')
                ->where('workflows.0.steps', 1)
                ->where('workflows.0.published', null));
    }

    public function test_a_new_workflow_starts_with_a_trigger_and_opens_in_the_editor()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('workflows.store'), ['title' => 'Orders']);

        $workflow = $user->workflows()->sole();
        $response->assertRedirect(route('workflows.show', $workflow));
        $this->assertSame('trigger.manual', $workflow->definition['nodes'][0]['type']);
        $this->assertSame($user->id, $workflow->created_by);

        $this->actingAs($user)
            ->get(route('workflows.show', $workflow))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workflows/Show')
                ->where('workflow.title', 'Orders')
                ->where('workflow.published', null)
                ->where('workflow.problems', [])
                ->where('workflow.can.update', true)
                ->has('nodeTypes', 26)
                ->has('groups'));
    }

    public function test_the_draft_saves_with_its_revision_and_says_what_stands_in_the_way()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create();

        $definition = $this->finished();
        $definition['nodes'][1]['config']['message'] = '';

        $this->save($user, $workflow, ['title' => 'Renamed', 'definition' => $definition])
            ->assertOk()
            ->assertJsonPath('revision', $workflow->revision + 1)
            ->assertJsonPath('problems.0', ['message' => 'Give it a Message.', 'node' => 'b2'])
            ->assertJsonPath('definition.nodes.1.config.level', 'info')
            ->assertJsonPath('published', null);

        $this->assertSame('Renamed', $workflow->fresh()->title);
        $this->assertSame($user->id, $workflow->fresh()->updated_by);
    }

    public function test_a_save_from_an_out_of_date_copy_is_turned_away_with_what_is_there_now()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create(['title' => 'First']);

        $this->save($user, $workflow, ['title' => 'Second'])->assertOk();

        $this->actingAs($user)
            ->patchJson(route('workflows.update', $workflow), ['revision' => $workflow->revision, 'title' => 'From an old copy'])
            ->assertStatus(409)
            ->assertJsonPath('workflow.title', 'Second')
            ->assertJsonPath('workflow.edited_by', $user->name);

        $this->assertSame('Second', $workflow->fresh()->title);
    }

    public function test_what_is_not_a_workflow_is_not_kept()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create();
        $before = $workflow->definition;

        $this->save($user, $workflow, ['definition' => ['nodes' => [['id' => 'x', 'key' => 'x', 'type' => 'rm.rf']]]])
            ->assertStatus(422)
            ->assertJsonPath('errors.definition.0', 'Step x is of a kind that does not exist: "rm.rf".');

        $this->assertSame($before, $workflow->fresh()->definition);
    }

    public function test_publishing_freezes_the_draft_and_later_changes_wait_for_the_next()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create();
        $this->save($user, $workflow, ['definition' => $this->finished()])->assertOk();

        $this->actingAs($user)
            ->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->fresh()->revision])
            ->assertOk()
            ->assertJsonPath('published.number', 1)
            ->assertJsonPath('published.by', $user->name)
            ->assertJsonPath('unpublished_changes', false);

        $first = $workflow->fresh()->publishedVersion;
        $this->assertSame(WorkflowVersion::PUBLISHED, $first->kind);
        $this->assertSame('Hello {{ trigger.name }}', $first->definition['nodes'][1]['config']['message']);

        // Publishing again with nothing new makes no new version
        $this->actingAs($user)
            ->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->fresh()->revision])
            ->assertJsonPath('published.number', 1);

        // A change to the draft leaves what is published as it was
        $this->save($user, $workflow, ['definition' => $this->finished('Bye')])
            ->assertJsonPath('unpublished_changes', true)
            ->assertJsonPath('published.number', 1);
        $this->assertSame('Hello {{ trigger.name }}', $first->fresh()->definition['nodes'][1]['config']['message']);

        $this->actingAs($user)
            ->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->fresh()->revision])
            ->assertJsonPath('published.number', 2);

        $this->assertSame(2, $workflow->versions()->count());
        $this->assertSame('Bye', $workflow->fresh()->publishedVersion->definition['nodes'][1]['config']['message']);
    }

    public function test_publishing_is_refused_while_something_stands_in_the_way()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create();
        $this->save($user, $workflow, ['definition' => $this->finished('Total {{ trigger.total')])->assertOk();

        $this->actingAs($user)
            ->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->fresh()->revision])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.node', 'b2');

        $this->assertNull($workflow->fresh()->published_version_id);
        $this->assertSame(0, $workflow->versions()->count());
    }

    public function test_only_what_was_saved_is_published()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create();
        $seen = $workflow->revision;
        $this->save($user, $workflow, ['definition' => $this->finished()])->assertOk();

        $this->actingAs($user)
            ->postJson(route('workflows.publish', $workflow), ['revision' => $seen])
            ->assertStatus(409);

        $this->assertNull($workflow->fresh()->published_version_id);
    }

    public function test_a_version_is_never_changed_once_written()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create();
        $version = $workflow->freeze(WorkflowVersion::PUBLISHED, $user);

        $this->expectException(LogicException::class);

        $version->update(['definition' => []]);
    }

    public function test_another_users_workflow_cannot_be_seen_or_touched()
    {
        $user = User::factory()->create();
        $theirs = Workflow::factory()->create();

        $this->actingAs($user)->get(route('workflows.show', $theirs))->assertForbidden();
        $this->actingAs($user)->patchJson(route('workflows.update', $theirs), ['title' => 'Mine'])->assertForbidden();
        $this->actingAs($user)->postJson(route('workflows.publish', $theirs), ['revision' => 0])->assertForbidden();
        $this->actingAs($user)->delete(route('workflows.destroy', $theirs))->assertForbidden();
    }

    public function test_deleting_a_workflow_takes_its_versions_with_it()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->create();
        $workflow->freeze(WorkflowVersion::PUBLISHED, $user);

        $this->actingAs($user)->delete(route('workflows.destroy', $workflow))->assertRedirect(route('workflows.index'));

        $this->assertDatabaseCount('workflows', 0);
        $this->assertDatabaseCount('workflow_versions', 0);
    }

    public function test_workflows_are_kept_in_folders_and_go_with_them()
    {
        $user = User::factory()->create();
        $folder = WorkflowFolder::factory()->for($user)->create(['name' => 'Shop']);

        $this->actingAs($user)->post(route('workflows.store'), ['folder' => $folder->ref_id, 'title' => 'Orders']);
        $workflow = $user->workflows()->sole();
        $this->assertSame($folder->id, $workflow->folder_id);

        $this->actingAs($user)
            ->get(route('workflows.index', ['folder' => $folder->ref_id]))
            ->assertInertia(fn (Assert $page) => $page->has('workflows', 1)->where('folder.name', 'Shop'));

        $this->actingAs($user)->delete(route('workflow-folders.destroy', $folder))->assertRedirect();
        $this->assertDatabaseCount('workflows', 0);
    }

    public function test_a_projects_viewers_look_and_its_editors_change()
    {
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $project = Project::factory()->withMember($editor, Project::EDITOR)->withMember($viewer, Project::VIEWER)->create();

        $this->actingAs($editor)
            ->post(route('projects.workflows.store', $project), ['title' => 'Team flow'])
            ->assertRedirect();
        $workflow = $project->workflows()->sole();
        $this->assertNull($workflow->user_id);

        $this->actingAs($viewer)
            ->get(route('projects.workflows.index', $project))
            ->assertInertia(fn (Assert $page) => $page->has('workflows', 1)->where('workflows.0.edited_by', $editor->name));
        $this->actingAs($viewer)
            ->get(route('workflows.show', $workflow))
            ->assertInertia(fn (Assert $page) => $page->where('workflow.can.update', false));

        $this->actingAs($viewer)->post(route('projects.workflows.store', $project))->assertForbidden();
        $this->actingAs($viewer)->patchJson(route('workflows.update', $workflow), ['title' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertForbidden();

        $this->save($editor, $workflow, ['definition' => $this->finished()])->assertOk();
        $this->actingAs($editor)
            ->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->fresh()->revision])
            ->assertOk();
    }

    public function test_a_projects_workflows_go_with_the_project()
    {
        $project = Project::factory()->withMember(User::factory()->create())->create();
        Workflow::factory()->forProject($project)->create();

        $project->delete();

        $this->assertDatabaseCount('workflows', 0);
    }
}
