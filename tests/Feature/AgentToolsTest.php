<?php

namespace Tests\Feature;

use App\Models\Agent\Agent;
use App\Models\Connection;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Agents\Toolbox;
use App\Support\Table\TableStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Agents with tools: Zyrenn's own MCP tools, run here as the person the
 * agent works for and in its project, and the tools of an MCP server its
 * owner connects -- offered to a model that asks for them, each use shown
 * beside the answer, and a tool that changes something only where that is
 * allowed.
 */
class AgentToolsTest extends TestCase
{
    use RefreshDatabase;

    /** What the model was asked, each time, and what it is to say next. */
    private array $asked = [];

    private array $says = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            'ollama:11434/v1/chat/completions' => function (Request $request) {
                $this->asked[] = $request->data();

                return Http::response(['choices' => [['message' => array_shift($this->says) ?? ['role' => 'assistant', 'content' => 'Done.']]]]);
            },
            // An MCP server: it says who it is, lists one tool, and answers it
            'mcp.example.test/*' => function (Request $request) {
                $id = $request['id'] ?? null;
                $result = match ($request['method'] ?? '') {
                    'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => (object) []], 'serverInfo' => ['name' => 'Weather', 'version' => '1']],
                    'tools/list' => ['tools' => [
                        ['name' => 'forecast', 'description' => 'The weather to come, for a city.', 'inputSchema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']], 'annotations' => ['readOnlyHint' => true]],
                    ]],
                    'tools/call' => ['content' => [['type' => 'text', 'text' => "Sunny in {$request['params']['arguments']['city']}"]], 'isError' => false],
                    default => null,
                };

                return match (true) {
                    $id === null => Http::response('', 202),
                    // A server of the usual kind does not know the newer handshake, and says so
                    $result === null => Http::response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']], 200, ['Content-Type' => 'application/json']),
                    default => Http::response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 's1']),
                };
            },
        ]);
    }

    /**
     * @return array{0: Project, 1: User, 2: Agent, 3: Table}
     */
    private function shop(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->withMember($owner)->create();
        Connection::factory()->forProject($project, $owner)->create([
            'name' => 'Local', 'service' => 'ollama', 'settings' => ['base_url' => 'http://ollama:11434', 'default_model' => 'qwen2.5:7b'],
        ]);
        $table = Table::factory()->create(['user_id' => null, 'project_id' => $project->id, 'title' => 'Prices']);
        TableStorage::newColumn($table, ['label' => 'Item', 'type' => 'varchar']);
        TableStorage::newColumn($table, ['label' => 'Price', 'type' => 'varchar']);
        $table = $table->fresh('columns');
        TableStorage::insertRow($table, [$table->columns->firstWhere('label', 'Item')->name => 'Mug', $table->columns->firstWhere('label', 'Price')->name => '250 baht']);

        $this->actingAs($owner)->post(route('projects.agents.store', $project), ['preset' => 'helper'])->assertRedirect();

        return [$project, $owner, Agent::query()->of($project)->sole(), $table];
    }

    /**
     * The model asking to use a tool.
     */
    private function wantsTool(string $name, array $arguments): array
    {
        return ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]]]];
    }

    public function test_an_agent_given_zyrenns_tools_looks_things_up_in_its_project()
    {
        [$project, $owner, $agent, $table] = $this->shop();

        // What may be given: Zyrenn's own, each saying whether it only reads
        $servers = $this->actingAs($owner)->getJson(route('agents.tools', $agent))->assertOk()->json('servers');
        $own = collect($servers[0]['tools'])->keyBy('name');
        $this->assertSame(['zyrenn', true, false], [$servers[0]['server'], $own['get-table']['reads'], $own['create-ticket']['reads']]);

        $this->actingAs($owner)->patch(route('agents.update', $agent), ['tools' => [
            ['server' => 'zyrenn', 'name' => 'get-table'],
            ['server' => 'zyrenn', 'name' => 'create-ticket'],
            ['server' => 'zyrenn', 'name' => 'no-such-tool'],
        ], 'note' => 'can read the price list'])->assertRedirect();
        $agent->refresh();
        $this->assertSame([2, [['server' => 'zyrenn', 'name' => 'create-ticket'], ['server' => 'zyrenn', 'name' => 'get-table']]], [$agent->version, $agent->tools]);

        $this->says = [$this->wantsTool('get-table', ['ref_id' => $table->ref_id]), ['role' => 'assistant', 'content' => 'A mug is 250 baht.']];
        $answer = $this->actingAs($owner)->postJson(route('agents.try', $agent), ['message' => 'How much is a mug?'])->assertOk()->json();

        $this->assertSame('A mug is 250 baht.', $answer['text'], json_encode($answer['actions']));
        $this->assertSame(['get-table', 'Zyrenn', true], [$answer['actions'][0]['tool'], $answer['actions'][0]['server'], $answer['actions'][0]['ok']], $answer['actions'][0]['said']);
        // What the tool said went back to the model whole, as the tool's answer
        $this->assertSame('tool', end($this->asked[1]['messages'])['role']);
        $this->assertStringContainsString('250 baht', end($this->asked[1]['messages'])['content']);
        // Offered only what reads -- trying it changes nothing -- and never asked for the project: it is filled in
        $offered = array_column(array_column($this->asked[0]['tools'], 'function'), 'name');
        $this->assertSame(['get-table'], $offered);
        $this->assertArrayNotHasKey('project', $this->asked[0]['tools'][0]['function']['parameters']['properties']);
        $this->assertSame(0, $project->tickets()->count());
    }

    public function test_an_agent_uses_the_tools_of_an_mcp_server_its_owner_connected()
    {
        [$project, $owner, $agent] = $this->shop();
        $weather = Connection::factory()->forProject($project, $owner)->create(['name' => 'Weather', 'service' => 'mcp', 'settings' => ['url' => 'https://mcp.example.test/mcp'], 'secrets' => ['token' => 'mcp-token']]);

        // It is tried as any connection is: reached, and its tools counted
        $this->actingAs($owner)->postJson(route('projects.connections.check', $project), ['service' => 'mcp', 'settings' => ['url' => 'https://mcp.example.test/mcp'], 'secrets' => ['token' => 'mcp-token']])
            ->assertJson(['ok' => true, 'message' => 'Works: it offers 1 tool.']);

        $catalog = collect(Toolbox::catalog($project))->keyBy('server');
        $this->assertSame(['Weather', 'forecast', true], [$catalog[$weather->ref_id]['name'], $catalog[$weather->ref_id]['tools'][0]['name'], $catalog[$weather->ref_id]['tools'][0]['reads']]);

        $agent->change(['tools' => [['server' => $weather->ref_id, 'name' => 'forecast']]], $owner);
        $named = 'x'.substr($weather->ref_id, 0, 6).'__forecast';
        $this->says = [$this->wantsTool($named, ['city' => 'Chiang Mai']), ['role' => 'assistant', 'content' => 'Sunny.']];

        $answer = $this->actingAs($owner)->postJson(route('agents.try', $agent), ['message' => 'Weather in Chiang Mai?'])->assertOk()->json();
        $this->assertSame(['Sunny.', 'forecast', 'Weather', 'Sunny in Chiang Mai'], [$answer['text'], $answer['actions'][0]['tool'], $answer['actions'][0]['server'], $answer['actions'][0]['said']]);
        // With its token
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'mcp.example.test') && $request->hasHeader('Authorization', 'Bearer mcp-token'));
    }

    public function test_a_tool_that_failed_is_said_to_the_model_and_shown_as_failed()
    {
        [$project, $owner, $agent] = $this->shop();
        $agent->change(['tools' => [['server' => 'zyrenn', 'name' => 'get-table']]], $owner);
        $this->says = [$this->wantsTool('get-table', ['ref_id' => 'nosuchtable']), ['role' => 'assistant', 'content' => 'I could not find it.']];

        $answer = $this->actingAs($owner)->postJson(route('agents.try', $agent), ['message' => 'Price of a mug?'])->json();

        $this->assertFalse($answer['actions'][0]['ok']);
        $this->assertStringStartsWith('It failed:', $answer['actions'][0]['said']);
        $this->assertSame('I could not find it.', $answer['text']);
    }
}
