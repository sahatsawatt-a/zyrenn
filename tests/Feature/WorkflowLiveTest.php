<?php

namespace Tests\Feature;

use App\Events\WorkflowChanged;
use App\Events\WorkflowRunChanged;
use App\Events\WorkflowRunText;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Engine;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * What an open editor is told: each run's progress, once it is saved, on the
 * workflow's own channel -- and the draft, when someone else saves it.
 */
class WorkflowLiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Bus::fake([BroadcastEvent::class]);
    }

    private function workflow(?User $user = null): Workflow
    {
        return Workflow::factory()->for($user ?? User::factory()->create())->steps([
            ['id' => 'start', 'key' => 'start', 'type' => 'trigger.manual'],
            ['id' => 'check', 'key' => 'check', 'type' => 'logic.if', 'config' => ['condition' => 'true']],
            ['id' => 'yes', 'key' => 'yes', 'type' => 'output.log', 'config' => ['message' => 'yes']],
            ['id' => 'no', 'key' => 'no', 'type' => 'output.log', 'config' => ['message' => 'no']],
        ], [
            ['id' => 'a', 'source' => 'start', 'target' => 'check'],
            ['id' => 'b', 'source' => 'check', 'sourceHandle' => 'true', 'target' => 'yes'],
            ['id' => 'c', 'source' => 'check', 'sourceHandle' => 'false', 'target' => 'no'],
        ])->create();
    }

    /**
     * What was told of runs, in order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function told(): Collection
    {
        return Bus::dispatched(BroadcastEvent::class)
            ->filter(fn (BroadcastEvent $job) => $job->event instanceof WorkflowRunChanged)
            ->map(fn (BroadcastEvent $job) => [
                'channel' => $job->event->broadcastOn()->name,
                'as' => $job->event->broadcastAs(),
                ...$job->event->broadcastWith(),
            ])
            ->values();
    }

    public function test_a_run_tells_the_workflows_channel_how_it_goes_until_it_ends()
    {
        $workflow = $this->workflow();
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $workflow->user), $workflow->user);
        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

        $told = $this->told();
        $last = $told->last();

        $this->assertGreaterThanOrEqual(4, $told->count());
        $this->assertSame("presence-workflows.{$workflow->ref_id}", $last['channel']);
        $this->assertSame('run.changed', $last['as']);
        $this->assertSame($run->ref_id, $last['run']);
        $this->assertSame('succeeded', $last['status']);
        $this->assertSame(
            ['check' => 'succeeded', 'no' => 'skipped', 'start' => 'succeeded', 'yes' => 'succeeded'],
            collect($last['steps'])->pluck('status', 'node_id')->sortKeys()->all(),
        );
        $this->assertSame('true', collect($last['steps'])->firstWhere('node_id', 'check')['handle']);
        // Never more than where things stand
        $this->assertArrayNotHasKey('output', $last['steps'][0]);
        // Each a newer picture than the last
        $this->assertSame($told->pluck('seq')->sort()->values()->all(), $told->pluck('seq')->all());
    }

    public function test_a_change_that_is_not_kept_is_not_told()
    {
        $workflow = $this->workflow();
        $version = $workflow->freeze(WorkflowVersion::TEST, $workflow->user);

        try {
            DB::transaction(function () use ($workflow, $version) {
                Engine::start($workflow, $version, $workflow->user);

                throw new RuntimeException('Something failed after.');
            });
        } catch (RuntimeException) {
        }

        $this->assertCount(0, $this->told());

        // ...and the run after is told as ever
        Engine::start($workflow, $version, $workflow->user);
        $this->assertCount(1, $this->told());
    }

    public function test_a_saved_draft_is_told_to_the_others_with_it_open()
    {
        $user = User::factory()->create();
        $workflow = $this->workflow($user);

        $this->actingAs($user)->patchJson(route('workflows.update', $workflow), ['revision' => $workflow->revision, 'title' => 'Renamed'])->assertOk();

        Bus::assertDispatched(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof WorkflowChanged
            && $job->event->broadcastOn()->name === "presence-workflows.{$workflow->ref_id}"
            && $job->event->broadcastWith() === ['change' => 'saved', 'revision' => $workflow->revision + 1, 'edited_by' => $user->name]);
    }

    public function test_only_those_who_may_see_a_workflow_hear_of_it()
    {
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();
        $project = Project::factory()->withMember($viewer, Project::VIEWER)->create();
        $workflow = Workflow::factory()->forProject($project)->create();

        $channel = collect(Broadcast::getChannels())->get('workflows.{workflow}');

        $this->assertSame(['id' => $viewer->id, 'name' => $viewer->name], $channel($viewer, $workflow));
        $this->assertFalse($channel($outsider, $workflow));
    }

    public function test_an_ai_answer_is_told_as_it_is_written_and_adds_up_to_the_answer()
    {
        $pieces = ['The ', 'weather ', 'is ', 'fine 🌤', ' today', '.'];
        Http::fake(['openrouter.ai/*' => Http::response(
            collect($pieces)->map(fn (string $piece) => 'data: '.json_encode(['choices' => [['delta' => ['content' => $piece]]]])."\n\n")->implode('')."data: [DONE]\n\n",
        )]);
        $user = User::factory()->create();
        $ai = Connection::factory()->openrouter()->for($user)->create(['settings' => ['base_url' => 'https://openrouter.ai/api/v1', 'default_model' => 'some/model']]);
        $workflow = Workflow::factory()->for($user)->steps([
            ['id' => 'start', 'key' => 'start', 'type' => 'trigger.manual'],
            ['id' => 'ask', 'key' => 'ask', 'type' => 'ai.chat', 'config' => ['connection' => $ai->ref_id, 'prompt' => 'Weather?']],
        ], [['id' => 'a', 'source' => 'start', 'target' => 'ask']])->create();

        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

        $written = Bus::dispatched(BroadcastEvent::class)
            ->filter(fn (BroadcastEvent $job) => $job->event instanceof WorkflowRunText)
            ->map(fn (BroadcastEvent $job) => ['as' => $job->event->broadcastAs(), 'channel' => $job->event->broadcastOn()->name, ...$job->event->broadcastWith()])
            ->values();

        $this->assertNotEmpty($written);
        $this->assertSame("presence-workflows.{$workflow->ref_id}", $written[0]['channel']);
        $this->assertSame('run.text', $written[0]['as']);
        $this->assertSame([$run->ref_id], $written->pluck('run')->unique()->values()->all());
        $this->assertSame(['ask'], $written->pluck('node_id')->unique()->values()->all());

        // Each picks up where the last left off, counted by character, and together they are the answer
        $at = 0;

        foreach ($written as $message) {
            $this->assertSame($at, $message['at']);
            $at += mb_strlen($message['text']);
        }

        $this->assertSame(implode('', $pieces), $written->pluck('text')->implode(''));
        $this->assertSame('The weather is fine 🌤 today.', $run->fresh()->steps()->where('node_key', 'ask')->sole()->output['text']);
    }
}
