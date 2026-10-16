<?php

namespace Tests\Feature;

use App\Events\ListChanged;
use App\Models\Note\Note;
use App\Models\Note\NoteFolder;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Support\Product\Keeper;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * A space's lists following what is made, changed and deleted in it, by
 * anyone or anything: each change tells the space's channel which kind of
 * list to ask for again.
 */
class ListChangedTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{channel: string, kind: string}> */
    private function told(): array
    {
        return Event::dispatched(ListChanged::class)->map(fn (array $sent) => [
            'channel' => $sent[0]->broadcastOn()->name,
            'kind' => $sent[0]->broadcastWith()['kind'],
        ])->values()->all();
    }

    public function test_a_users_own_things_are_told_on_their_channel_and_a_projects_on_its_own()
    {
        Event::fake([ListChanged::class]);
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $note = Note::factory()->for($user)->create();
        Ticket::factory()->for($project)->create();
        NoteFolder::factory()->for($user)->create();
        $note->delete();

        $this->assertSame([
            ['channel' => "private-App.Models.User.{$user->id}", 'kind' => 'notes'],
            ['channel' => "private-projects.{$project->ref_id}", 'kind' => 'tickets'],
            ['channel' => "private-App.Models.User.{$user->id}", 'kind' => 'notes'],
            ['channel' => "private-App.Models.User.{$user->id}", 'kind' => 'notes'],
        ], $this->told());
        $this->assertSame('listed.changed', (new ListChanged($user, 'notes'))->broadcastAs());
    }

    public function test_a_note_saved_as_it_is_written_is_not_told_until_its_list_would_change()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create(['title' => 'Plan']);
        $workflow = Workflow::factory()->for($user)->create();
        Event::fake([ListChanged::class]);

        $note->update(['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]);
        $workflow->update(['definition' => [...$workflow->definition, 'settings' => ['moved' => true]]]);
        $this->assertSame([], $this->told());

        $note->update(['title' => 'Plan B']);
        $note->forceFill(['folder_id' => NoteFolder::factory()->for($user)->create()->id])->save();
        $workflow->forceFill(['enabled' => true])->save();

        $this->assertSame(['notes', 'notes', 'notes', 'workflows'], array_column($this->told(), 'kind'));
    }

    public function test_many_changes_in_one_job_are_told_once_for_each_kind_when_it_is_done()
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create();
        $project->members()->attach($owner, ['role' => Project::OWNER]);
        Event::fake([ListChanged::class]);
        $job = Mockery::mock(Job::class);
        $job->allows('hasFailed')->andReturnFalse();
        $job->allows('payload')->andReturn([]);

        // As a worker on a real queue runs one: what is deferred goes out at its end
        event(new JobProcessing('database', $job));
        $app = Keeper::addPart($project, $owner, 'component', 'Editor');
        $board = Keeper::addPart($project, $owner, 'component', 'Board', $app);
        Keeper::link($board, $app, 'uses', $owner);
        Ticket::factory()->for($project)->count(3)->create();
        $this->assertSame([], $this->told());
        event(new JobAttempted('database', $job));

        $this->assertEqualsCanonicalizing([
            ['channel' => "private-projects.{$project->ref_id}", 'kind' => 'product'],
            ['channel' => "private-projects.{$project->ref_id}", 'kind' => 'tickets'],
        ], $this->told());
    }

    public function test_a_change_made_outside_a_request_or_job_is_told_at_once()
    {
        // MCP on stdio and tinker run for as long as they are open: nothing is kept for later
        Event::fake([ListChanged::class]);
        $project = Project::factory()->create();

        Ticket::factory()->for($project)->count(2)->create();

        $this->assertCount(2, $this->told());
    }

    public function test_only_a_projects_members_hear_of_its_lists()
    {
        $project = Project::factory()->create();
        $viewer = User::factory()->create();
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);
        $rules = app(BroadcastManager::class)->driver()->getChannels();

        $this->assertTrue($rules['projects.{project}']($viewer, $project));
        $this->assertFalse($rules['projects.{project}'](User::factory()->create(), $project));
    }
}
