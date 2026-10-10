<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Definition;
use App\Support\Workflow\Engine;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shapes a run can take beyond a straight line: a Switch's many ways, a
 * Merge bringing them back, For each running another workflow per item, and
 * one chat's messages kept in order -- or only the latest answered.
 */
class WorkflowFlowTest extends TestCase
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

    private function begin(User $user, array $nodes, array $edges, array $payload = []): WorkflowRun
    {
        $nodes[0]['config']['payload'] = json_encode($payload ?: ['n' => 1]);
        $workflow = Workflow::factory()->for($user)->steps($nodes, $edges)->create();

        return Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
    }

    private function step(WorkflowRun $run, string $key): WorkflowNodeRun
    {
        return $run->steps()->where('node_key', $key)->sole();
    }

    // --- Switch ---

    public function test_a_switch_goes_the_first_way_that_is_true_or_otherwise()
    {
        $user = User::factory()->create();
        $ways = ['ways' => [['name' => 'big', 'value' => 'trigger.total > 100'], ['name' => 'some', 'value' => 'trigger.total > 10']]];
        $shape = fn (int $total) => $this->begin($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('sort', 'logic.switch', $ways),
            $this->node('big', 'output.log', ['message' => 'big']),
            $this->node('some', 'output.log', ['message' => 'some']),
            $this->node('none', 'output.log', ['message' => 'none']),
        ], [
            $this->edge('start', 'sort'),
            $this->edge('sort', 'big', 'big'),
            $this->edge('sort', 'some', 'some'),
            $this->edge('sort', 'none', 'otherwise'),
        ], ['total' => $total]);

        $runs = [500 => $shape(500), 50 => $shape(50), 5 => $shape(5)];
        $this->work();

        foreach ([500 => ['big', 'big'], 50 => ['some', 'some'], 5 => ['otherwise', 'none']] as $total => [$way, $said]) {
            $run = $runs[$total]->fresh();
            $this->assertSame(RunStatus::Succeeded, $run->status);
            $this->assertSame($way, $this->step($run, 'sort')->handle);
            $this->assertSame([$said], $run->logs()->pluck('message')->all());
        }

        // Each way it tried, as it worked it out
        $this->assertSame(['big' => false, 'some' => true], $this->step($runs[50]->fresh(), 'sort')->input['settings']);
    }

    public function test_a_switch_names_its_ways_once_and_never_otherwise_and_tests_each()
    {
        $problems = Definition::problems(Definition::normalize(['nodes' => [
            $this->node('start', 'trigger.manual'),
            $this->node('sort', 'logic.switch', ['ways' => [
                ['name' => 'otherwise', 'value' => 'true'],
                ['name' => 'big', 'value' => ''],
                ['name' => 'odd', 'value' => 'input.total >'],
            ]]),
        ], 'edges' => [$this->edge('start', 'sort')]]));
        $messages = implode(' | ', array_column($problems, 'message'));

        $this->assertStringContainsString('"otherwise" is the way taken when no other is', $messages);
        $this->assertStringContainsString('"big" needs a formula saying when it is taken', $messages);
        $this->assertStringContainsString('"odd":', $messages);

        // A way that isn't one of its own can't be joined from
        $this->expectExceptionMessageMatches('/no way out called "small"/');
        Definition::normalize(['nodes' => [
            $this->node('start', 'trigger.manual'),
            $this->node('sort', 'logic.switch', ['ways' => [['name' => 'big', 'value' => 'true']]]),
            $this->node('log', 'output.log', ['message' => 'x']),
        ], 'edges' => [$this->edge('start', 'sort'), $this->edge('sort', 'log', 'small')]]);
    }

    // --- Merge ---

    public function test_a_merge_waits_for_every_way_taken_and_gives_out_each_by_its_key()
    {
        $user = User::factory()->create();
        $run = $this->begin($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('check', 'logic.if', ['condition' => 'true']),
            $this->node('a', 'data.set', ['fields' => [['name' => 'from', 'value' => 'a']]]),
            $this->node('b', 'data.set', ['fields' => [['name' => 'from', 'value' => 'b']]]),
            $this->node('never', 'data.set', ['fields' => [['name' => 'from', 'value' => 'never']]]),
            $this->node('both', 'logic.merge', ['wait' => 'all']),
        ], [
            $this->edge('start', 'check'),
            $this->edge('check', 'a', 'true'),
            $this->edge('check', 'b', 'true'),
            $this->edge('check', 'never', 'false'),
            $this->edge('a', 'both'),
            $this->edge('b', 'both'),
            $this->edge('never', 'both'),
        ]);
        $this->work();

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertEquals(['a' => ['from' => 'a'], 'b' => ['from' => 'b']], $this->step($run, 'both')->output);
    }

    public function test_a_merge_can_carry_on_with_the_first_to_arrive()
    {
        $user = User::factory()->create();
        $run = $this->begin($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('quick', 'data.set', ['fields' => [['name' => 'from', 'value' => 'quick']]]),
            $this->node('slow', 'logic.delay', ['amount' => '5', 'unit' => 'minutes']),
            $this->node('first', 'logic.merge', ['wait' => 'first']),
            $this->node('after', 'output.log', ['message' => 'first came {{ input.from }}']),
        ], [
            $this->edge('start', 'quick'),
            $this->edge('start', 'slow'),
            $this->edge('quick', 'first'),
            $this->edge('slow', 'first'),
            $this->edge('first', 'after'),
        ]);
        $this->work();

        // On without waiting for the delay
        $this->assertSame(NodeStatus::Succeeded, $this->step($run, 'first')->status);
        $this->assertSame(['first came quick'], $run->fresh()->logs()->pluck('message')->all());
        $this->assertSame(RunStatus::Waiting, $run->fresh()->status);

        // The late one is let go: nothing runs twice
        $this->travel(6)->minutes();
        $this->work();

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->logs()->count());
    }

    // --- For each ---

    private function callable(User $user, array $then): Workflow
    {
        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('called', 'trigger.call'),
            $then,
        ], [$this->edge('called', $then['id'])])->create(['title' => 'Double it']);
        $workflow->freeze(WorkflowVersion::PUBLISHED, $user);

        return $workflow->fresh();
    }

    public function test_for_each_runs_another_workflow_per_item_a_few_at_a_time_and_gathers_what_each_gave()
    {
        $user = User::factory()->create();
        $double = $this->callable($user, $this->node('twice', 'data.set', ['fields' => [['name' => 'doubled', 'value' => '{{ trigger.item * 2 }}']]]));

        $run = $this->begin($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('each', 'logic.each', ['workflow' => $double->ref_id, 'items' => 'trigger.nums', 'at_once' => 2]),
        ], [$this->edge('start', 'each')], ['nums' => [1, 2, 3, 4, 5]]);

        $this->work();

        // Never more than two going at once: the third began only once one had ended
        $children = WorkflowRun::where('caller_node_run_id', $this->step($run, 'each')->id)->orderBy('caller_index')->get();
        $this->assertCount(5, $children);
        $this->assertTrue($children[2]->created_at->gte($children[0]->finished_at) || $children[2]->created_at->gte($children[1]->finished_at));
        $this->assertSame([1], $children->pluck('depth')->unique()->values()->all());
        $this->assertSame([$run->id], $children->pluck('origin_run_id')->unique()->values()->all());

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $output = $this->step($run, 'each')->output;
        $this->assertSame(5, $output['succeeded']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($output['results'], 'item'));
        $this->assertSame([2, 4, 6, 8, 10], array_map(fn ($result) => $result['output']['doubled'], $output['results']));
        $this->assertSame(1, $children[0]->trigger_data['index']);
        $this->assertSame(5, $children[0]->trigger_data['of']);
    }

    public function test_for_each_says_which_failed_and_its_runs_stop_when_its_run_does()
    {
        $user = User::factory()->create();
        $picky = $this->callable($user, $this->node('check', 'http.request', ['url' => 'http://127.0.0.1/{{ trigger.item }}']));

        $run = $this->begin($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('each', 'logic.each', ['workflow' => $picky->ref_id, 'items' => 'trigger.nums']),
        ], [$this->edge('start', 'each')], ['nums' => [1, 2]]);
        $this->work();

        $output = $this->step($run, 'each')->output;
        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(['failed', 'failed'], array_column($output['results'], 'status'));
        $this->assertSame(2, $output['failed']);

        // Stopped while what it started still waits
        $slow = $this->callable($user, $this->node('wait', 'logic.delay', ['amount' => '1', 'unit' => 'hours']));
        $stopped = $this->begin($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('each', 'logic.each', ['workflow' => $slow->ref_id, 'items' => 'trigger.nums']),
        ], [$this->edge('start', 'each')], ['nums' => [1, 2, 3]]);
        $this->work();
        $this->assertSame(3, WorkflowRun::where('caller_node_run_id', $this->step($stopped, 'each')->id)->where('status', RunStatus::Waiting->value)->count());

        Engine::cancel($stopped->fresh(), $user);

        $this->assertSame(
            ['cancelled'],
            WorkflowRun::where('caller_node_run_id', $this->step($stopped, 'each')->id)->pluck('status')->map->value->unique()->values()->all(),
        );
    }

    public function test_for_each_offers_only_the_owners_published_workflows_run_this_way()
    {
        $user = User::factory()->create();
        $mine = $this->callable($user, $this->node('log', 'output.log', ['message' => 'x']));
        $theirs = $this->callable(User::factory()->create(), $this->node('log', 'output.log', ['message' => 'x']));
        $plain = Workflow::factory()->for($user)->steps([$this->node('start', 'trigger.manual')])->create();
        $plain->freeze(WorkflowVersion::PUBLISHED, $user);

        $this->assertNotNull(References::callable($user, $mine->ref_id));
        $this->assertNull(References::callable($user, $theirs->ref_id));
        $this->assertNull(References::callable($user, $plain->ref_id));

        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('start', 'trigger.manual'),
            $this->node('each', 'logic.each', ['workflow' => $theirs->ref_id]),
        ], [$this->edge('start', 'each')])->create();
        $this->assertStringContainsString('When run by another workflow', References::allProblems($workflow)[0]['message']);

        $this->actingAs($user)->get(route('workflows.show', $workflow))
            ->assertInertia(fn ($page) => $page->where('options.workflows', [['ref_id' => $mine->ref_id, 'title' => 'Double it']]));
    }

    // --- one chat's messages ---

    private function chatRun(Workflow $workflow, WorkflowVersion $version, string $chat, string $text): WorkflowRun
    {
        return Engine::start($workflow, $version, null, 'telegram', 'heard', [
            'service' => 'telegram', 'chat' => ['id' => $chat], 'text' => $text,
        ]);
    }

    private function chatWorkflow(User $user, string $perChat): array
    {
        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('heard', 'trigger.telegram', ['connection' => 'x', 'per_chat' => $perChat]),
            $this->node('think', 'logic.delay', ['amount' => '1', 'unit' => 'minutes']),
            $this->node('say', 'output.log', ['message' => 'answered {{ trigger.text }}']),
        ], [$this->edge('heard', 'think'), $this->edge('think', 'say')])->create();

        return [$workflow, $workflow->freeze(WorkflowVersion::PUBLISHED, $user)];
    }

    public function test_one_at_a_time_per_chat_answers_a_chat_in_order_and_other_chats_meanwhile()
    {
        $user = User::factory()->create();
        [$workflow, $version] = $this->chatWorkflow($user, 'one');

        $first = $this->chatRun($workflow, $version, '77', 'one');
        $second = $this->chatRun($workflow, $version, '77', 'two');
        $elsewhere = $this->chatRun($workflow, $version, '88', 'hi');
        $this->work();

        // The second waits its turn; another chat doesn't wait for this one
        $this->assertSame(RunStatus::Waiting, $first->fresh()->status);
        $this->assertSame(RunStatus::Queued, $second->fresh()->status);
        $this->assertSame(RunStatus::Waiting, $elsewhere->fresh()->status);

        $this->travel(2)->minutes();
        $this->work();
        $this->assertSame(RunStatus::Succeeded, $first->fresh()->status);
        $this->assertSame(RunStatus::Waiting, $second->fresh()->status);

        $this->travel(2)->minutes();
        $this->work();
        $this->assertSame(RunStatus::Succeeded, $second->fresh()->status);
        $this->assertTrue($second->fresh()->started_at->gte($first->fresh()->finished_at));
    }

    public function test_only_the_latest_per_chat_stops_the_run_before_it()
    {
        $user = User::factory()->create();
        [$workflow, $version] = $this->chatWorkflow($user, 'latest');

        $first = $this->chatRun($workflow, $version, '77', 'one');
        $this->work();
        $second = $this->chatRun($workflow, $version, '77', 'one, I meant two');
        $this->work();
        $this->travel(2)->minutes();
        $this->work();

        $this->assertSame(RunStatus::Cancelled, $first->fresh()->status);
        $this->assertSame('superseded', $first->fresh()->reason);
        $this->assertSame(['answered one, I meant two'], $second->fresh()->logs()->pluck('message')->all());
        $this->assertSame(0, $first->fresh()->logs()->count());
    }
}
