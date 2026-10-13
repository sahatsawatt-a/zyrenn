<?php

namespace Tests\Feature;

use App\Jobs\RunWorkflowStep;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Engine;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workflows run through the real queue: each step a job in the jobs table,
 * written with the change that queued it, worked off by a worker.
 */
class WorkflowRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    private function node(string $key, string $type, array $config = [], array $more = []): array
    {
        return ['id' => $key, 'key' => $key, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'config' => $config, ...$more];
    }

    private function edge(string $from, string $to, string $handle = 'out'): array
    {
        return ['id' => "{$from}.{$handle}.{$to}", 'source' => $from, 'sourceHandle' => $handle, 'target' => $to];
    }

    /**
     * A workflow of these steps, frozen and run by hand, then worked off.
     */
    private function runSteps(array $nodes, array $edges, ?User $user = null): WorkflowRun
    {
        $user ??= User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps($nodes, $edges)->create();
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);

        $this->work();

        return $run->fresh();
    }

    private function step(WorkflowRun $run, string $key): WorkflowNodeRun
    {
        return $run->steps()->where('node_key', $key)->sole();
    }

    private function trigger(array $payload): array
    {
        return $this->node('start', 'trigger.manual', ['payload' => json_encode($payload)]);
    }

    public function test_a_run_goes_from_its_trigger_to_its_end_each_step_reading_the_last()
    {
        $run = $this->runSteps([
            $this->trigger(['name' => 'Ada', 'total' => 120, 'items' => [1, 2]]),
            $this->node('set', 'data.set', ['fields' => [
                ['name' => 'with_tax', 'value' => '{{ trigger.total * 1.07 }}'],
                ['name' => 'greeting', 'value' => 'Hello {{ trigger.name }}'],
                ['name' => 'items', 'value' => '{{ trigger.items }}'],
            ]]),
            $this->node('log', 'output.log', ['message' => '{{ input.greeting }}, that is {{ node("set").with_tax }}']),
        ], [$this->edge('start', 'set'), $this->edge('set', 'log')]);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(3, $run->executed_steps);
        $this->assertSame(['name' => 'Ada', 'total' => 120, 'items' => [1, 2]], $this->step($run, 'start')->output);

        // A value that is one formula keeps its kind; anything else is text
        $this->assertSame(['with_tax' => 128.4, 'greeting' => 'Hello Ada', 'items' => [1, 2]], $this->step($run, 'set')->output);
        $this->assertSame('Hello Ada, that is 128.4', $run->logs()->sole()->message);
        // Log passes on what it was given, and says what it worked out
        $this->assertSame($this->step($run, 'set')->output, $this->step($run, 'log')->output);
        $this->assertSame('Hello Ada, that is 128.4', $this->step($run, 'log')->input['settings']['message']);
    }

    public function test_an_if_carries_on_one_way_and_what_is_only_down_the_other_is_skipped()
    {
        $nodes = [
            $this->trigger(['total' => 50]),
            $this->node('check', 'logic.if', ['condition' => 'trigger.total > 100']),
            $this->node('big', 'output.log', ['message' => 'big']),
            $this->node('after_big', 'output.log', ['message' => 'after big']),
            $this->node('small', 'output.log', ['message' => 'small']),
        ];
        $edges = [
            $this->edge('start', 'check'),
            $this->edge('check', 'big', 'true'),
            $this->edge('big', 'after_big'),
            $this->edge('check', 'small', 'false'),
        ];

        $run = $this->runSteps($nodes, $edges);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame('false', $this->step($run, 'check')->handle);
        $this->assertSame(NodeStatus::Skipped, $this->step($run, 'big')->status);
        $this->assertSame(NodeStatus::Skipped, $this->step($run, 'after_big')->status);
        $this->assertSame(NodeStatus::Succeeded, $this->step($run, 'small')->status);
        $this->assertSame(['small'], $run->logs()->pluck('message')->all());
    }

    public function test_where_an_ifs_two_ways_meet_the_step_runs_once()
    {
        $run = $this->runSteps([
            $this->trigger(['total' => 150]),
            $this->node('check', 'logic.if', ['condition' => 'trigger.total > 100']),
            $this->node('big', 'data.set', ['fields' => [['name' => 'size', 'value' => 'big']]]),
            $this->node('small', 'data.set', ['fields' => [['name' => 'size', 'value' => 'small']]]),
            $this->node('done', 'output.log', ['message' => 'It was {{ input.size }}']),
        ], [
            $this->edge('start', 'check'),
            $this->edge('check', 'big', 'true'),
            $this->edge('check', 'small', 'false'),
            $this->edge('big', 'done'),
            $this->edge('small', 'done'),
        ]);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(1, $this->step($run, 'done')->attempt);
        $this->assertSame(['It was big'], $run->logs()->pluck('message')->all());
    }

    public function test_a_step_after_two_that_both_ran_waits_for_both_and_reads_each_by_key()
    {
        $run = $this->runSteps([
            $this->trigger(['n' => 2]),
            $this->node('double', 'data.set', ['fields' => [['name' => 'n', 'value' => '{{ trigger.n * 2 }}']]]),
            $this->node('triple', 'data.set', ['fields' => [['name' => 'n', 'value' => '{{ trigger.n * 3 }}']]]),
            $this->node('sum', 'data.set', ['fields' => [['name' => 'total', 'value' => '{{ input.double.n + input.triple.n }}']]]),
        ], [
            $this->edge('start', 'double'),
            $this->edge('start', 'triple'),
            $this->edge('double', 'sum'),
            $this->edge('triple', 'sum'),
        ]);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(['total' => 10], $this->step($run, 'sum')->output);
        $this->assertSame(1, $this->step($run, 'sum')->attempt);
    }

    public function test_a_failing_step_stops_the_run_and_what_had_not_begun_is_cancelled()
    {
        $run = $this->runSteps([
            $this->trigger([]),
            $this->node('broken', 'data.set', ['fields' => [['name' => 'x', 'value' => '{{ trigger.missing }}']]]),
            $this->node('after', 'output.log', ['message' => 'never']),
        ], [$this->edge('start', 'broken'), $this->edge('broken', 'after')]);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('node_failed', $run->reason);
        $this->assertSame('Set data: There is no "missing" here.', $run->error);
        $this->assertSame(NodeStatus::Failed, $this->step($run, 'broken')->status);
        $this->assertSame('There is no "missing" here.', $this->step($run, 'broken')->error['message']);
        $this->assertSame(NodeStatus::Cancelled, $this->step($run, 'after')->status);
        $this->assertSame(0, $run->logs()->count());
    }

    public function test_a_step_set_to_carry_on_passes_on_what_went_wrong()
    {
        $run = $this->runSteps([
            $this->trigger([]),
            $this->node('broken', 'data.set', ['fields' => [['name' => 'x', 'value' => '{{ 1 / "a" }}']]], ['onError' => 'continue']),
            $this->node('after', 'output.log', ['message' => 'Went on: {{ input.error }}']),
        ], [$this->edge('start', 'broken'), $this->edge('broken', 'after')]);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertNotNull($this->step($run, 'broken')->error);
        $this->assertStringStartsWith('Went on: ', $run->logs()->sole()->message);
    }

    public function test_a_steps_job_that_comes_twice_runs_it_once()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([
            $this->trigger([]),
            $this->node('log', 'output.log', ['message' => 'once']),
        ], [$this->edge('start', 'log')])->create();
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);

        // The same step's job, delivered a second time
        RunWorkflowStep::dispatch($this->step($run, 'start')->id);
        $this->work();

        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(1, $this->step($run, 'start')->attempt);
        $this->assertSame(2, $run->executed_steps);
        $this->assertSame(1, $run->logs()->count());
    }

    public function test_a_run_stops_at_its_budget_of_steps()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([
            $this->trigger([]),
            $this->node('log', 'output.log', ['message' => 'over budget']),
        ], [$this->edge('start', 'log')])->create();
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $run->update(['max_executed_steps' => 1]);

        $this->work();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('budget_exceeded', $run->reason);
        $this->assertSame(NodeStatus::Cancelled, $this->step($run, 'log')->status);
        $this->assertSame(0, $run->logs()->count());
    }

    public function test_a_step_given_up_on_before_it_ran_fails_its_run()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([$this->trigger([])])->create();
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);

        Engine::abandon($this->step($run, 'start')->id, 'Its worker stopped before it finished.');

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame(NodeStatus::Failed, $this->step($run, 'start')->status);
    }

    public function test_a_second_copy_turned_away_leaves_a_step_another_worker_holds()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([$this->trigger([])])->create();
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->step($run, 'start')->forceFill(['status' => NodeStatus::Running, 'lease_until' => now()->addMinute()])->save();

        Engine::abandon($this->step($run, 'start')->id, 'Its worker stopped before it finished.', mine: false);

        $this->assertSame(NodeStatus::Running, $this->step($run, 'start')->status);
        $this->assertSame(RunStatus::Queued, $run->fresh()->status);
    }

    // --- from the editor ---

    public function test_run_from_the_editor_freezes_the_saved_draft_and_reuses_that_copy()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([
            $this->trigger(['name' => 'Ada']),
            $this->node('log', 'output.log', ['message' => 'Hi {{ trigger.name }}']),
        ], [$this->edge('start', 'log')])->create();

        $first = $this->actingAs($user)
            ->postJson(route('workflows.runs.store', $workflow), ['revision' => $workflow->revision])
            ->assertCreated()
            ->assertJsonPath('run.status', 'queued')
            ->assertJsonPath('run.version.kind', 'test')
            ->assertJsonPath('run.steps.0.status', 'queued')
            ->json('run.ref_id');

        $this->work();

        $this->actingAs($user)
            ->getJson(route('workflow-runs.show', $first))
            ->assertOk()
            ->assertJsonPath('run.status', 'succeeded')
            ->assertJsonPath('run.started_by', $user->name)
            ->assertJsonPath('run.steps.1.output', ['name' => 'Ada'])
            ->assertJsonPath('run.logs.0.message', 'Hi Ada')
            ->assertJsonPath('run.logs.0.node_id', 'log');

        // The same draft again: the same frozen copy
        $this->actingAs($user)->postJson(route('workflows.runs.store', $workflow), ['revision' => $workflow->revision])->assertCreated();
        $this->assertSame(1, $workflow->versions()->count());

        $this->actingAs($user)
            ->getJson(route('workflows.runs.index', $workflow))
            ->assertJsonCount(2, 'runs')
            ->assertJsonPath('runs.1.status', 'succeeded');
    }

    public function test_a_run_keeps_the_version_it_started_with_while_the_draft_changes()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([
            $this->trigger([]),
            $this->node('log', 'output.log', ['message' => 'old']),
        ], [$this->edge('start', 'log')])->create();

        $ref = $this->actingAs($user)
            ->postJson(route('workflows.runs.store', $workflow), ['revision' => $workflow->revision])
            ->json('run.ref_id');

        $definition = $workflow->definition;
        $definition['nodes'][1]['config']['message'] = 'new';
        $workflow->update(['definition' => $definition]);

        $this->work();

        $this->assertSame(['old'], WorkflowRun::where('ref_id', $ref)->sole()->logs()->pluck('message')->all());
    }

    public function test_an_unfinished_draft_or_one_seen_out_of_date_is_not_run()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([
            $this->trigger([]),
            $this->node('log', 'output.log', ['message' => '']),
        ], [$this->edge('start', 'log')])->create();

        $this->actingAs($user)
            ->postJson(route('workflows.runs.store', $workflow), ['revision' => $workflow->revision])
            ->assertStatus(422)
            ->assertJsonPath('problems.0.message', 'Give it a Message.');

        $this->actingAs($user)
            ->postJson(route('workflows.runs.store', $workflow), ['revision' => $workflow->revision + 5])
            ->assertStatus(409);

        $this->assertSame(0, WorkflowRun::count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_projects_viewers_see_its_runs_but_do_not_start_them()
    {
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $project = Project::factory()->withMember($editor, Project::EDITOR)->withMember($viewer, Project::VIEWER)->create();
        $workflow = Workflow::factory()->forProject($project, $editor)->create();

        $this->actingAs($viewer)->postJson(route('workflows.runs.store', $workflow), ['revision' => $workflow->revision])->assertForbidden();

        $ref = $this->actingAs($editor)
            ->postJson(route('workflows.runs.store', $workflow), ['revision' => $workflow->revision])
            ->assertCreated()
            ->json('run.ref_id');

        $this->actingAs($viewer)->getJson(route('workflow-runs.show', $ref))->assertOk();
        $this->actingAs(User::factory()->create())->getJson(route('workflow-runs.show', $ref))->assertForbidden();
    }
}
