<?php

namespace Tests\Feature;

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
 * Steps that wait -- for a time, for a person -- runs that are stopped, and
 * what a dead worker or a lost job leaves behind, put right by the sweep.
 * Through the real database queue; the clock is moved on, not waited for.
 */
class WorkflowRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();
    }

    private function node(string $key, string $type, array $config = []): array
    {
        return ['id' => $key, 'key' => $key, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    private function edge(string $from, string $to, string $handle = 'out'): array
    {
        return ['id' => "{$from}.{$handle}.{$to}", 'source' => $from, 'sourceHandle' => $handle, 'target' => $to];
    }

    private function start(array $nodes, array $edges, ?User $user = null): WorkflowRun
    {
        $user ??= User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps($nodes, $edges)->create();

        return Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
    }

    private function step(WorkflowRun $run, string $key): WorkflowNodeRun
    {
        return $run->steps()->where('node_key', $key)->sole();
    }

    private function trigger(array $payload = []): array
    {
        return $this->node('start', 'trigger.manual', ['payload' => json_encode($payload ?: ['n' => 1])]);
    }

    /**
     * start → wait (Delay) → log
     */
    private function delayed(string $amount = '5', string $unit = 'minutes', ?User $user = null): WorkflowRun
    {
        return $this->start([
            $this->trigger(['minutes' => 5]),
            $this->node('wait', 'logic.delay', ['amount' => $amount, 'unit' => $unit]),
            $this->node('log', 'output.log', ['message' => 'after the wait']),
        ], [$this->edge('start', 'wait'), $this->edge('wait', 'log')], $user);
    }

    /**
     * start → ask (Approval) → approved: yes, rejected: no
     */
    private function asking(User $user, string $expires = 'never'): WorkflowRun
    {
        return $this->start([
            $this->trigger(['amount' => 40]),
            $this->node('ask', 'logic.approval', ['message' => 'Refund {{ trigger.amount }}?', 'expires' => $expires]),
            $this->node('yes', 'output.log', ['message' => 'refunded']),
            $this->node('no', 'output.log', ['message' => 'kept']),
        ], [$this->edge('start', 'ask'), $this->edge('ask', 'yes', 'approved'), $this->edge('ask', 'no', 'rejected')], $user);
    }

    // --- Delay ---

    public function test_a_delay_waits_without_holding_a_worker_and_carries_on_when_its_time_comes()
    {
        $run = $this->delayed('{{ trigger.minutes }}');
        $this->work();

        $run->refresh();
        $wait = $this->step($run, 'wait');
        $this->assertSame(RunStatus::Waiting, $run->status);
        $this->assertSame(NodeStatus::Waiting, $wait->status);
        $this->assertSame('delay', $wait->waiting_for);
        // Allowing for the few seconds the worker takes to start
        $this->assertEqualsWithDelta(now()->addMinutes(5)->timestamp, $wait->resume_at->timestamp, 15);
        // Its job is put off until then, and nothing else is left for a worker
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertGreaterThan(now()->addMinutes(4)->timestamp, DB::table('jobs')->value('available_at'));

        $this->travel(6)->minutes();
        $this->work();

        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(NodeStatus::Succeeded, $this->step($run, 'wait')->status);
        $this->assertSame(['minutes' => 5], $this->step($run, 'wait')->output);
        $this->assertSame(['after the wait'], $run->logs()->pluck('message')->all());
        // Carrying on is not a new step, nor a new try
        $this->assertSame(1, $this->step($run, 'wait')->attempt);
        $this->assertSame(3, $run->executed_steps);
    }

    public function test_a_delay_of_no_sense_or_past_a_month_fails()
    {
        $run = $this->delayed('soon');
        $this->work();
        $this->assertSame('Delay: How long should be a number of minutes, not "soon".', $run->fresh()->error);

        $run = $this->delayed('31', 'days');
        $this->work();
        $this->assertSame('Delay: A delay can be at most 30 days.', $run->fresh()->error);
    }

    // --- Approval ---

    public function test_an_approval_waits_for_an_answer_and_goes_that_way()
    {
        $user = User::factory()->create();
        $run = $this->asking($user);
        $this->work();

        $ask = $this->step($run->fresh(), 'ask');
        $this->assertSame(RunStatus::Waiting, $run->fresh()->status);
        $this->assertSame('approval', $ask->waiting_for);
        $this->assertSame('Refund 40?', $ask->wait['message']);
        $this->assertNull($ask->resume_at);

        $this->actingAs($user)
            ->postJson(route('workflow-runs.answer', [$run, 'ask']), ['approved' => true, 'note' => 'Fine'])
            ->assertOk()
            ->assertJsonPath('run.can.act', true);
        $this->work();

        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame('approved', $this->step($run, 'ask')->handle);
        $this->assertSame($user->name, $this->step($run, 'ask')->output['by']);
        $this->assertSame('Fine', $this->step($run, 'ask')->output['note']);
        $this->assertSame(NodeStatus::Skipped, $this->step($run, 'no')->status);
        $this->assertSame(['refunded'], $run->logs()->pluck('message')->all());

        // Answered once is answered
        $this->actingAs($user)
            ->postJson(route('workflow-runs.answer', [$run, 'ask']), ['approved' => false])
            ->assertStatus(409);
    }

    public function test_a_rejection_goes_the_other_way()
    {
        $user = User::factory()->create();
        $run = $this->asking($user);
        $this->work();

        $this->actingAs($user)->postJson(route('workflow-runs.answer', [$run, 'ask']), ['approved' => false])->assertOk();
        $this->work();

        $this->assertSame(['kept'], $run->logs()->pluck('message')->all());
        $this->assertSame('rejected', $this->step($run, 'ask')->handle);
    }

    public function test_an_approval_nobody_answers_in_time_is_rejected()
    {
        $user = User::factory()->create();
        $run = $this->asking($user, '1h');
        $this->work();

        $this->travel(61)->minutes();
        $this->work();

        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertTrue($this->step($run, 'ask')->output['expired']);
        $this->assertSame(['kept'], $run->logs()->pluck('message')->all());
    }

    public function test_only_those_who_can_edit_the_workflow_answer()
    {
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $project = Project::factory()->withMember($editor, Project::EDITOR)->withMember($viewer, Project::VIEWER)->create();
        $workflow = Workflow::factory()->forProject($project, $editor)->steps([
            $this->trigger(),
            $this->node('ask', 'logic.approval', ['message' => 'Go?']),
        ], [$this->edge('start', 'ask')])->create();
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $editor), $editor);
        $this->work();

        $this->actingAs($viewer)
            ->getJson(route('workflow-runs.show', $run))
            ->assertJsonPath('run.can.act', false)
            ->assertJsonPath('run.steps.1.wait.message', 'Go?');
        $this->actingAs($viewer)->postJson(route('workflow-runs.answer', [$run, 'ask']), ['approved' => true])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('workflow-runs.cancel', $run))->assertForbidden();
        $this->assertSame(NodeStatus::Waiting, $this->step($run, 'ask')->status);
    }

    // --- cancelling ---

    public function test_a_cancelled_run_ends_and_its_wait_never_wakes()
    {
        $user = User::factory()->create();
        $run = $this->delayed(user: $user);
        $this->work();

        $this->actingAs($user)
            ->postJson(route('workflow-runs.cancel', $run))
            ->assertOk()
            ->assertJsonPath('run.status', 'cancelled')
            ->assertJsonPath('run.error', "Cancelled by {$user->name}.");

        $this->travel(6)->minutes();
        $this->work();

        $run->refresh();
        $this->assertSame(RunStatus::Cancelled, $run->status);
        $this->assertSame(NodeStatus::Cancelled, $this->step($run, 'wait')->status);
        $this->assertSame(NodeStatus::Cancelled, $this->step($run, 'log')->status);
        $this->assertSame(0, $run->logs()->count());
    }

    // --- what the sweep puts right ---

    public function test_a_step_whose_worker_died_is_handed_to_another()
    {
        $run = $this->start([$this->trigger(), $this->node('log', 'output.log', ['message' => 'done'])], [$this->edge('start', 'log')]);
        DB::table('jobs')->delete();
        // A worker took it, and died
        $this->step($run, 'start')->forceFill(['status' => NodeStatus::Running, 'attempt' => 1, 'lease_until' => now()->subSecond()])->save();

        $this->assertSame(['requeued' => 1, 'asked' => 0, 'failed' => 0, 'woken' => 0, 'settled' => 0, 'stuck' => 0], Engine::recover());
        $this->work();

        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(2, $this->step($run, 'start')->attempt);
        $this->assertSame(['done'], $run->logs()->pluck('message')->all());
    }

    public function test_a_step_whose_worker_keeps_dying_fails_in_the_end()
    {
        $run = $this->start([$this->trigger()], []);
        DB::table('jobs')->delete();
        $this->step($run, 'start')->forceFill([
            'status' => NodeStatus::Running,
            'attempt' => Engine::MAX_ATTEMPTS,
            'lease_until' => now()->subSecond(),
        ])->save();

        Engine::recover();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('Its worker stopped before it finished, 3 times.', $run->error);
    }

    public function test_a_step_still_held_is_left_alone()
    {
        $run = $this->start([$this->trigger()], []);
        $this->step($run, 'start')->forceFill(['status' => NodeStatus::Running, 'attempt' => 1, 'lease_until' => now()->addMinute()])->save();

        $this->assertSame(['requeued' => 0, 'asked' => 0, 'failed' => 0, 'woken' => 0, 'settled' => 0, 'stuck' => 0], Engine::recover());
        $this->assertSame(NodeStatus::Running, $this->step($run, 'start')->status);
    }

    public function test_a_wait_whose_wake_up_was_lost_is_woken()
    {
        $run = $this->delayed('1');
        $this->work();
        // Its put-off job is lost
        DB::table('jobs')->delete();

        $this->travel(10)->minutes();
        $this->assertSame(1, Engine::recover()['woken']);
        $this->work();

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
    }

    public function test_a_queued_step_whose_job_was_turned_away_gets_a_new_one()
    {
        $run = $this->start([$this->trigger()], []);
        DB::table('jobs')->delete();

        Engine::abandon($this->step($run, 'start')->id, 'Its worker stopped before it finished.', mine: false);
        $this->work();

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
    }

    public function test_many_ways_that_meet_start_the_step_after_them_once()
    {
        $branches = range(1, 12);
        $run = $this->start([
            $this->trigger(),
            ...array_map(fn ($i) => $this->node("b{$i}", 'data.set', ['fields' => [['name' => 'n', 'value' => (string) $i]]]), $branches),
            $this->node('join', 'data.set', ['fields' => [['name' => 'total', 'value' => '{{ sum('.implode(', ', array_map(fn ($i) => "input.b{$i}.n", $branches)).') }}']]]),
        ], [
            ...array_map(fn ($i) => $this->edge('start', "b{$i}"), $branches),
            ...array_map(fn ($i) => $this->edge("b{$i}", 'join'), $branches),
        ]);

        $this->work();

        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(['total' => 78], $this->step($run, 'join')->output);
        $this->assertSame(1, $this->step($run, 'join')->attempt);
        $this->assertSame(14, $run->executed_steps);
    }
}
