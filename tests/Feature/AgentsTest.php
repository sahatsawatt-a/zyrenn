<?php

namespace Tests\Feature;

use App\Models\Agent\Agent;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Knowledge\KnowledgeBase;
use App\Models\Note\Note;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Knowledge\Knowledge;
use App\Support\Markdown\TiptapMarkdown;
use App\Support\Workflow\Engine;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Agents: a model with its instructions and knowledge, every change kept as
 * a version that can be gone back to or named by a step -- and asked by a
 * workflow, tried on their page, or asked to suggest a reply in a chat.
 */
class AgentsTest extends TestCase
{
    use RefreshDatabase;

    /** What the model was asked, each time. */
    private array $asked = [];

    /** What the model says, when a test wants it to say something else. */
    private ?string $says = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Http::preventStrayRequests();
        // A model that says which it is, and what it was told to be
        Http::fake(['ollama:11434/v1/chat/completions' => function (Request $request) {
            $this->asked[] = $request->data();
            $said = $this->says ?? "{$request['model']} by: {$request['messages'][0]['content']}";

            return Http::response('data: '.json_encode(['choices' => [['delta' => ['content' => $said]]]])."\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream']);
        }]);
    }

    /**
     * @return array{0: Project, 1: User, 2: User, 3: Connection}
     */
    private function team(): array
    {
        [$owner, $viewer] = User::factory()->count(2)->create()->all();
        $project = Project::factory()->withMember($owner)->create();
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);
        $ai = Connection::factory()->forProject($project, $owner)->create([
            'name' => 'Local', 'service' => 'ollama', 'settings' => ['base_url' => 'http://ollama:11434', 'default_model' => 'qwen2.5:7b'],
        ]);

        return [$project, $owner, $viewer, $ai];
    }

    private function agent(Project $project, User $owner): Agent
    {
        $this->actingAs($owner)->post(route('projects.agents.store', $project), ['preset' => 'helper'])->assertRedirect();

        return Agent::query()->of($project)->sole();
    }

    public function test_every_change_is_a_version_and_any_can_be_gone_back_to()
    {
        [$project, $owner, $viewer] = $this->team();
        $agent = $this->agent($project, $owner);
        $this->assertSame(['Helper', 'qwen2.5:7b', 1], [$agent->name, $agent->model, $agent->version]);

        $this->actingAs($owner)->patch(route('agents.update', $agent), ['model' => 'qwen2.5:14b', 'note' => 'a larger model'])->assertRedirect();
        $this->actingAs($owner)->patch(route('agents.update', $agent), ['instructions' => 'Answer in one line.'])->assertRedirect();
        // A new name, or the same model again, is no new version
        $this->actingAs($owner)->patch(route('agents.update', $agent), ['name' => 'Shop helper', 'model' => 'qwen2.5:14b'])->assertRedirect();
        $agent->refresh();
        $this->assertSame(['Shop helper', 3], [$agent->name, $agent->version]);
        $this->assertSame([3, 2, 1], $agent->versions()->pluck('number')->all());
        $this->assertSame('a larger model', $agent->at(2)->note);

        // Back to the first: its model and instructions, kept as version 4
        $this->actingAs($owner)->post(route('agents.restore', $agent), ['version' => 1])->assertRedirect();
        $agent->refresh();
        $this->assertSame([4, 'qwen2.5:7b', $agent->at(1)->instructions, 'Back to version 1'], [$agent->version, $agent->model, $agent->instructions, $agent->at(4)->note]);
        $this->actingAs($owner)->post(route('agents.restore', $agent), ['version' => 9])->assertSessionHasErrors('version');

        $this->actingAs($viewer)->get(route('projects.agents.index', $project))->assertInertia(fn (Assert $page) => $page
            ->component('agents/Index')
            ->where('agents.0.version', 4)
            ->has('agents.0.versions', 4)
            ->where('agents.0.versions.0.note', 'Back to version 1')
            ->where('can.manage', false));
        $this->actingAs($viewer)->patch(route('agents.update', $agent), ['model' => 'x'])->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson(route('agents.try', $agent), ['message' => 'Hi'])->assertForbidden();
    }

    public function test_it_is_tried_at_its_latest_or_at_any_version()
    {
        [$project, $owner, $viewer] = $this->team();
        $agent = $this->agent($project, $owner);
        $agent->change(['model' => 'qwen2.5:14b', 'instructions' => 'Be terse.'], $owner);

        $this->actingAs($viewer)->postJson(route('agents.try', $agent), ['message' => 'Hi'])
            ->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('text', 'qwen2.5:14b by: Be terse.');
        $this->actingAs($viewer)->postJson(route('agents.try', $agent), ['message' => 'Hi', 'version' => 1])
            ->assertJsonPath('version', 1)
            ->assertJsonPath('model', 'qwen2.5:7b');
    }

    public function test_a_step_asks_the_latest_or_keeps_to_a_version_named()
    {
        [$project, $owner] = $this->team();
        $agent = $this->agent($project, $owner);
        $agent->change(['model' => 'qwen2.5:14b'], $owner);
        $room = ChatRoom::startGroup($project, $owner, 'Ada on LINE');
        $room->messages()->create(['role' => ChatMessage::WORKFLOW, 'content' => 'Is it open?', 'meta' => ['name' => 'Ada', 'outside' => true]]);
        $room->messages()->create(['role' => ChatMessage::USER, 'user_id' => $owner->id, 'content' => 'Not for her eyes', 'meta' => ['internal' => true]]);

        $run = function (array $config) use ($project, $owner) {
            $workflow = Workflow::factory()->forProject($project, $owner)->steps([
                ['id' => 'start', 'key' => 'start', 'type' => 'trigger.manual', 'position' => ['x' => 0, 'y' => 0], 'config' => ['payload' => '{"text": "When?"}']],
                ['id' => 'ask', 'key' => 'ask', 'type' => 'ai.agent', 'position' => ['x' => 0, 'y' => 0], 'config' => ['agent' => Agent::query()->of($project)->sole()->ref_id, 'message' => '{{ trigger.text }}', 'answer' => 'text', ...$config]],
            ], [['id' => 'e', 'source' => 'start', 'sourceHandle' => 'out', 'target' => 'ask']])->create();
            $done = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $owner), $owner);
            $this->work();

            return $done->fresh()->steps()->where('node_key', 'ask')->sole();
        };

        $latest = $run(['room' => $room->ref_id]);
        $this->assertSame([2, 'qwen2.5:14b'], [$latest->output['version'], $latest->output['model']]);
        // It read the chat, without what was for the team alone
        $told = end($this->asked)['messages'][1]['content'];
        $this->assertStringContainsString("Customer: Is it open?\n", $told."\n");
        $this->assertStringNotContainsString('Not for her eyes', $told);
        $this->assertStringEndsWith('Their latest message: When?', $told);

        $kept = $run(['version' => '1']);
        $this->assertSame([1, 'qwen2.5:7b'], [$kept->output['version'], $kept->output['model']]);
        $this->assertSame(RunStatus::Failed, $run(['version' => '7'])->run->status);
    }

    public function test_an_agent_suggests_a_reply_for_a_person_to_send()
    {
        $this->says = '{"kind": "question", "answered": true, "reply": "We open at 9.", "summary": "hours"}';
        [$project, $owner, $viewer] = $this->team();
        // What it answers from: a note, found by its words
        $note = new Note;
        $note->project_id = $project->id;
        $note->created_by = $owner->id;
        $note->title = 'Opening hours';
        $note->content = TiptapMarkdown::toDoc('We open at 9, Monday to Saturday.');
        $note->save();
        $base = new KnowledgeBase(['title' => 'Shop', 'sources' => [['kind' => 'notes', 'ref' => '']]]);
        $base->ownedBy($project, $owner)->save();
        app(Knowledge::class)->read($base);
        $this->actingAs($owner)->post(route('projects.agents.store', $project), ['preset' => 'desk'])->assertRedirect();
        $agent = Agent::query()->of($project)->sole();
        $room = ChatRoom::startGroup($project, $owner, 'Ada on LINE');
        $room->forceFill(['link' => ['service' => 'line', 'connection' => 'c', 'chat' => 'U1']])->save();
        $room->becomeCustomers();
        $room->messages()->create(['role' => ChatMessage::WORKFLOW, 'content' => 'When do you open?', 'meta' => ['name' => 'Ada', 'outside' => true]]);

        $this->actingAs($owner)->get(route('chats.show', $room))->assertInertia(fn (Assert $page) => $page->where('room.desk.agents.0.name', 'Customer desk'));
        $this->actingAs($viewer)->postJson(route('chats.suggest', $room), ['agent' => $agent->ref_id])->assertForbidden();

        // The reply out of the desk's JSON, and nothing said in the chat
        // With what it answered from, to check the reply by before it is sent
        $this->actingAs($owner)->postJson(route('chats.suggest', $room), ['agent' => $agent->ref_id])
            ->assertOk()
            ->assertJson(['text' => 'We open at 9.', 'agent' => 'Customer desk', 'version' => 1, 'knows' => true])
            ->assertJsonPath('sources.0.title', 'Opening hours')
            ->assertJsonPath('sources.0.url', route('notes.show', $note))
            ->assertJsonPath('sources.0.text', 'We open at 9, Monday to Saturday.');

        // An agent with nothing to know from says so
        $agent->change(['knowledge_base_id' => null], $owner);
        $this->actingAs($owner)->postJson(route('chats.suggest', $room), ['agent' => $agent->ref_id])
            ->assertJson(['knows' => false, 'sources' => []]);
        $this->assertSame(1, $room->messages()->count());
    }
}
