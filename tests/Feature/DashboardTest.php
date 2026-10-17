<?php

namespace Tests\Feature;

use App\Models\Board\Board;
use App\Models\Chat\ChatRoom;
use App\Models\Drive\DriveFile;
use App\Models\Note\Note;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Engine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dashboards: one's own at /dashboard, a project's at /p/{project}/dashboard.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The dashboard page is newer than the last asset build
        $this->withoutVite();
    }

    /**
     * A note body with to-dos in it: [text, ticked] pairs, the last one
     * holding a to-do of its own under it.
     *
     * @param  list<array{string, bool}>  $tasks
     * @return array<string, mixed>
     */
    private static function withTasks(array $tasks): array
    {
        $item = fn (string $text, bool $checked, array $under = []) => [
            'type' => 'taskItem',
            'attrs' => ['checked' => $checked],
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
                ...$under,
            ],
        ];

        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'taskList',
                'content' => [
                    ...array_map(fn (array $task) => $item(...$task), $tasks),
                    $item('Parent', false, [[
                        'type' => 'taskList',
                        'content' => [$item('Child', false)],
                    ]]),
                ],
            ]],
        ];
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_ones_own_dashboard_counts_and_lists_only_ones_own()
    {
        $user = User::factory()->create();
        Note::factory()->for($user)->create(['title' => 'Older', 'updated_at' => now()->subDay()]);
        Note::factory()->for($user)->create(['title' => 'Newest']);
        Board::factory()->for($user)->create(['title' => 'Plan', 'updated_at' => now()->subHour()]);
        Table::factory()->for($user)->create(['updated_at' => now()->subMinutes(30)]);
        DriveFile::factory()->for($user)->create(['size' => 1000]);
        DriveFile::factory()->for($user)->create(['size' => 500]);
        Note::factory()->create(['title' => 'Someone else']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard/Index')
                ->where('counts', ['notes' => 2, 'boards' => 1, 'tables' => 1, 'files' => 2, 'bytes' => 1500])
                ->has('recent', 4)
                ->where('recent.0.title', 'Newest')
                ->where('recent.1.kind', 'table')
                ->where('recent.2.title', 'Plan')
                ->where('recent.3.title', 'Older')
                ->missing('recent.0.edited_by')
                ->missing('members')
                ->has('glance', 0)
                ->has('waiting', 0));
    }

    public function test_open_todos_are_gathered_from_the_notes_unticked_ones_only()
    {
        $user = User::factory()->create();
        Note::factory()->for($user)->create([
            'title' => 'Launch',
            'content' => self::withTasks([['Write the post', false], ['Book the room', true]]),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('todos.total', 3)
                ->where('todos.items.0.text', 'Write the post')
                ->where('todos.items.0.note.title', 'Launch')
                // Only the item's own line, with what is nested under it on its own
                ->where('todos.items.1.text', 'Parent')
                ->where('todos.items.2.text', 'Child'));
    }

    public function test_a_projects_dashboard_shows_what_is_in_it_and_who_changed_it()
    {
        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $owner = User::factory()->create(['name' => 'Ada']);
        $viewer = User::factory()->create(['name' => 'Grace']);
        $project->members()->attach($owner, ['role' => Project::OWNER]);
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);

        Note::factory()->create([
            'user_id' => null,
            'project_id' => $project->id,
            'title' => 'Shared',
            'updated_by' => $owner->id,
            'content' => self::withTasks([['Check the deck', false]]),
        ]);
        Note::factory()->for($viewer)->create(['title' => 'Private']);

        $this->actingAs($viewer)
            ->get(route('projects.dashboard', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard/Index')
                ->where('project.name', 'Lakeshore')
                ->where('project.role', Project::VIEWER)
                ->where('counts.notes', 1)
                ->has('recent', 1)
                ->where('recent.0.title', 'Shared')
                ->where('recent.0.edited_by', 'Ada')
                ->where('todos.items.0.text', 'Check the deck')
                ->where('members.0', ['name' => 'Ada', 'role' => Project::OWNER, 'is_me' => false])
                ->where('members.1', ['name' => 'Grace', 'role' => Project::VIEWER, 'is_me' => true])
                ->has('glance.activity')
                ->has('glance.tickets.counts'));
    }

    public function test_only_members_see_a_projects_dashboard_and_a_project_opens_on_it()
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();
        $project->members()->attach($member, ['role' => Project::EDITOR]);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.dashboard', $project))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('projects.show', $project))
            ->assertRedirect(route('projects.dashboard', $project));
    }

    public function test_ones_own_dashboard_has_each_project_at_a_glance_and_what_waits_on_one()
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['name' => 'Grace']);
        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $project->members()->attach($user, ['role' => Project::EDITOR]);
        $project->members()->attach($other, ['role' => Project::OWNER]);

        $theirs = ['user_id' => null, 'project_id' => $project->id];
        Note::factory()->create([...$theirs, 'title' => 'By Grace', 'updated_by' => $other->id, 'updated_at' => now()->subHour()]);
        Note::factory()->create(['title' => 'Elsewhere', 'updated_by' => $other->id]);
        $mine = Ticket::file($project, $other, ['type' => 'bug', 'title' => 'Export breaks', 'priority' => 'high', 'assigned_to' => $user->id]);
        Ticket::file($project, $other, ['type' => 'feature', 'title' => 'Waits on design', 'status' => Ticket::ON_HOLD, 'reason' => 'The mockup first']);
        Ticket::file($project, $other, ['type' => 'feature', 'title' => 'Someone else\'s', 'assigned_to' => $other->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                // Only what is theirs to do: the ticket given to them
                ->has('waiting', 1)
                ->where('waiting.0.kind', 'ticket')
                ->where('waiting.0.title', "#{$mine->number} Export breaks")
                ->where('waiting.0.priority', 'high')
                ->where('waiting.0.context', fn (string $context) => str_starts_with($context, 'Lakeshore'))
                // Each project at a glance
                ->has('glance', 1)
                ->where('glance.0.name', 'Lakeshore')
                ->where('glance.0.role', Project::EDITOR)
                ->where('glance.0.tickets', ['open' => 2, 'in_progress' => 0, 'on_hold' => 1])
                // The latest that happened in it: Grace's tickets, filed together
                ->where('glance.0.last.who', 'Grace')
                ->missing('yourProjects'));

        // On the project's own: its tickets in full, and what happened in it
        $this->actingAs($user)
            ->get(route('projects.dashboard', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('waiting', 1)
                ->where('waiting.0.context', fn (string $context) => ! str_contains($context, 'Lakeshore'))
                ->where('glance.tickets.on_hold.0.reason', 'The mockup first')
                ->where('glance.activity', fn ($lines) => collect($lines)->contains(fn (array $line) => str_contains($line['text'], 'edited the note “By Grace”') && $line['who'] === 'Grace')));
    }

    public function test_a_step_asking_for_approval_waits_on_whoever_may_change_the_workflow_until_answered()
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([
            ['id' => 'start', 'key' => 'start', 'type' => 'trigger.manual', 'position' => ['x' => 0, 'y' => 0], 'config' => ['payload' => '{}']],
            ['id' => 'ask', 'key' => 'ask', 'type' => 'logic.approval', 'position' => ['x' => 0, 'y' => 0], 'config' => ['message' => 'Publish the notes?', 'expires' => 'never']],
        ], [['id' => 'e', 'source' => 'start', 'sourceHandle' => 'out', 'target' => 'ask']])->create(['title' => 'Release notes']);
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        $answer = route('workflow-runs.answer', [$run, 'ask']);
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('waiting.0.kind', 'approval')
                ->where('waiting.0.title', 'Publish the notes?')
                ->where('waiting.0.context', 'Release notes')
                ->where('waiting.0.href', fn (string $href) => str_ends_with($href, '?run='.$run->ref_id))
                ->where('waiting.0.answer', $answer));

        // Answered from the dashboard's question, it waits no more
        $this->actingAs($user)->postJson($answer, ['approved' => true])->assertOk();
        $this->work();
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('waiting', 0));

        // Someone who may not change the workflow is not asked
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('waiting', 0));
    }

    public function test_conversations_waiting_are_listed_with_what_is_unread()
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['name' => 'Grace']);
        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $project->members()->attach($user, ['role' => Project::EDITOR]);
        $project->members()->attach($other, ['role' => Project::OWNER]);

        $direct = ChatRoom::between($user, $other);
        $direct->messages()->create(['role' => 'user', 'user_id' => $other->id, 'content' => 'hi']);
        $group = ChatRoom::startGroup($project, $other, 'Design', [$user->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('chats', 2)
                ->where('chats', fn ($chats) => collect($chats)->firstWhere('title', 'Grace')['unread'] === 1
                    && collect($chats)->firstWhere('title', 'Design')['project'] === 'Lakeshore'));

        // A project's dashboard has only its groups
        $this->actingAs($user)
            ->get(route('projects.dashboard', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('chats', 1)
                ->where('chats.0.ref_id', $group->ref_id));
    }
}
