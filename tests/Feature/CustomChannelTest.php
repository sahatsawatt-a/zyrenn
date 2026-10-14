<?php

namespace Tests\Feature;

use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Connections\Services;
use App\Support\Workflow\Engine;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A "Custom channel": any messaging platform with an API of its own, its
 * messages sent to a hook in the app's own shape and answers posted back,
 * both ways signed with the secret the two share -- and its chats in a
 * project's inbox like any other service's.
 */
class CustomChannelTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-long-shared-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
    }

    private function channel(Project $project, User $by, string $sendTo = 'https://api.shop.example/zyrenn'): Connection
    {
        return Connection::factory()->forProject($project, $by)->create([
            'name' => 'Shop chat',
            'service' => Services::CHANNEL,
            'settings' => ['platform' => 'Shop chat', 'send_url' => $sendTo],
            'secrets' => ['secret' => self::SECRET],
        ]);
    }

    /**
     * What the platform sends, signed as it would sign it.
     */
    private function sent(Connection $channel, array $body, ?string $secret = self::SECRET): TestResponse
    {
        $json = (string) json_encode($body);

        return $this->call('POST', "/hooks/channel/{$channel->ref_id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZYRENN_SIGNATURE' => 'sha256='.hash_hmac('sha256', $json, (string) $secret),
        ], $json);
    }

    private function node(string $key, string $type, array $config = []): array
    {
        return ['id' => $key, 'key' => $key, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    /**
     * A project with an owner and an editor.
     *
     * @return array{0: Project, 1: User, 2: User}
     */
    private function team(): array
    {
        [$owner, $editor] = User::factory()->count(2)->create()->all();
        $project = Project::factory()->withMember($owner)->create();
        $project->members()->attach($editor, ['role' => Project::EDITOR]);

        return [$project, $owner, $editor];
    }

    private function published(Project $project, User $owner, array $nodes, array $edges = []): Workflow
    {
        $workflow = Workflow::factory()->forProject($project, $owner)->steps($nodes, $edges)->create();
        $this->actingAs($owner)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($owner)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();
        auth()->logout();

        return $workflow;
    }

    public function test_a_platform_of_ones_own_starts_a_run_only_when_signed_with_the_shared_secret()
    {
        [$project, $owner] = $this->team();
        $channel = $this->channel($project, $owner);
        $workflow = $this->published($project, $owner, [$this->node('heard', 'trigger.channel', ['connection' => $channel->ref_id])]);

        // Where it sends, and in what shape, are on the connection's page
        $this->actingAs($owner)->get(route('projects.connections.index', $project))->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.hook.url', "https://hooks.example.test/hooks/channel/{$channel->ref_id}")
            ->where('connections.0.hook.hint', fn (string $hint) => str_contains($hint, '"chat"')));
        auth()->logout();

        $message = [
            'id' => 'm-1',
            'chat' => ['id' => 'c-42', 'title' => 'Order 1001'],
            'sender' => ['id' => 'u-7', 'name' => 'Ada', 'picture' => 'https://cdn.shop.example/ada.png'],
            'text' => 'Where is my order?',
        ];

        $this->sent($channel, $message, 'someone-elses')->assertForbidden();
        $this->call('POST', "/hooks/channel/{$channel->ref_id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($message))->assertForbidden();
        $this->sent($channel, ['chat' => 'c-42'])->assertStatus(422);
        $this->assertSame(0, $workflow->runs()->count());

        $this->sent($channel, $message)->assertOk()->assertJson(['taken' => 1]);
        // The same message again, by its own id, starts nothing more; several come as one
        $this->sent($channel, $message)->assertOk();
        $this->sent($channel, ['messages' => [
            ['id' => 'm-2', 'chat' => 'c-43', 'files' => ['https://cdn.shop.example/receipt.jpg', 'not an address']],
            ['id' => 'm-3', 'chat' => 'c-44', 'location' => ['lat' => 13.75, 'lng' => 100.5, 'title' => 'Shop']],
        ]])->assertOk()->assertJson(['taken' => 2]);
        $this->work();

        $runs = $workflow->runs()->orderBy('id')->get();
        $this->assertCount(3, $runs);
        $heard = $runs[0]->trigger_data;
        $this->assertSame(
            ['channel', 'Shop chat', 'text', 'c-42', 'Order 1001', 'u-7', 'Ada', 'https://cdn.shop.example/ada.png', 'Where is my order?', 'm-1'],
            [$heard['service'], $heard['platform'], $heard['kind'], $heard['chat']['id'], $heard['chat']['title'], $heard['sender']['id'], $heard['sender']['name'], $heard['sender']['picture'], $heard['text'], $heard['reply']['to']],
        );
        $this->assertSame(['file', ['https://cdn.shop.example/receipt.jpg']], [$runs[1]->trigger_data['kind'], $runs[1]->trigger_data['files']]);
        $this->assertSame(['location', 'Shop'], [$runs[2]->trigger_data['kind'], $runs[2]->trigger_data['location']['title']]);
    }

    public function test_a_custom_channels_chats_are_in_the_inbox_and_answers_go_back_signed()
    {
        $posted = [];
        Http::fake(function (Request $request) use (&$posted) {
            $posted[] = $request;

            return Http::response(['id' => 'out-9']);
        });
        [$project, $owner, $editor] = $this->team();
        $channel = $this->channel($project, $owner);

        $this->actingAs($owner)
            ->post(route('projects.workflows.store', $project), ['template' => 'channel-room', 'connections' => ['channel' => $channel->ref_id]])
            ->assertRedirect();
        $workflow = $project->workflows()->sole();
        $this->assertSame([], References::allProblems($workflow));
        $this->actingAs($owner)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($owner)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();
        auth()->logout();

        $this->sent($channel, ['id' => 'm-1', 'chat' => ['id' => 'c-42'], 'sender' => ['id' => 'u-7', 'name' => 'Ada', 'picture' => 'https://cdn.shop.example/ada.png'], 'text' => 'Where is my order?'])->assertOk();
        $this->work();

        // In: a customer's chat of its own, named for the platform, with their face
        $room = ChatRoom::query()->customers($project)->sole();
        $this->assertSame(['Ada on Shop chat', ChatRoom::OPEN, 'Shop chat', 'https://cdn.shop.example/ada.png'], [$room->title, $room->status, $room->link['platform'], $room->picture]);
        $this->assertSame(['Where is my order?', 'Ada (Shop chat)'], [$room->messages()->sole()->content, $room->messages()->sole()->meta['name']]);
        $this->actingAs($editor)->get(route('chats.show', $room))->assertInertia(fn (Assert $page) => $page->where('room.link.service', 'Shop chat'));
        $this->actingAs($editor)->get(route('projects.inbox', $project))->assertInertia(fn (Assert $page) => $page->where('rooms.0.linked', 'Shop chat')->where('channels', ['channel']));

        // Out: words with a file from the project's Drive, posted to "Send to", signed
        $temp = (string) tempnam(sys_get_temp_dir(), 'test-file-');
        file_put_contents($temp, 'receipt');
        $file = DriveFile::keep($temp, 'receipt.txt', $project);
        unlink($temp);

        $this->actingAs($editor)->post(route('chats.messages.store', $room), ['content' => 'Here it is', 'file' => $file->ref_id])->assertOk()->streamedContent();
        $this->work();

        $this->assertCount(1, $posted);
        $out = $posted[0];
        $this->assertSame('https://api.shop.example/zyrenn', $out->url());
        $this->assertSame('sha256='.hash_hmac('sha256', $out->body(), self::SECRET), $out->header('X-Zyrenn-Signature')[0]);
        $this->assertNotEmpty($out->header('Idempotency-Key')[0]);
        $this->assertSame(['c-42', 'Here it is', 'receipt.txt', null], [$out['chat'], $out['text'], $out['file']['name'], $out['location']]);
        $this->assertStringStartsWith('https://hooks.example.test/hooks/files/', $out['file']['url']);
        $this->assertSame(ChatRoom::WAITING, $room->fresh()->status);
    }

    public function test_a_channel_is_saved_with_its_platform_and_secret_and_nowhere_to_send_to_yet()
    {
        [$project, $owner] = $this->team();

        $this->actingAs($owner)->post(route('projects.connections.store', $project), ['name' => 'Shop', 'service' => 'channel', 'settings' => ['send_url' => '']])
            ->assertSessionHasErrors(['settings.platform', 'secrets.secret']);
        $this->actingAs($owner)->post(route('projects.connections.store', $project), [
            'name' => 'Shop', 'service' => 'channel', 'settings' => ['platform' => 'Shop chat', 'send_url' => ''], 'secrets' => ['secret' => self::SECRET],
        ])->assertRedirect();

        $channel = $project->connections()->sole();
        $this->assertSame(['Shop chat', null, self::SECRET], [$channel->setting('platform'), $channel->setting('send_url'), $channel->secret('secret')]);
    }

    public function test_an_answer_goes_only_to_a_public_address_and_somewhere_named()
    {
        [$project, $owner] = $this->team();
        $run = function (Connection $channel) use ($project, $owner) {
            $workflow = Workflow::factory()->forProject($project, $owner)->steps([
                $this->node('start', 'trigger.manual', ['payload' => '{"n": 1}']),
                $this->node('send', 'channel.send', ['connection' => $channel->ref_id, 'to' => 'c-1', 'text' => 'Hi', 'unsure' => 'never']),
            ], [['id' => 'start.out.send', 'source' => 'start', 'sourceHandle' => 'out', 'target' => 'send']])->create();
            $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $owner), $owner);
            $this->work();

            return $run->fresh()->steps()->where('node_key', 'send')->sole();
        };

        $inside = $run($this->channel($project, $owner, 'http://10.0.0.5/messages'));
        $this->assertSame(RunStatus::Failed, $inside->run->status);

        $nowhere = $run($this->channel($project, $owner, ''));
        $this->assertStringContainsString('nowhere to send to', json_encode($nowhere->error));
        Http::assertNothingSent();
    }
}
