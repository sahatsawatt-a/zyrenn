<?php

namespace Tests\Feature;

use App\Events\WorkflowChanged;
use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Workflows\CreateWorkflow;
use App\Mcp\Tools\Workflows\DeleteWorkflow;
use App\Mcp\Tools\Workflows\GetWorkflow;
use App\Mcp\Tools\Workflows\GetWorkflowRun;
use App\Mcp\Tools\Workflows\ListWorkflowRuns;
use App\Mcp\Tools\Workflows\ListWorkflows;
use App\Mcp\Tools\Workflows\ListWorkflowSteps;
use App\Mcp\Tools\Workflows\PublishWorkflow;
use App\Mcp\Tools\Workflows\RunWorkflow;
use App\Mcp\Tools\Workflows\SwitchWorkflow;
use App\Mcp\Tools\Workflows\UpdateWorkflow;
use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowVersion;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * The workflow tools: an assistant finds a workflow, reads its steps, runs
 * it with data of its own and reads how each step went, and switches it on.
 */
class McpWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // As the app runs them: steps through the database queue, worked after
        config(['queue.default' => 'database']);
    }

    private function node(string $key, string $type, array $config = []): array
    {
        return ['id' => $key, 'key' => $key, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    private function greeter(User|Project $owner): Workflow
    {
        $factory = Workflow::factory()->steps([
            $this->node('start', 'trigger.manual', ['payload' => '{"name": "test"}']),
            $this->node('greet', 'data.set', ['fields' => [['name' => 'said', 'value' => 'Hello {{ trigger.name }}']]]),
        ], [['id' => 'e', 'source' => 'start', 'sourceHandle' => 'out', 'target' => 'greet']]);

        return ($owner instanceof Project ? $factory->forProject($owner) : $factory->for($owner))->create(['title' => 'Greeter']);
    }

    public function test_a_workflow_is_found_read_run_with_data_and_its_run_read_back()
    {
        $user = User::factory()->create();
        $workflow = $this->greeter($user);

        UserServer::actingAs($user)->tool(ListWorkflows::class, ['search' => 'greet'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('workflows.0.ref_id', $workflow->ref_id)
                ->where('workflows.0.published', null)
                ->etc());

        UserServer::actingAs($user)->tool(GetWorkflow::class, ['ref_id' => $workflow->ref_id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('steps.1.key', 'greet')
                ->where('joins.0', ['from' => 'start', 'way' => 'out', 'to' => 'greet'])
                ->where('problems', [])
                ->etc());

        UserServer::actingAs($user)->tool(RunWorkflow::class, ['ref_id' => $workflow->ref_id, 'data' => ['name' => 'Ada']])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('status', 'queued')
                ->where('version', 'draft')
                ->etc());
        $this->work();

        $run = $workflow->runs()->sole();
        $this->assertSame(['run' => $run->ref_id, 'by' => 'mcp'], AuditEvent::where('action', 'workflow.run')->sole()->details);

        UserServer::actingAs($user)->tool(ListWorkflowRuns::class, ['ref_id' => $workflow->ref_id, 'status' => 'succeeded'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('runs.0.ref_id', $run->ref_id)->etc());

        UserServer::actingAs($user)->tool(GetWorkflowRun::class, ['run' => $run->ref_id, 'step' => 'greet'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('status', 'succeeded')
                ->where('steps.1.output', ['said' => 'Hello Ada'])
                ->etc());

        // Someone else's run is not there to read
        UserServer::actingAs(User::factory()->create())->tool(GetWorkflowRun::class, ['run' => $run->ref_id])
            ->assertHasErrors(["Run {$run->ref_id} was not found."]);
    }

    public function test_only_what_is_published_is_switched_on()
    {
        $user = User::factory()->create();
        $workflow = $this->greeter($user);

        UserServer::actingAs($user)->tool(SwitchWorkflow::class, ['ref_id' => $workflow->ref_id, 'on' => true])
            ->assertHasErrors(['Publish it first: what is switched on is what is published.']);
        UserServer::actingAs($user)->tool(RunWorkflow::class, ['ref_id' => $workflow->ref_id, 'version' => 'published'])
            ->assertHasErrors();

        $workflow->freeze(WorkflowVersion::PUBLISHED, $user);

        UserServer::actingAs($user)->tool(SwitchWorkflow::class, ['ref_id' => $workflow->ref_id, 'on' => true])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('enabled', true)->where('published', 1)->etc());
        $this->assertTrue($workflow->fresh()->enabled);
    }

    public function test_a_project_viewer_reads_but_cannot_run_and_the_admin_server_acts_for_anyone()
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $project = Project::factory()->create(['created_by' => $owner->id]);
        $project->members()->attach($owner->id, ['role' => Project::OWNER]);
        $project->members()->attach($viewer->id, ['role' => Project::VIEWER]);
        $workflow = $this->greeter($project);

        UserServer::actingAs($viewer)->tool(GetWorkflow::class, ['ref_id' => $workflow->ref_id, 'project' => $project->ref_id])->assertOk();
        UserServer::actingAs($viewer)->tool(RunWorkflow::class, ['ref_id' => $workflow->ref_id, 'project' => $project->ref_id])
            ->assertHasErrors();
        $this->assertSame(0, $workflow->runs()->count());

        GlobalServer::tool(RunWorkflow::class, ['user_id' => $owner->id, 'ref_id' => $workflow->ref_id, 'project' => $project->ref_id])
            ->assertOk();
        $this->work();
        $this->assertSame('succeeded', $workflow->runs()->sole()->status->value);
    }

    // --- building one ---

    public function test_the_kinds_of_step_are_listed_with_what_their_settings_may_point_at_never_a_secret()
    {
        $user = User::factory()->create();
        $bot = Connection::factory()->telegram()->for($user)->create(['name' => 'Shop bot', 'secrets' => ['bot_token' => '123:secret']]);

        UserServer::actingAs($user)->tool(ListWorkflowSteps::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('kinds', fn ($kinds) => collect($kinds)->pluck('type')->contains('logic.switch') && collect($kinds)->firstWhere('type', 'trigger.telegram')['starts_a_run'] === true)
                ->where('choices.connections.0.ref_id', $bot->ref_id)
                ->etc());

        UserServer::actingAs($user)->tool(ListWorkflowSteps::class, ['type' => 'telegram.send'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('settings.0.name', 'connection')
                ->where('settings.0.services', ['telegram'])
                ->etc())
            ->assertDontSee('123:secret');
    }

    public function test_a_workflow_is_built_at_once_changed_by_key_and_published()
    {
        $user = User::factory()->create();
        $bot = Connection::factory()->telegram()->for($user)->create();
        $ai = Connection::factory()->openrouter()->for($user)->create(['settings' => ['base_url' => 'https://openrouter.ai/api/v1', 'default_model' => 'some/model']]);

        UserServer::actingAs($user)->tool(CreateWorkflow::class, [
            'title' => 'Answer on Telegram',
            'folder' => 'Bots',
            'remove' => ['start'],
            'add' => [
                ['type' => 'trigger.telegram', 'key' => 'heard', 'settings' => ['connection' => $bot->ref_id]],
                ['type' => 'ai.chat', 'key' => 'ask', 'after' => 'heard', 'settings' => ['connection' => $ai->ref_id, 'prompt' => '{{ trigger.text }}']],
                ['type' => 'telegram.send', 'after' => 'ask', 'settings' => ['connection' => $bot->ref_id, 'chat' => '{{ trigger.chat.id }}', 'text' => '{{ node("ask").text }}']],
            ],
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('problems', [])
            ->where('joins', [['from' => 'heard', 'way' => 'out', 'to' => 'ask'], ['from' => 'ask', 'way' => 'out', 'to' => 'send_on_telegram']])
            ->where('folder', 'Bots')
            ->etc());

        $workflow = $user->workflows()->sole();
        $positions = collect($workflow->definition['nodes'])->pluck('position.x', 'key');
        $this->assertTrue($positions['heard'] < $positions['ask'] && $positions['ask'] < $positions['send_on_telegram']);
        // A webhook-less trigger needs no token; a new step's defaults are filled in
        $this->assertSame('text', collect($workflow->definition['nodes'])->firstWhere('key', 'ask')['config']['answer']);

        // Changed by key: a setting merged, a key renamed where formulas name it, a branch added
        Bus::fake([BroadcastEvent::class]);
        UserServer::actingAs($user)->tool(UpdateWorkflow::class, [
            'ref_id' => $workflow->ref_id,
            'revision' => $workflow->revision,
            'change' => [['key' => 'ask', 'new_key' => 'think', 'settings' => ['system' => 'Be brief.']]],
            'add' => [
                ['type' => 'logic.if', 'key' => 'long', 'after' => 'heard', 'settings' => ['condition' => 'len(trigger.text) > 500']],
                // After a key renamed in the same call
                ['type' => 'output.log', 'key' => 'noted', 'after' => 'think', 'settings' => ['message' => '{{ node("think").text }}']],
            ],
            'unjoin' => [['from' => 'heard', 'to' => 'think']],
            'join' => [['from' => 'long', 'way' => 'false', 'to' => 'think']],
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('problems', [])
            ->where('joins', fn ($joins) => collect($joins)->contains(['from' => 'long', 'way' => 'false', 'to' => 'think'])
                && ! collect($joins)->contains(['from' => 'heard', 'way' => 'out', 'to' => 'think']))
            ->etc());

        $nodes = collect($workflow->fresh()->definition['nodes'])->keyBy('key');
        $this->assertSame('{{ node("think").text }}', $nodes['send_on_telegram']['config']['text']);
        $this->assertSame('Be brief.', $nodes['think']['config']['system']);
        $this->assertSame('{{ trigger.text }}', $nodes['think']['config']['prompt']);
        // Whoever has it open in the editor hears of it
        Bus::assertDispatched(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof WorkflowChanged);

        // Made over an older copy: refused, nothing changed
        UserServer::actingAs($user)->tool(UpdateWorkflow::class, ['ref_id' => $workflow->ref_id, 'revision' => $workflow->revision, 'title' => 'Mine'])
            ->assertHasErrors();
        $this->assertSame('Answer on Telegram', $workflow->fresh()->title);

        UserServer::actingAs($user)->tool(PublishWorkflow::class, ['ref_id' => $workflow->ref_id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('published', 1)->where('unpublished_changes', false)->etc());
        $this->assertEquals(['by' => 'mcp', 'version' => 1], AuditEvent::where('action', 'workflow.published')->sole()->details);
    }

    public function test_what_cant_be_done_is_said_plainly_and_changes_nothing()
    {
        $user = User::factory()->create();
        $workflow = $this->greeter($user);
        $revision = $workflow->revision;
        $edit = fn (array $edits) => UserServer::actingAs($user)->tool(UpdateWorkflow::class, ['ref_id' => $workflow->ref_id, ...$edits]);

        $edit(['add' => [['type' => 'magic.wand']]])->assertHasErrors(['There is no kind of step "magic.wand". list-workflow-steps lists them.']);
        $edit(['add' => [['type' => 'output.log', 'key' => 'greet']]])->assertHasErrors(['There is a step "greet" already.']);
        $edit(['join' => [['from' => 'greet', 'way' => 'sideways', 'to' => 'start']]])->assertHasErrors();
        $edit(['add' => [['type' => 'logic.if', 'key' => 'check', 'after' => 'greet'], ['type' => 'output.log', 'after' => 'check']]])
            ->assertHasErrors(['"check" has more than one way out; say which: true, false.']);
        $edit(['remove' => ['nothing']])->assertHasErrors(['There is no step "nothing". get-workflow lists them.']);

        $this->assertSame($revision, $workflow->fresh()->revision);

        // Not ready: publishing says what stands in the way
        $edit(['add' => [['type' => 'chat.send', 'key' => 'say', 'after' => 'greet']]])->assertOk();
        UserServer::actingAs($user)->tool(PublishWorkflow::class, ['ref_id' => $workflow->ref_id])->assertHasErrors();
        $this->assertNull($workflow->fresh()->published_version_id);
    }

    public function test_a_template_is_made_with_its_connections_and_a_workflow_deleted()
    {
        $user = User::factory()->create();
        $bot = Connection::factory()->telegram()->for($user)->create();

        UserServer::actingAs($user)->tool(CreateWorkflow::class, ['title' => 'Bridge', 'template' => 'telegram-room'])
            ->assertHasErrors();
        UserServer::actingAs($user)->tool(CreateWorkflow::class, ['title' => 'Bridge', 'template' => 'telegram-room', 'connections' => ['telegram' => $bot->ref_id]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('problems', [])->where('steps.0.type', 'trigger.telegram')->etc());

        $workflow = $user->workflows()->sole();
        UserServer::actingAs($user)->tool(DeleteWorkflow::class, ['ref_id' => $workflow->ref_id])->assertOk();
        $this->assertNull($workflow->fresh());
        $this->assertSame(['workflow.created', 'workflow.deleted'], AuditEvent::orderBy('id')->pluck('action')->all());
    }

    public function test_a_project_viewer_cannot_build_or_change_its_workflows()
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $project = Project::factory()->create(['created_by' => $owner->id]);
        $project->members()->attach($owner->id, ['role' => Project::OWNER]);
        $project->members()->attach($viewer->id, ['role' => Project::VIEWER]);
        $workflow = $this->greeter($project);

        UserServer::actingAs($viewer)->tool(UpdateWorkflow::class, ['ref_id' => $workflow->ref_id, 'project' => $project->ref_id, 'title' => 'x'])->assertHasErrors();
        UserServer::actingAs($viewer)->tool(CreateWorkflow::class, ['title' => 'x', 'project' => $project->ref_id])->assertHasErrors();
        UserServer::actingAs($owner)->tool(CreateWorkflow::class, ['title' => 'Theirs', 'project' => $project->ref_id])->assertOk();

        $this->assertSame(['Greeter', 'Theirs'], $project->workflows()->orderBy('id')->pluck('title')->all());
    }
}
