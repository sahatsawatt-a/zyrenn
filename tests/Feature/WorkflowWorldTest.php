<?php

namespace Tests\Feature;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowDelivery;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Table\TableStorage;
use App\Support\Workflow\Engine;
use App\Support\Workflow\Inbound\Schedules;
use App\Support\Workflow\Inbound\TelegramPoll;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Workflows reaching out of themselves: the chat, tables, AI, the web,
 * Telegram and LINE -- and starting by themselves, from a chat message, a
 * time, a webhook or a bot's message. Through the real queue; every outside
 * service is faked.
 */
class WorkflowWorldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
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
     * Start → each step in turn, run by hand and worked off.
     */
    private function runChain(User $user, array $steps, array $payload = [], ?Project $project = null): WorkflowRun
    {
        $nodes = [$this->node('start', 'trigger.manual', ['payload' => json_encode($payload ?: ['n' => 1])]), ...$steps];
        $edges = [];

        foreach (array_slice($nodes, 1) as $at => $node) {
            $edges[] = $this->edge($nodes[$at]['id'], $node['id']);
        }

        $factory = Workflow::factory()->steps($nodes, $edges);
        $workflow = $project ? $factory->forProject($project, $user)->create() : $factory->for($user)->create();
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        return $run->fresh();
    }

    /**
     * A published, switched-on workflow: this trigger, then these steps.
     */
    private function listening(User $user, array $trigger, array $steps = []): Workflow
    {
        $nodes = [$trigger, ...$steps];
        $edges = [];

        foreach (array_slice($nodes, 1) as $at => $node) {
            $edges[] = $this->edge($nodes[$at]['id'], $node['id']);
        }

        $workflow = Workflow::factory()->for($user)->steps($nodes, $edges)->create();
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        return $workflow->fresh();
    }

    private function step(WorkflowRun $run, string $key): WorkflowNodeRun
    {
        return $run->steps()->where('node_key', $key)->sole();
    }

    private function table(User $user): Table
    {
        $table = Table::factory()->for($user)->create(['title' => 'Orders']);
        TableStorage::newColumn($table, ['label' => 'Customer', 'type' => 'varchar']);
        TableStorage::newColumn($table, ['label' => 'Status', 'type' => 'varchar']);

        return $table->fresh('columns');
    }

    // --- switched on ---

    public function test_only_something_published_is_switched_on_and_it_listens_as_published()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->for($user)->create();
        $workflow = Workflow::factory()->for($user)->steps([$this->node('heard', 'trigger.chat', ['room' => $room->ref_id])])->create();

        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertStatus(422);

        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)
            ->patchJson(route('workflows.enable', $workflow), ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('listening', [['node_id' => 'heard', 'kind' => 'chat_room']]);

        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => false])->assertJsonPath('listening', []);
        $this->assertDatabaseCount('workflow_listeners', 0);
    }

    public function test_a_step_pointing_at_what_is_not_its_owners_stands_in_the_way()
    {
        $user = User::factory()->create();
        $theirs = Table::factory()->create();
        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('start', 'trigger.manual'),
            $this->node('add', 'table.add', ['table' => $theirs->ref_id, 'values' => [['name' => 'x', 'value' => '1']]]),
        ], [$this->edge('start', 'add')])->create();

        $this->actingAs($user)
            ->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])
            ->assertStatus(422)
            ->assertJsonPath('problems.0', ['message' => 'Table: choose one of this workflow\'s own tables.', 'node' => 'add']);
    }

    // --- chat ---

    public function test_a_message_in_a_room_starts_the_workflows_listening_there()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->for($user)->create();
        $workflow = $this->listening($user, $this->node('heard', 'trigger.chat', ['room' => $room->ref_id]), [
            $this->node('log', 'output.log', ['message' => '{{ trigger.message.author }} said {{ trigger.text }}']),
        ]);

        $room->messages()->create(['role' => ChatMessage::USER, 'user_id' => $user->id, 'content' => 'Hello']);
        $this->work();

        $run = $workflow->runs()->sole();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame('chat', $run->trigger);
        $this->assertNull($run->started_by);
        $this->assertSame(["{$user->name} said Hello"], $run->logs()->pluck('message')->all());
    }

    public function test_a_workflow_answering_in_the_room_it_listens_to_stops_a_few_runs_deep()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->for($user)->create();
        $workflow = $this->listening($user, $this->node('heard', 'trigger.chat', ['room' => $room->ref_id, 'include' => 'all']), [
            $this->node('echo', 'chat.send', ['room' => $room->ref_id, 'message' => 'Echo: {{ trigger.text }}']),
        ]);

        $room->messages()->create(['role' => ChatMessage::USER, 'user_id' => $user->id, 'content' => 'Hi']);
        $this->work();

        $this->assertSame(Engine::MAX_DEPTH + 1, $workflow->runs()->count());
        $this->assertSame(Engine::MAX_DEPTH, $workflow->runs()->max('depth'));
    }

    public function test_a_workflows_own_messages_start_nothing_unless_asked()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->for($user)->create();
        $workflow = $this->listening($user, $this->node('heard', 'trigger.chat', ['room' => $room->ref_id]), [
            $this->node('echo', 'chat.send', ['room' => $room->ref_id, 'message' => 'Echo: {{ trigger.text }}']),
        ]);

        $room->messages()->create(['role' => ChatMessage::USER, 'user_id' => $user->id, 'content' => 'Hi']);
        $this->work();

        $this->assertSame(1, $workflow->runs()->count());
        $this->assertSame(['Hi', 'Echo: Hi'], $room->messages()->orderBy('id')->pluck('content')->all());
    }

    public function test_a_room_for_each_outside_conversation_and_messages_said_as_the_workflow_once()
    {
        $user = User::factory()->create();
        $steps = [
            $this->node('find', 'chat.room', ['key' => 'telegram:{{ trigger.chat }}', 'title' => '{{ trigger.name }} on Telegram']),
            $this->node('say', 'chat.send', ['room' => '{{ node("find").room }}', 'message' => '{{ trigger.text }}', 'sender' => '{{ trigger.name }} (Telegram)']),
            $this->node('read', 'chat.fetch', ['room' => '{{ node("find").room }}', 'limit' => 5]),
        ];

        $first = $this->runChain($user, $steps, ['chat' => '42', 'name' => 'Somchai', 'text' => 'Hello']);
        $second = $this->runChain($user, $steps, ['chat' => '42', 'name' => 'Somchai', 'text' => 'Again']);

        $this->assertTrue($this->step($first, 'find')->output['created']);
        $this->assertFalse($this->step($second, 'find')->output['created']);
        $room = ChatRoom::where('external_key', 'telegram:42')->sole();
        $this->assertSame('Somchai on Telegram', $room->title);
        $this->assertSame($user->id, $room->user_id);

        $said = $room->messages()->orderBy('id')->first();
        $this->assertSame(ChatMessage::WORKFLOW, $said->role);
        $this->assertSame(['name' => 'Somchai (Telegram)', 'outside' => true, 'by' => $user->name, 'workflow' => $first->workflow->ref_id], $said->toChat()['sender']);
        $this->assertSame(['Hello', 'Again'], array_column($this->step($second, 'read')->output['messages'], 'content'));

        // Tried again, the step says nothing more
        $step = $this->step($second, 'say');
        $step->forceFill(['status' => NodeStatus::Queued])->save();
        Engine::runStep($step->id);
        $this->assertSame(2, $room->messages()->count());
    }

    public function test_a_projects_workflow_writes_only_in_the_projects_group_chats()
    {
        $editor = User::factory()->create();
        $project = Project::factory()->withMember($editor, Project::EDITOR)->create();
        $own = ChatRoom::factory()->for($editor)->create();

        $run = $this->runChain($editor, [$this->node('say', 'chat.send', ['room' => $own->ref_id, 'message' => 'x'])], [], $project);
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('is not a room this workflow can use', $run->error);

        $run = $this->runChain($editor, [$this->node('find', 'chat.room', ['key' => 'k1', 'title' => 'Team'])], [], $project);
        $room = ChatRoom::where('external_key', 'k1')->sole();
        $this->assertSame(ChatRoom::GROUP, $room->kind);
        $this->assertSame($project->id, $room->project_id);
        $this->assertSame($editor->id, $room->created_by);
    }

    // --- tables ---

    public function test_rows_are_added_found_and_changed_by_their_columns_names()
    {
        $user = User::factory()->create();
        $table = $this->table($user);

        $run = $this->runChain($user, [
            $this->node('add', 'table.add', ['table' => $table->ref_id, 'values' => [['name' => 'Customer', 'value' => '{{ trigger.name }}'], ['name' => 'status', 'value' => 'new']]]),
            $this->node('find', 'table.find', ['table' => $table->ref_id, 'column' => 'Status', 'value' => 'NEW', 'limit' => 10]),
            $this->node('done', 'table.update', ['table' => $table->ref_id, 'row' => '{{ node("add").row.id }}', 'values' => [['name' => 'Status', 'value' => 'paid']]]),
        ], ['name' => 'Ada']);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(1, $this->step($run, 'find')->output['count']);
        $this->assertSame('Ada', $this->step($run, 'find')->output['rows'][0]['customer']);
        $this->assertSame('paid', TableStorage::rows($table)[0]['status']);

        $broken = $this->runChain($user, [$this->node('add', 'table.add', ['table' => $table->ref_id, 'values' => [['name' => 'Nope', 'value' => '1']]])]);
        $this->assertStringContainsString('This table has no column "Nope"', $broken->error);
    }

    // --- AI ---

    public function test_ai_is_asked_through_the_owners_connection_and_its_answer_read_as_json()
    {
        Http::fake(['openrouter.ai/*' => Http::response("data: {\"choices\":[{\"delta\":{\"content\":\"```json\\n{\\\"mood\\\": \\\"happy\\\"}\\n```\"}}]}\n\ndata: [DONE]\n\n")]);
        $user = User::factory()->create();
        $ai = Connection::factory()->openrouter()->for($user)->create(['settings' => ['base_url' => 'https://openrouter.ai/api/v1', 'default_model' => 'some/model']]);

        $run = $this->runChain($user, [$this->node('ask', 'ai.chat', ['connection' => $ai->ref_id, 'system' => 'Answer in JSON', 'prompt' => 'How is {{ trigger.name }}?', 'answer' => 'json'])], ['name' => 'Ada']);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(['mood' => 'happy'], $this->step($run, 'ask')->output['json']);
        Http::assertSent(fn (Request $request) => $request['model'] === 'some/model'
            && $request['messages'][1]['content'] === 'How is Ada?'
            && $request->hasHeader('Authorization', 'Bearer sk-or-secret'));
    }

    // --- code ---

    public function test_code_is_run_by_the_code_runner_on_what_came_before_and_gives_out_what_it_returns()
    {
        // The runner answers by what it is asked to run, as the real one would for each
        Http::fake(['code:3000/run' => fn (Request $request) => Http::response(match (true) {
            str_contains($request['code'], 'boom') => ['ok' => false, 'error' => 'TypeError: boom', 'line' => 3, 'logs' => ['about to fail']],
            str_contains($request['code'], 'return {}') => '{"ok":true,"value":{},"logs":[]}',
            str_contains($request['code'], 'return [') => ['ok' => true, 'value' => [1, 2], 'logs' => []],
            default => ['ok' => true, 'value' => ['shout' => 'ADA', 'total' => 42], 'logs' => ['read Ada', 'done']],
        })]);
        $user = User::factory()->create();
        $code = "console.log('read', trigger.name);\nreturn { shout: trigger.name.toUpperCase(), total: node(\"sum\").n * 2 };";

        $run = $this->runChain($user, [
            $this->node('sum', 'data.set', ['fields' => [['name' => 'n', 'value' => '{{ 20 + 1 }}']]]),
            $this->node('work', 'data.code', ['code' => $code, 'seconds' => 3]),
            $this->node('none', 'data.code', ['code' => 'return {}']),
            $this->node('list', 'data.code', ['code' => 'return [1, 2]']),
            $this->node('after', 'output.log', ['message' => '{{ node("work").shout }} {{ node("work").total }} {{ len(node("list").value) }}']),
        ], ['name' => 'Ada']);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        // A record is given out as it is, an empty one too; anything else as "value"
        $this->assertSame([['shout' => 'ADA', 'total' => 42], [], ['value' => [1, 2]]], [$this->step($run, 'work')->output, $this->step($run, 'none')->output, $this->step($run, 'list')->output]);
        // What it logged is in the run's log, before what follows it
        $this->assertSame(['read Ada', 'done', 'ADA 42 2'], $run->logs()->pluck('message')->all());
        // It is handed the code as written, its time, and what a formula could read: nothing else
        Http::assertSent(fn (Request $request) => $request['code'] === $code
            && $request['seconds'] === 3
            && array_keys($request['data']) === ['trigger', 'input', 'steps']
            && $request['data']['trigger']['name'] === 'Ada'
            && $request['data']['steps']['sum'] === ['n' => 21]
            && $request['data']['input'] === ['n' => 21]);

        // What goes wrong in it is said as the code would say it, with the line
        $failed = $this->runChain($user, [$this->node('work', 'data.code', ['code' => "const a = 1;\n\nthrow new TypeError('boom');"])]);
        $this->assertSame([RunStatus::Failed, 'Run code: TypeError: boom (line 3)'], [$failed->status, $failed->error]);
        $this->assertSame(['about to fail'], $failed->logs()->pluck('message')->all());

        // With no runner to ask, it says so and waits to try again, rather than run anything here
        Http::fake(['code:3000/run' => fn () => throw new ConnectionException('refused')]);
        $unreached = $this->runChain($user, [$this->node('work', 'data.code', ['code' => 'return 1'])]);
        $this->assertSame(['retry_wait', 'The code runner could not be reached: it runs as the "code" container.'], [($waiting = $this->step($unreached, 'work'))->status->value, $waiting->error['message']]);
    }

    // --- the web ---

    public function test_a_request_reaches_only_the_public_internet()
    {
        $user = User::factory()->create();

        foreach (['http://127.0.0.1/admin', 'http://169.254.169.254/latest', 'http://10.0.0.5/', 'ftp://example.com/'] as $url) {
            $run = $this->runChain($user, [$this->node('call', 'http.request', ['url' => $url])]);

            $this->assertSame(RunStatus::Failed, $run->status, $url);
        }

        Http::assertNothingSent();
    }

    public function test_a_request_changing_something_carries_a_key_and_is_recorded()
    {
        Http::fake(['93.184.215.14/*' => Http::response(['id' => 7], 201)]);
        $user = User::factory()->create();
        $key = Connection::factory()->for($user)->create(['name' => 'API', 'service' => 'http_header', 'settings' => ['base_url' => 'https://93.184.215.14/v1', 'header_name' => 'X-Api-Key'], 'secrets' => ['header_value' => 'k-123']]);

        $run = $this->runChain($user, [$this->node('call', 'http.request', [
            'method' => 'POST',
            'url' => 'https://93.184.215.14/v1/orders',
            'auth' => $key->ref_id,
            'body_type' => 'json',
            'body' => '{{ trigger }}',
            'headers' => [['name' => 'X-Note', 'value' => 'from {{ trigger.who }}']],
        ])], ['who' => 'Ada']);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(['status' => 201, 'body' => ['id' => 7]], array_intersect_key($this->step($run, 'call')->output, ['status' => 1, 'body' => 1]));
        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Api-Key', 'k-123')
            && $request->hasHeader('X-Note', 'from Ada')
            && $request->hasHeader('Idempotency-Key')
            && $request->data() === ['who' => 'Ada']);
        $this->assertSame('sent', WorkflowDelivery::sole()->status);

        // The key goes nowhere else
        $elsewhere = $this->runChain($user, [$this->node('call', 'http.request', ['url' => 'https://93.184.215.15/v1', 'auth' => $key->ref_id])]);
        $this->assertStringContainsString('is only sent to https://93.184.215.14/v1', $elsewhere->error);
    }

    public function test_a_service_that_is_busy_is_asked_again_later()
    {
        Http::fake(['93.184.215.14/*' => Http::sequence()->push('busy', 429, ['Retry-After' => '30'])->push(['ok' => true])]);
        $user = User::factory()->create();

        $run = $this->runChain($user, [$this->node('call', 'http.request', ['url' => 'https://93.184.215.14/'])]);
        $step = $this->step($run, 'call');
        $this->assertSame(NodeStatus::RetryWait, $step->status);
        $this->assertEqualsWithDelta(now()->addSeconds(30)->timestamp, $step->resume_at->timestamp, 15);

        $this->travel(1)->minutes();
        $this->work();

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(2, $this->step($run, 'call')->attempt);
    }

    // --- Telegram and LINE ---

    private function bot(User $user, string $receive = 'webhook'): Connection
    {
        return Connection::factory()->telegram()->for($user)->create([
            'settings' => ['receive' => $receive],
            'secrets' => ['bot_token' => '123:abc', 'webhook_secret' => 'shh'],
        ]);
    }

    public function test_switching_on_a_bots_workflow_points_the_bot_at_its_hook()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $user = User::factory()->create();
        $bot = Connection::factory()->telegram()->for($user)->create(['settings' => ['receive' => 'webhook']]);

        $this->listening($user, $this->node('heard', 'trigger.telegram', ['connection' => $bot->ref_id]));

        $secret = $bot->fresh()->secret('webhook_secret');
        $this->assertNotNull($secret);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === "https://hooks.example.test/hooks/telegram/{$bot->ref_id}"
            && $request['secret_token'] === $secret);
    }

    public function test_a_bots_message_starts_a_run_once_and_the_answer_goes_back()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);
        $user = User::factory()->create();
        $bot = $this->bot($user);
        $workflow = $this->listening($user, $this->node('heard', 'trigger.telegram', ['connection' => $bot->ref_id, 'command' => '/hi']), [
            $this->node('answer', 'telegram.send', ['connection' => $bot->ref_id, 'chat' => '{{ trigger.chat.id }}', 'text' => 'Hello {{ trigger.sender.name }}']),
        ]);
        $update = ['update_id' => 500, 'message' => ['message_id' => 1, 'text' => '/hi there', 'chat' => ['id' => 77, 'type' => 'private'], 'from' => ['id' => 77, 'first_name' => 'Ada']]];

        $this->postJson("/hooks/telegram/{$bot->ref_id}", $update, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
        $this->postJson("/hooks/telegram/{$bot->ref_id}", $update, ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();
        // Telegram sending it again starts nothing
        $this->postJson("/hooks/telegram/{$bot->ref_id}", $update, ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();
        // Without the command, nothing either
        $this->postJson("/hooks/telegram/{$bot->ref_id}", [...$update, 'update_id' => 501, 'message' => [...$update['message'], 'text' => 'hello']], ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();
        $this->work();

        $run = $workflow->runs()->sole();
        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '77' && $request['text'] === 'Hello Ada');
        $this->assertSame('sent', WorkflowDelivery::sole()->status);
    }

    public function test_a_bot_that_is_asked_gets_its_messages_without_a_public_address()
    {
        Http::fake(['api.telegram.org/*/getUpdates*' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 10, 'message' => ['message_id' => 1, 'text' => 'one', 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'first_name' => 'A']]],
            ['update_id' => 11, 'message' => ['message_id' => 2, 'text' => 'two', 'chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1, 'first_name' => 'A']]],
        ]]), 'api.telegram.org/*' => Http::response(['ok' => true])]);
        $user = User::factory()->create();
        $bot = $this->bot($user, 'poll');
        $workflow = $this->listening($user, $this->node('heard', 'trigger.telegram', ['connection' => $bot->ref_id]));

        $this->assertSame(2, TelegramPoll::once());

        $this->assertSame(2, $workflow->runs()->count());
        $this->assertSame('12', $bot->fresh()->setting('offset'));
    }

    public function test_a_send_that_may_have_gone_out_is_never_sent_again_unasked()
    {
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $user = User::factory()->create();
        $bot = $this->bot($user);

        $run = $this->runChain($user, [$this->node('answer', 'telegram.send', ['connection' => $bot->ref_id, 'chat' => '1', 'text' => 'Hi'])]);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('may have gone out', $run->error);
        // Tried once, never again by itself
        $this->assertSame(1, $this->step($run, 'answer')->attempt);
        $this->assertSame('failed', WorkflowDelivery::sole()->status);
    }

    public function test_a_send_whose_worker_died_asks_before_it_is_sent_again()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);
        $user = User::factory()->create();
        $bot = $this->bot($user);
        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('start', 'trigger.manual'),
            $this->node('answer', 'telegram.send', ['connection' => $bot->ref_id, 'chat' => '1', 'text' => 'Hi']),
            $this->node('after', 'output.log', ['message' => 'sent']),
        ], [$this->edge('start', 'answer'), $this->edge('answer', 'after')])->create();

        $ask = function () use ($workflow, $user) {
            $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
            Engine::runStep($this->step($run, 'start')->id);
            DB::table('jobs')->delete();

            // A worker took the send, and died partway through it
            $step = $this->step($run, 'answer');
            $step->forceFill(['status' => NodeStatus::Running, 'attempt' => 1, 'lease_until' => now()->subSecond()])->save();
            WorkflowDelivery::create(['workflow_node_run_id' => $step->id, 'service' => 'telegram', 'status' => 'sending']);

            $this->assertSame(1, Engine::recover()['asked']);

            return $run;
        };

        $run = $ask();
        $step = $this->step($run, 'answer');
        $this->assertSame(NodeStatus::Waiting, $step->status);
        $this->assertSame('resend', $step->waiting_for);
        $this->assertStringContainsString('may not have gone out', $step->wait['message']);
        $this->assertSame('unknown', WorkflowDelivery::where('workflow_node_run_id', $step->id)->sole()->status);
        Http::assertNothingSent();

        // Not to be sent again: the run ends there
        $this->actingAs($user)->postJson(route('workflow-runs.answer', [$run, 'answer']), ['approved' => false])->assertOk();
        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        $this->assertStringContainsString('Not sent again', $run->fresh()->error);
        Http::assertNothingSent();

        // To be sent again: it is, and the run carries on
        $run = $ask();
        $this->actingAs($user)->postJson(route('workflow-runs.answer', [$run, 'answer']), ['approved' => true])->assertOk();
        $this->work();
        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_a_line_message_is_checked_kept_once_and_answered_with_its_reply_token()
    {
        Http::fake(['api.line.me/*' => Http::response([])]);
        $user = User::factory()->create();
        $channel = Connection::factory()->for($user)->create(['name' => 'Shop', 'service' => 'line', 'settings' => [], 'secrets' => ['channel_access_token' => 'tok', 'channel_secret' => 'line-secret']]);
        $workflow = $this->listening($user, $this->node('heard', 'trigger.line', ['connection' => $channel->ref_id]), [
            $this->node('answer', 'line.reply', ['connection' => $channel->ref_id, 'text' => 'Got "{{ trigger.text }}"']),
        ]);
        $body = json_encode(['events' => [[
            'type' => 'message', 'webhookEventId' => 'ev1', 'replyToken' => 'rt-1',
            'source' => ['type' => 'user', 'userId' => 'U1'], 'message' => ['type' => 'text', 'id' => 'm1', 'text' => 'hi'],
        ]]]);
        $sign = fn (string $secret) => base64_encode(hash_hmac('sha256', $body, $secret, true));

        $this->call('POST', "/hooks/line/{$channel->ref_id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LINE_SIGNATURE' => $sign('nope')], $body)->assertForbidden();
        $this->call('POST', "/hooks/line/{$channel->ref_id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LINE_SIGNATURE' => $sign('line-secret')], $body)->assertOk();
        $this->call('POST', "/hooks/line/{$channel->ref_id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LINE_SIGNATURE' => $sign('line-secret')], $body)->assertOk();
        $this->work();

        $this->assertSame(1, $workflow->runs()->count());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/message/reply') && $request['replyToken'] === 'rt-1' && $request['messages'][0]['text'] === 'Got "hi"');
    }

    public function test_replying_and_pushing_are_lines_two_calls_each_a_step_of_its_own()
    {
        Http::fake(['api.line.me/*' => Http::response([])]);
        $user = User::factory()->create();
        $channel = Connection::factory()->for($user)->create(['name' => 'Shop', 'service' => 'line', 'settings' => [], 'secrets' => ['channel_access_token' => 'tok', 'channel_secret' => 'line-secret']]);
        $heard = ['service' => 'line', 'chat' => ['id' => 'U9'], 'sender' => ['id' => 'U9'], 'text' => 'hi', 'reply' => ['token' => 'rt-9', 'to' => null]];
        $start = function (array $steps) use ($user, $channel, $heard): WorkflowRun {
            $nodes = [$this->node('heard', 'trigger.line', ['connection' => $channel->ref_id]), ...$steps];
            $workflow = Workflow::factory()->for($user)->steps($nodes, array_map(fn (array $step) => $this->edge('heard', $step['id']), $steps))->create();
            $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::PUBLISHED, $user), null, 'line', 'heard', $heard);
            $this->work();

            return $run->fresh();
        };

        $run = $start([
            $this->node('answer', 'line.reply', ['connection' => $channel->ref_id, 'token' => '{{ trigger.reply.token }}', 'text' => 'Hello']),
            $this->node('later', 'line.send', ['connection' => $channel->ref_id, 'to' => '{{ trigger.chat.id }}', 'text' => 'And again']),
        ]);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/message/reply') && $request['replyToken'] === 'rt-9' && $request['messages'][0]['text'] === 'Hello');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/message/push') && $request['to'] === 'U9' && $request->hasHeader('X-Line-Retry-Key'));

        // A reply needs the token a LINE message came with
        $run = $start([$this->node('answer', 'line.reply', ['connection' => $channel->ref_id, 'token' => '{{ trigger.reply.to }}', 'text' => 'Hello'])]);
        $this->assertStringContainsString('There is no reply token', $this->step($run, 'answer')->error['message']);

        // One published while "Send on LINE" still chose between the two goes on answering
        Http::fake(['api.line.me/*' => Http::response([])]);
        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('heard', 'trigger.line', ['connection' => $channel->ref_id]),
            $this->node('old', 'line.send', ['connection' => $channel->ref_id, 'text' => 'As before']),
        ], [$this->edge('heard', 'old')])->create();
        $version = $workflow->freeze(WorkflowVersion::PUBLISHED, $user);
        $frozen = $version->definition;
        $frozen['nodes'][1]['config']['mode'] = 'reply';
        // A version is never changed once written, so as it was written then
        DB::table('workflow_versions')->where('id', $version->id)->update(['definition' => json_encode($frozen)]);
        // Another message, with a token of its own: LINE takes one once, and the first was answered above
        $run = Engine::start($workflow, $version->fresh(), null, 'line', 'heard', [...$heard, 'reply' => ['token' => 'rt-10', 'to' => null]]);
        $this->work();

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status, (string) $run->fresh()->error);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/message/reply') && $request['replyToken'] === 'rt-10' && $request['messages'][0]['text'] === 'As before');

        // And a token answered with already is not tried again: the step says why, and what to use instead
        $again = Engine::start($workflow, $version->fresh(), null, 'line', 'heard', [...$heard, 'reply' => ['token' => 'rt-10', 'to' => null]]);
        $this->work();
        $this->assertSame([RunStatus::Failed, 'Push on LINE: Its reply token was used already: LINE takes one once. "Push on LINE" writes to someone at any time.'], [$again->fresh()->status, $again->fresh()->error]);
    }

    // --- webhooks and schedules ---

    public function test_a_webhook_starts_a_run_checks_its_sender_and_answers_setup_checks()
    {
        $user = User::factory()->create();
        $secret = Connection::factory()->for($user)->create(['name' => 'Shop secret', 'service' => 'webhook_secret', 'settings' => [], 'secrets' => ['secret' => 'whsec']]);
        $token = str_repeat('a', 32);
        $workflow = $this->listening($user, $this->node('hook', 'trigger.webhook', ['token' => $token, 'verify' => 'hmac', 'secret' => $secret->ref_id, 'dedupe' => 'event.id', 'handshake' => 'slack']), [
            $this->node('log', 'output.log', ['message' => 'Order {{ trigger.body.event.order }}']),
        ]);
        $body = json_encode(['event' => ['id' => 'e1', 'order' => 42]]);
        $signed = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'whsec')];

        $this->call('POST', '/hooks/w/'.str_repeat('b', 32), [], [], [], $signed, $body)->assertNotFound();
        $this->call('POST', "/hooks/w/{$token}", [], [], [], [...$signed, 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=0000'], $body)->assertStatus(401);
        $this->call('POST', "/hooks/w/{$token}", [], [], [], $signed, $body)->assertStatus(202);
        $this->call('POST', "/hooks/w/{$token}", [], [], [], $signed, $body)->assertStatus(202);
        $this->postJson("/hooks/w/{$token}", ['type' => 'url_verification', 'challenge' => 'c-1'])->assertExactJson(['challenge' => 'c-1']);
        $this->work();

        $run = $workflow->runs()->sole();
        $this->assertSame(['Order 42'], $run->logs()->pluck('message')->all());
        $this->assertArrayNotHasKey('cookie', $run->trigger_data['headers']);
    }

    public function test_a_schedule_starts_once_when_due_and_lets_a_run_still_going_finish()
    {
        $this->travelTo(now()->setTime(9, 0, 20));
        $user = User::factory()->create();
        $workflow = $this->listening($user, $this->node('every', 'trigger.schedule', ['cron' => '0 9 * * *']), [
            $this->node('wait', 'logic.delay', ['amount' => '2', 'unit' => 'hours']),
        ]);

        $this->assertSame(1, Schedules::tick());
        $this->assertSame(0, Schedules::tick());

        // Tomorrow at 9, the first is still waiting: this time is skipped
        $this->work();
        $this->travelTo(now()->addDay()->setTime(9, 0, 5));
        $this->assertSame(0, Schedules::tick());

        $this->travelTo(now()->setTime(9, 1));
        $this->assertSame(0, Schedules::tick());
        $this->assertSame(1, $workflow->runs()->count());
    }

    // --- templates ---

    public function test_a_template_is_made_with_ones_own_connections_only()
    {
        $user = User::factory()->create();
        $bot = $this->bot($user);
        $ai = Connection::factory()->for($user)->create();
        $theirs = Connection::factory()->telegram()->create();

        $this->actingAs($user)
            ->post(route('workflows.store'), ['template' => 'telegram-ai', 'connections' => ['telegram' => $theirs->ref_id, 'ai' => $ai->ref_id]])
            ->assertSessionHasErrors('connections.telegram');

        $this->actingAs($user)
            ->post(route('workflows.store'), ['template' => 'telegram-ai', 'connections' => ['telegram' => $bot->ref_id, 'ai' => $ai->ref_id]])
            ->assertRedirect();

        $workflow = $user->workflows()->sole();
        $this->assertSame('AI answers on Telegram', $workflow->title);
        $this->assertSame(['trigger.telegram', 'ai.chat', 'telegram.send'], array_column($workflow->definition['nodes'], 'type'));
        $this->assertSame($bot->ref_id, $workflow->definition['nodes'][2]['config']['connection']);
        $this->assertSame([], References::allProblems($workflow));
    }

    // --- linked chats ---

    public function test_a_telegram_chat_and_its_room_talk_both_ways_through_one_workflow()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);
        $user = User::factory()->create();
        $bot = $this->bot($user);

        $this->actingAs($user)->post(route('workflows.store'), ['template' => 'telegram-room', 'connections' => ['telegram' => $bot->ref_id]]);
        $workflow = $user->workflows()->sole();
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        // In from Telegram: a chat of its own, linked back
        $this->postJson("/hooks/telegram/{$bot->ref_id}", [
            'update_id' => 700,
            'message' => ['message_id' => 1, 'text' => 'Is it open?', 'chat' => ['id' => 77, 'type' => 'private'], 'from' => ['id' => 77, 'first_name' => 'Ada']],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();
        $this->work();

        $room = ChatRoom::where('external_key', 'telegram:77')->sole();
        $this->assertSame(['service' => 'telegram', 'connection' => $bot->ref_id, 'chat' => '77'], $room->link);
        $this->assertSame(['service' => 'Telegram', 'through' => $bot->name], $room->linkShown());
        $this->assertSame(['Is it open?'], $room->messages()->pluck('content')->all());
        // What came in is not sent back out
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage'));

        // Out from the room: back to that Telegram chat, through the chat's own bot
        $room->messages()->create(['role' => ChatMessage::USER, 'user_id' => $user->id, 'content' => 'Yes, until six']);
        $this->work();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '77'
            && $request['text'] === 'Yes, until six');
        $this->assertSame(2, $workflow->runs()->where('status', 'succeeded')->count());
    }

    public function test_every_linked_chat_is_heard_only_by_its_owners_workflows()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $bot = $this->bot($owner);
        $room = ChatRoom::factory()->for($owner)->create();
        $room->forceFill(['link' => ['service' => 'telegram', 'connection' => $bot->ref_id, 'chat' => '77']])->save();

        $theirs = $this->listening($stranger, $this->node('written', 'trigger.chat', ['room' => '*linked:telegram']), [
            $this->node('log', 'output.log', ['message' => 'overheard {{ trigger.text }}']),
        ]);
        $mine = $this->listening($owner, $this->node('written', 'trigger.chat', ['room' => '*linked:telegram']), [
            $this->node('log', 'output.log', ['message' => 'heard {{ trigger.text }} for {{ trigger.room.link.chat }}']),
        ]);

        $room->messages()->create(['role' => ChatMessage::USER, 'user_id' => $owner->id, 'content' => 'hello']);
        $this->work();

        $this->assertSame(0, $theirs->runs()->count());
        $this->assertSame(['heard hello for 77'], $mine->runs()->sole()->logs()->pluck('message')->all());
    }

    public function test_a_line_chat_gets_a_room_named_for_who_wrote_or_for_its_group()
    {
        Http::fake([
            'api.line.me/v2/bot/profile/U1' => Http::response(['displayName' => 'Ada', 'pictureUrl' => 'https://profile.line-scdn.net/ada']),
            'api.line.me/v2/bot/group/C1/member/U2' => Http::response(['displayName' => 'Bo']),
            'api.line.me/v2/bot/group/C1/summary' => Http::response(['groupId' => 'C1', 'groupName' => 'Shop team']),
            'api.line.me/*' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $channel = Connection::factory()->for($user)->create(['name' => 'Shop', 'service' => 'line', 'settings' => [], 'secrets' => ['channel_access_token' => 'tok', 'channel_secret' => 'line-secret']]);

        $this->actingAs($user)->post(route('workflows.store'), ['template' => 'line-room', 'connections' => ['line' => $channel->ref_id]]);
        $workflow = $user->workflows()->sole();
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        $send = function (string $id, array $source, string $text) use ($channel) {
            $body = json_encode(['events' => [[
                'type' => 'message', 'webhookEventId' => $id, 'replyToken' => "rt-{$id}",
                'source' => $source, 'message' => ['type' => 'text', 'id' => "m-{$id}", 'text' => $text],
            ]]]);
            $this->call('POST', "/hooks/line/{$channel->ref_id}", [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_LINE_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 'line-secret', true)),
            ], $body)->assertOk();
        };

        $send('e1', ['type' => 'user', 'userId' => 'U1'], 'hello');
        $send('e2', ['type' => 'user', 'userId' => 'U1'], 'again');
        $send('e3', ['type' => 'group', 'groupId' => 'C1', 'userId' => 'U2'], 'hi all');
        $this->work();

        $one = ChatRoom::where('external_key', 'line:U1')->sole();
        $this->assertSame('Ada on LINE', $one->title);
        $this->assertSame(['Ada (LINE)', 'Ada (LINE)'], $one->messages()->get()->map(fn (ChatMessage $m) => $m->meta['name'])->all());
        $this->assertSame('Shop team on LINE', ChatRoom::where('external_key', 'line:C1')->sole()->title);
        // A person is asked about once, not for every message
        $this->assertSame(
            ['/profile/U1', '/group/C1/member/U2', '/group/C1/summary'],
            Http::recorded()->map(fn (array $sent) => str_replace('https://api.line.me/v2/bot', '', $sent[0]->url()))
                ->filter(fn (string $path) => ! str_contains($path, 'webhook'))->values()->all(),
        );
    }

    public function test_when_line_wont_say_who_wrote_its_step_says_why_and_can_be_carried_past()
    {
        Http::fake(['api.line.me/v2/bot/profile/*' => Http::response(['message' => 'Not found'], 404)]);
        $user = User::factory()->create();
        $channel = Connection::factory()->for($user)->create(['name' => 'Shop', 'service' => 'line', 'settings' => [], 'secrets' => ['channel_access_token' => 'tok', 'channel_secret' => 'line-secret']]);
        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('heard', 'trigger.line', ['connection' => $channel->ref_id]),
            [...$this->node('who', 'line.profile', ['connection' => $channel->ref_id, 'user' => '{{ trigger.sender.id }}', 'chat' => '{{ trigger.chat.id }}']), 'onError' => 'continue'],
            $this->node('log', 'output.log', ['message' => '{{ coalesce(node("who").name, "Someone") }} wrote']),
        ], [$this->edge('heard', 'who'), $this->edge('who', 'log')])->create();

        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::PUBLISHED, $user), null, 'line', 'heard', [
            'service' => 'line', 'chat' => ['id' => 'U9'], 'sender' => ['id' => 'U9'], 'text' => 'hi',
        ]);
        $this->work();

        $who = $this->step($run, 'who');
        $this->assertStringContainsString('added the channel as a friend', $who->error['message']);
        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(['Someone wrote'], $run->fresh()->logs()->pluck('message')->all());
    }
}
