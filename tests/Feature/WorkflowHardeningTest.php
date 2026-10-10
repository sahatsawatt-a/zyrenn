<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\User;
use App\Models\Workflow\InboundEvent;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Alerts;
use App\Support\Workflow\Engine;
use App\Support\Workflow\Health;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\Retention;
use App\Support\Workflow\RunStatus;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

/**
 * Keeping an eye on workflows once they run by themselves: someone is told
 * when one fails or is stuck, the numbers say how they are doing, what is
 * old is let go, who changed what is kept, and a run carries on with the
 * steps it was published with.
 */
class WorkflowHardeningTest extends TestCase
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

    /**
     * A published workflow whose one step after its webhook trigger is $then.
     */
    private function published(User $user, array $then): Workflow
    {
        return Workflow::factory()->for($user)->steps([
            $this->node('hook', 'trigger.webhook', ['token' => str_repeat('a', 32)]),
            $then,
        ], [$this->edge('hook', $then['id'])])->create();
    }

    private function fire(Workflow $workflow, ?WorkflowVersion $version = null): WorkflowRun
    {
        $version ??= $workflow->publishedVersion ?? $workflow->freeze(WorkflowVersion::PUBLISHED, $workflow->user);

        return Engine::start($workflow, $version, null, 'webhook', 'hook', ['service' => 'webhook', 'body' => []]);
    }

    private function alerts(User $user): ChatRoom
    {
        return $user->chatRooms()->where('external_key', Alerts::ROOM_KEY)->sole();
    }

    // --- alerts ---

    public function test_a_workflow_that_fails_by_itself_is_told_of_once_in_a_while_in_the_owners_alerts_chat()
    {
        $user = User::factory()->create();
        // Blocked before anything is sent: a private address
        $workflow = $this->published($user, $this->node('call', 'http.request', ['url' => 'http://127.0.0.1/admin']));

        $first = $this->fire($workflow);
        $this->work();
        $second = $this->fire($workflow);
        $this->work();

        $this->assertSame(RunStatus::Failed, $first->fresh()->status);
        $this->assertSame(RunStatus::Failed, $second->fresh()->status);

        $room = $this->alerts($user);
        $this->assertSame('Workflow alerts', $room->title);
        $said = $room->messages()->get();
        $this->assertCount(1, $said);
        $this->assertStringContainsString("“{$workflow->title}” failed", $said[0]->content);
        $this->assertSame('failed', $said[0]->meta['alert']);
        $this->assertNotNull($first->fresh()->alerted_at);
        // Within the quiet half hour, the second failure is not told again
        $this->assertNull($second->fresh()->alerted_at);
    }

    public function test_a_run_by_hand_that_fails_is_not_told_of()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('start', 'trigger.manual'),
            $this->node('call', 'http.request', ['url' => 'http://127.0.0.1/admin']),
        ], [$this->edge('start', 'call')])->create();

        Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        $this->assertSame(0, $user->chatRooms()->where('external_key', Alerts::ROOM_KEY)->count());
    }

    public function test_steps_no_worker_takes_are_told_of_once()
    {
        $user = User::factory()->create();
        $workflow = $this->published($user, $this->node('log', 'output.log', ['message' => 'hi']));
        $run = $this->fire($workflow);
        // No worker comes for it
        DB::table('jobs')->delete();
        WorkflowNodeRun::where('workflow_run_id', $run->id)->where('status', NodeStatus::Queued->value)
            ->update(['queued_at' => now()->subMinutes(Alerts::STUCK_MINUTES + 1)]);

        $this->assertSame(1, Engine::recover()['stuck']);
        $this->assertSame(0, Engine::recover()['stuck']);

        $this->assertStringContainsString('waited over 10 minutes for a worker', $this->alerts($user)->messages()->sole()->content);
        $this->assertSame(1, Health::of($user)['stuck']);
    }

    public function test_a_send_that_may_not_have_gone_out_asks_its_owner()
    {
        $user = User::factory()->create();
        $bot = Connection::factory()->telegram()->for($user)->create(['secrets' => ['bot_token' => '123:abc']]);
        $workflow = $this->published($user, $this->node('send', 'telegram.send', ['connection' => $bot->ref_id, 'chat' => '77', 'text' => 'hi']));
        $run = $this->fire($workflow);
        // Its worker died partway through the send
        $step = $run->steps()->where('node_key', 'send')->sole();
        $step->forceFill(['status' => NodeStatus::Running, 'attempt' => 1, 'started_at' => now(), 'lease_until' => now()->subMinute()])->save();
        DB::table('jobs')->delete();

        $this->assertSame(1, Engine::recover()['asked']);

        $this->assertStringContainsString('waits for you to say whether to send it again', $this->alerts($user)->messages()->sole()->content);
    }

    // --- how they are doing ---

    public function test_the_numbers_count_an_owners_runs_only()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->published($user, $this->node('log', 'output.log', ['message' => 'hi']));
        $theirs = $this->published($other, $this->node('log', 'output.log', ['message' => 'hi']));

        $this->fire($mine);
        $this->fire($mine);
        $this->fire($theirs);
        $this->work();

        $health = Health::of($user);
        $this->assertSame(2, $health['runs']['total']);
        $this->assertSame(2, $health['runs']['succeeded']);
        $this->assertNotNull($health['duration_ms']['p50']);
        // Each step's wait for a worker is measured from when it was queued
        $this->assertNotNull($health['queue_ms']['p95']);
        $this->assertSame(3, Health::of(null)['runs']['total']);
    }

    // --- letting go of what is old ---

    public function test_old_runs_events_and_records_are_let_go_but_a_waiting_run_is_kept()
    {
        $user = User::factory()->create();
        $workflow = $this->published($user, $this->node('log', 'output.log', ['message' => 'hi']));
        $old = $this->fire($workflow);
        $recent = $this->fire($workflow);
        $this->work();

        $waiting = Workflow::factory()->for($user)->steps([
            $this->node('hook', 'trigger.webhook', ['token' => str_repeat('b', 32)]),
            $this->node('wait', 'logic.delay', ['amount' => '20', 'unit' => 'days']),
        ], [$this->edge('hook', 'wait')])->create();
        $long = $this->fire($waiting);
        $this->work();

        WorkflowRun::whereKey([$old->id, $long->id])->update(['finished_at' => now()->subDays(31), 'created_at' => now()->subDays(31)]);
        InboundEvent::create(['source' => 'telegram:x', 'event_id' => 'old', 'received_at' => now()->subDays(15)]);
        InboundEvent::create(['source' => 'telegram:x', 'event_id' => 'new', 'received_at' => now()]);
        AuditEvent::create(['user_id' => $user->id, 'action' => 'workflow.run', 'subject_type' => 'workflow', 'subject_ref' => $workflow->ref_id, 'created_at' => now()->subDays(400)]);

        $gone = Retention::prune();
        $this->assertSame(['runs' => 1, 'events' => 1, 'audit' => 1], $gone, json_encode($gone));

        $this->assertNull($old->fresh());
        $this->assertSame(0, WorkflowNodeRun::where('workflow_run_id', $old->id)->count());
        $this->assertNotNull($recent->fresh());
        $this->assertSame(RunStatus::Waiting, $long->fresh()->status);
        $this->assertSame(['new'], InboundEvent::pluck('event_id')->all());
    }

    // --- who changed what ---

    public function test_publishing_switching_on_running_and_changing_a_connection_are_kept_without_secrets()
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('start', 'trigger.manual'),
            $this->node('log', 'output.log', ['message' => 'hi']),
        ], [$this->edge('start', 'log')])->create();

        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();
        $this->actingAs($user)->postJson(route('workflows.runs.store', $workflow), ['revision' => $workflow->fresh()->revision])->assertCreated();

        $this->assertSame(
            ['workflow.run', 'workflow.enabled', 'workflow.published'],
            AuditEvent::where('subject_ref', $workflow->ref_id)->orderByDesc('id')->pluck('action')->all(),
        );

        $this->actingAs($user)->post(route('connections.store'), [
            'name' => 'Shop bot', 'service' => 'telegram', 'settings' => ['receive' => 'webhook'], 'secrets' => ['bot_token' => '123:first'],
        ])->assertRedirect();
        $bot = $user->connections()->sole();
        $this->actingAs($user)->patch(route('connections.update', $bot), [
            'name' => 'Shop bot', 'service' => 'telegram', 'settings' => ['receive' => 'webhook'], 'secrets' => ['bot_token' => '123:second'],
        ])->assertRedirect();

        $changed = AuditEvent::where('action', 'connection.changed')->sole();
        $this->assertSame(['fields' => ['bot_token']], $changed->details);
        $this->assertSame($user->id, $changed->actor_id);
        $this->assertStringNotContainsString('123:', json_encode(AuditEvent::pluck('details')->all()));

        // Shown on the connections page, to its owner
        $this->actingAs($user)->get(route('connections.index'))
            ->assertInertia(fn ($page) => $page->where('activity.0.action', 'connection.changed')->where('activity.1.action', 'connection.added'));
    }

    // --- a run keeps the steps it was published with ---

    public function test_a_step_from_a_newer_app_fails_plainly_and_a_waiting_run_finishes_on_its_own_version()
    {
        $user = User::factory()->create();
        $workflow = $this->published($user, $this->node('log', 'output.log', ['message' => 'hi']));
        $version = $workflow->freeze(WorkflowVersion::PUBLISHED, $user);
        $definition = $version->definition;
        $definition['nodes'][1]['nodeVersion'] = 99;
        // Versions are never changed; this stands for one written by a newer app
        DB::table('workflow_versions')->where('id', $version->id)->update(['definition' => json_encode($definition)]);

        $run = $this->fire($workflow, $version);
        $this->work();

        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        $this->assertStringContainsString('version 99, newer than this app runs', (string) $run->fresh()->error);

        // Waiting on a delay when a new version without it is published
        $waiting = Workflow::factory()->for($user)->steps([
            $this->node('hook', 'trigger.webhook', ['token' => str_repeat('c', 32)]),
            $this->node('wait', 'logic.delay', ['amount' => '5', 'unit' => 'minutes']),
            $this->node('after', 'output.log', ['message' => 'carried on']),
        ], [$this->edge('hook', 'wait'), $this->edge('wait', 'after')])->create();
        $old = $this->fire($waiting);
        $this->work();
        $this->assertSame(RunStatus::Waiting, $old->fresh()->status);

        $waiting->definition = ['nodes' => [$this->node('hook', 'trigger.webhook', ['token' => str_repeat('c', 32)])], 'edges' => []];
        $waiting->save();
        $waiting->freeze(WorkflowVersion::PUBLISHED, $user);

        $this->travel(6)->minutes();
        $this->work();

        $this->assertSame(RunStatus::Succeeded, $old->fresh()->status);
        $this->assertSame(['carried on'], $old->fresh()->logs()->pluck('message')->all());
    }

    // --- under load ---

    private function listening(User $user, array $trigger, array $then): Workflow
    {
        $workflow = Workflow::factory()->for($user)->steps([$trigger, $then], [$this->edge($trigger['id'], $then['id'])])->create();
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        return $workflow->fresh();
    }

    public function test_a_burst_of_webhooks_starts_one_run_for_each_however_often_each_is_sent()
    {
        $user = User::factory()->create();
        $token = str_repeat('d', 32);
        $workflow = $this->listening($user, $this->node('hook', 'trigger.webhook', ['token' => $token, 'dedupe' => 'id']), $this->node('log', 'output.log', ['message' => '{{ trigger.body.id }}']));

        // 100 events, a fifth of them sent twice, as senders do when unsure
        foreach ([...range(1, 100), ...range(1, 20)] as $id) {
            $this->postJson("/hooks/w/{$token}", ['id' => "ev-{$id}"])->assertStatus(202);
        }

        $this->work();

        $this->assertSame(100, $workflow->runs()->count());
        $this->assertSame(100, $workflow->runs()->where('status', RunStatus::Succeeded->value)->count());
        $this->assertSame(100, InboundEvent::where('source', 'like', 'webhook:%')->count());
    }

    public function test_a_wide_graph_runs_every_branch_and_joins_once()
    {
        $user = User::factory()->create();
        $branches = collect(range(1, 40))->map(fn (int $n) => $this->node("b{$n}", 'output.log', ['message' => "branch {$n}"]));
        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('start', 'trigger.manual'),
            ...$branches->all(),
            $this->node('join', 'output.log', ['message' => 'all in']),
        ], [
            ...$branches->map(fn (array $node) => $this->edge('start', $node['id']))->all(),
            ...$branches->map(fn (array $node) => $this->edge($node['id'], 'join'))->all(),
        ])->create();

        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(42, $run->executed_steps);
        $this->assertSame(1, $run->logs()->where('message', 'all in')->count());
    }

    // --- when something it needs is down ---

    public function test_when_the_database_fails_mid_webhook_the_sender_is_asked_again_and_nothing_is_half_kept()
    {
        $user = User::factory()->create();
        $token = str_repeat('e', 32);
        $workflow = $this->listening($user, $this->node('hook', 'trigger.webhook', ['token' => $token, 'dedupe' => 'id']), $this->node('log', 'output.log', ['message' => 'hi']));

        $down = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$down) {
            if ($down && str_contains($query, 'insert into "workflow_runs"')) {
                throw new QueryException('sqlite', $query, [], new RuntimeException('The database went away.'));
            }
        });

        $this->postJson("/hooks/w/{$token}", ['id' => 'ev-1'])->assertServerError();
        // Not kept as seen, so the sender's next try is taken
        $this->assertSame(0, InboundEvent::count());
        $this->assertSame(0, $workflow->runs()->count());

        $down = false;
        $this->postJson("/hooks/w/{$token}", ['id' => 'ev-1'])->assertStatus(202);
        $this->work();

        $this->assertSame(1, $workflow->runs()->where('status', RunStatus::Succeeded->value)->count());
    }

    public function test_runs_carry_on_when_the_live_server_is_down()
    {
        Broadcast::extend('down', fn () => new class extends Broadcaster implements BroadcasterContract
        {
            public function auth($request)
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return null;
            }

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new RuntimeException('Reverb is not answering.');
            }
        });
        config(['broadcasting.default' => 'down', 'broadcasting.connections.down' => ['driver' => 'down']]);

        $user = User::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('start', 'trigger.manual'),
            $this->node('log', 'output.log', ['message' => 'still here']),
        ], [$this->edge('start', 'log')])->create();

        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(['still here'], $run->fresh()->logs()->pluck('message')->all());
    }

    public function test_each_hook_is_held_back_by_itself_not_by_the_address_every_bot_shares()
    {
        $limits = fn (string $path) => collect(RateLimiter::limiter('hooks')(Request::create($path, 'POST', server: ['REMOTE_ADDR' => '149.154.167.1'])))
            ->map(fn ($limit) => [$limit->key, $limit->maxAttempts]);

        $one = $limits('/hooks/telegram/aaaa');
        $other = $limits('/hooks/telegram/bbbb');

        $this->assertNotSame($one[0][0], $other[0][0]);
        $this->assertSame(600, $one[0][1]);
        // The one address both come from shares only a far higher cap
        $this->assertSame($one[1][0], $other[1][0]);
        $this->assertSame(3000, $one[1][1]);
    }
}
