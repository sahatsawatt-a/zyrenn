<?php

namespace Tests\Feature;

use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Engine;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Files in workflows: one sent to a Telegram bot or a LINE channel kept in
 * the Drive, found and read there, and sent out again -- to a chat, or put
 * to a model. Through the real queue; every outside service is faked.
 */
class WorkflowFilesTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();
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

    private function bot(User $user): Connection
    {
        return Connection::factory()->telegram()->for($user)->create([
            'settings' => ['receive' => 'webhook'],
            'secrets' => ['bot_token' => '123:abc', 'webhook_secret' => 'shh'],
        ]);
    }

    private function channel(User $user): Connection
    {
        return Connection::factory()->for($user)->create(['name' => 'Shop', 'service' => 'line', 'settings' => [], 'secrets' => ['channel_access_token' => 'tok', 'channel_secret' => 'line-secret']]);
    }

    private function lineSends(Connection $channel, array $event): void
    {
        $body = (string) json_encode(['events' => [$event]]);

        $this->call('POST', "/hooks/line/{$channel->ref_id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LINE_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 'line-secret', true)),
        ], $body)->assertOk();
    }

    private function picture(User|Project $owner, string $name = 'cat.png'): DriveFile
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'test-picture-');
        file_put_contents($temp, base64_decode(self::PNG));
        $file = DriveFile::keep($temp, $name, $owner);
        unlink($temp);

        return $file;
    }

    // --- in: what a bot or a channel is sent, fetched by its own service's step ---

    public function test_a_picture_sent_to_a_bot_is_kept_in_the_drive_and_can_be_sent_back()
    {
        Http::fake([
            'api.telegram.org/bot123:abc/getFile*' => Http::response(['ok' => true, 'result' => ['file_id' => 'big', 'file_path' => 'photos/file_7.png']]),
            'api.telegram.org/file/bot123:abc/photos/file_7.png' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'application/octet-stream']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]]),
        ]);
        $user = User::factory()->create();
        $bot = $this->bot($user);
        $workflow = $this->listening($user, $this->node('heard', 'trigger.telegram', ['connection' => $bot->ref_id]), [
            $this->node('save', 'telegram.file', ['connection' => $bot->ref_id, 'folder' => 'Inbox/{{ trigger.sender.name }}']),
            $this->node('back', 'telegram.send_file', ['connection' => $bot->ref_id, 'chat' => '{{ trigger.chat.id }}', 'text' => 'Kept as {{ node("save").name }}', 'file' => '{{ node("save").ref_id }}']),
        ]);

        $this->postJson("/hooks/telegram/{$bot->ref_id}", ['update_id' => 500, 'message' => [
            'message_id' => 1, 'caption' => 'look', 'chat' => ['id' => 77, 'type' => 'private'], 'from' => ['id' => 77, 'first_name' => 'Ada'],
            // The same picture in three sizes: the last is the one to keep
            'photo' => [['file_id' => 'small'], ['file_id' => 'medium'], ['file_id' => 'big']],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();
        $this->work();

        $run = $workflow->runs()->sole();
        $this->assertSame(RunStatus::Succeeded, $run->status, (string) json_encode($this->step($run, 'save')->error));
        $this->assertSame(['big'], $run->trigger_data['files']);

        $file = $user->driveFiles()->sole();
        $this->assertMatchesRegularExpression('/^telegram-\d{4}-\d\d-\d\d-\d{6}\.png$/', $file->name);
        $this->assertSame(['image', 'image/png', 'Ada'], [$file->kind, $file->mime, $file->folder->name]);
        $this->assertSame('Inbox', $file->folder->parent->name);
        $this->assertSame(base64_decode(self::PNG), Storage::disk(DriveFile::DISK)->get($file->path));

        $saved = $this->step($run, 'save')->output;
        $this->assertSame([$file->ref_id, "/drive/files/{$file->ref_id}", 'Inbox/Ada'], [$saved['ref_id'], $saved['url'], $saved['folder']]);
        $this->assertStringStartsWith('![', $saved['markdown']);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/getFile') && $request['file_id'] === 'big');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendPhoto')
            && $request->hasFile('photo', null, $file->name)
            && collect($request->data())->firstWhere('name', 'caption')['contents'] === "Kept as {$file->name}");
    }

    public function test_a_file_sent_to_a_line_channel_keeps_its_name_and_a_picture_is_answered_with()
    {
        Http::fake([
            'api-data.line.me/v2/bot/message/m7/content' => Http::response('%PDF-1.4 report', 200, ['Content-Type' => 'application/pdf']),
            'api-data.line.me/v2/bot/message/m8/content' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            'api.line.me/*' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $workflow = $this->listening($user, $this->node('heard', 'trigger.line', ['connection' => $channel->ref_id]), [
            $this->node('save', 'line.file', ['connection' => $channel->ref_id, 'folder' => 'LINE']),
            [...$this->node('back', 'line.reply', ['connection' => $channel->ref_id, 'file' => '{{ node("save").ref_id }}']), 'onError' => 'continue'],
        ]);

        $this->lineSends($channel, ['type' => 'message', 'webhookEventId' => 'ev7', 'replyToken' => 'rt-7', 'source' => ['type' => 'user', 'userId' => 'U1'], 'message' => ['type' => 'file', 'id' => 'm7', 'fileName' => 'Q3 report.pdf', 'fileSize' => 15]]);
        $this->lineSends($channel, ['type' => 'message', 'webhookEventId' => 'ev8', 'replyToken' => 'rt-8', 'source' => ['type' => 'user', 'userId' => 'U1'], 'message' => ['type' => 'image', 'id' => 'm8', 'contentProvider' => ['type' => 'line']]]);
        $this->work();

        [$report, $picture] = $user->driveFiles()->orderBy('id')->get()->all();
        $this->assertSame(['Q3 report.pdf', 'pdf', 'LINE'], [$report->name, $report->kind, $report->folder->name]);
        $this->assertMatchesRegularExpression('/^line-[\d-]+\.png$/', $picture->name);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/message/m8/content') && $request->hasHeader('Authorization', 'Bearer tok'));

        // LINE takes a picture, and not a PDF
        [$first, $second] = $workflow->runs()->orderBy('id')->get()->all();
        $this->assertStringContainsString('JPEG or PNG', $this->step($first, 'back')->error['message']);
        $this->assertSame(RunStatus::Succeeded, $second->status, (string) json_encode($this->step($second, 'back')->error));

        // It is given an address to fetch the picture from: signed, and for that file alone
        $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/message/reply'))->sole()[0];
        $this->assertSame('rt-8', $sent['replyToken']);
        $this->assertSame('image', $sent['messages'][0]['type']);
        $url = $sent['messages'][0]['originalContentUrl'];
        $this->assertStringStartsWith("https://hooks.example.test/hooks/files/{$picture->ref_id}?expires=", $url);

        $path = substr($url, strlen('https://hooks.example.test'));
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(str_replace($picture->ref_id, $report->ref_id, $path))->assertForbidden();
        // LINE keeps no copy: each phone fetches it when it shows the message, so the link lasts
        $this->travel(2)->days();
        $this->get($path)->assertOk();
        $this->travel(30)->days();
        $this->get($path)->assertForbidden();
    }

    public function test_a_message_with_no_file_says_so_and_the_template_passes_it_by()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);
        $user = User::factory()->create();
        $bot = $this->bot($user);

        $this->actingAs($user)
            ->post(route('workflows.store'), ['template' => 'telegram-drive', 'connections' => ['telegram' => $bot->ref_id]])
            ->assertRedirect();
        $workflow = $user->workflows()->sole();
        $this->assertSame([], References::allProblems($workflow));
        $this->assertSame(['heard.out.has', 'has.true.save', 'save.out.answer'], array_column($workflow->definition['edges'], 'id'));
        $this->assertSame('telegram.file', $workflow->definition['nodes'][2]['type']);

        $said = ['service' => 'telegram', 'chat' => ['id' => '77'], 'sender' => ['id' => '77', 'name' => 'Ada'], 'text' => 'hi', 'files' => []];
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::PUBLISHED, $user), null, 'telegram', 'heard', $said);
        $this->work();

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(0, $user->driveFiles()->count());

        // Without the If before it, the step says why it has nothing to save
        $bare = Workflow::factory()->for($user)->steps([
            $this->node('heard', 'trigger.telegram', ['connection' => $bot->ref_id]),
            $this->node('save', 'telegram.file', ['connection' => $bot->ref_id]),
        ], [$this->edge('heard', 'save')])->create();
        $run = Engine::start($bare, $bare->freeze(WorkflowVersion::PUBLISHED, $user), null, 'telegram', 'heard', $said);
        $this->work();

        $this->assertStringContainsString('the message carried none', $this->step($run, 'save')->error['message']);

        // A LINE step is not given a bot: each asks its own service
        $crossed = Workflow::factory()->for($user)->steps([
            $this->node('heard', 'trigger.telegram', ['connection' => $bot->ref_id]),
            $this->node('save', 'line.file', ['connection' => $bot->ref_id]),
        ], [$this->edge('heard', 'save')])->create();
        $this->assertStringContainsString('Channel', implode(' ', array_column(References::allProblems($crossed), 'message')));
    }

    public function test_a_room_bridge_keeps_a_picture_in_the_drive_and_shows_it_in_the_room()
    {
        Http::fake([
            'api-data.line.me/v2/bot/message/m8/content' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            'api.line.me/v2/bot/profile/*' => Http::response(['displayName' => 'Ada']),
            'api.line.me/*' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $channel = $this->channel($user);

        $this->actingAs($user)
            ->post(route('workflows.store'), ['template' => 'line-room', 'connections' => ['line' => $channel->ref_id]])
            ->assertRedirect();
        $workflow = $user->workflows()->sole();
        $this->assertSame([], References::allProblems($workflow));
        // Each service's file is fetched by that service's own step
        $this->assertSame('line.file', collect($workflow->definition['nodes'])->firstWhere('key', 'save')['type']);
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        $from = ['type' => 'message', 'source' => ['type' => 'user', 'userId' => 'U1']];
        $this->lineSends($channel, [...$from, 'webhookEventId' => 'ev8', 'message' => ['type' => 'image', 'id' => 'm8', 'contentProvider' => ['type' => 'line']]]);
        $this->lineSends($channel, [...$from, 'webhookEventId' => 'ev9', 'message' => ['type' => 'text', 'id' => 'm9', 'text' => 'that was my cat']]);
        $this->work();

        $this->assertSame([RunStatus::Succeeded, RunStatus::Succeeded], $workflow->runs()->pluck('status')->all());
        $file = $user->driveFiles()->sole();
        $this->assertSame('LINE', $file->folder->name);

        // The picture under the sender's name, with no words of its own; and the words
        $messages = ChatRoom::sole()->messages()->get()->map->toChat();
        $shown = $messages->firstWhere('content', '');
        $said = $messages->firstWhere('content', 'that was my cat');
        $this->assertSame(['', 'Ada (LINE)', $file->ref_id, true], [$shown['content'], $shown['sender']['name'], $shown['file']['ref_id'], $shown['file']['is_image']]);
        $this->assertSame(['that was my cat', null], [$said['content'], $said['file']]);

        // Taken out of the Drive since, the message stands without it
        $file->delete();
        $this->assertNull(ChatRoom::sole()->messages()->where('content', '')->sole()->toChat()['file']);
    }

    public function test_a_file_sent_in_a_chat_is_kept_in_the_drive_and_goes_out_through_the_bridge()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);
        $user = User::factory()->create();
        $bot = $this->bot($user);

        $this->actingAs($user)->post(route('workflows.store'), ['template' => 'telegram-room', 'connections' => ['telegram' => $bot->ref_id]])->assertRedirect();
        $workflow = $user->workflows()->sole();
        $this->assertSame([], References::allProblems($workflow));
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        // Someone writes to the bot: their chat gets a room here
        $this->postJson("/hooks/telegram/{$bot->ref_id}", ['update_id' => 700, 'message' => ['message_id' => 1, 'text' => 'hi', 'chat' => ['id' => 77, 'type' => 'private'], 'from' => ['id' => 77, 'first_name' => 'Ada']]], ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();
        $this->work();
        $room = ChatRoom::sole();

        // A picture sent in the room, with a word: kept in one's own Drive, shown under the word
        $this->actingAs($user)->post(route('chats.messages.store', $room), ['content' => 'Here it is', 'upload' => UploadedFile::fake()->createWithContent('map.png', base64_decode(self::PNG))])->assertOk();
        $file = $user->driveFiles()->sole();
        $this->assertSame(['map.png', 'Chats'], [$file->name, $file->folder->name]);
        $said = $room->messages()->where('role', 'user')->sole()->toChat();
        $this->assertSame(['Here it is', $file->ref_id, true], [$said['content'], $said['file']['ref_id'], $said['file']['is_image']]);

        // A file needs no words -- and the bridge sends both on, each by its own call
        $this->actingAs($user)->post(route('chats.messages.store', $room), ['upload' => UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf')])->assertOk();
        $this->actingAs($user)->postJson(route('chats.messages.store', $room), ['content' => 'And a word'])->assertOk();
        $this->work();

        $this->assertSame([], $workflow->runs()->where('status', '!=', RunStatus::Succeeded->value)->pluck('error')->all());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendPhoto') && $request->hasFile('photo', null, 'map.png')
            && collect($request->data())->firstWhere('name', 'caption')['contents'] === 'Here it is'
            && collect($request->data())->firstWhere('name', 'chat_id')['contents'] === '77');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendDocument') && $request->hasFile('document', null, 'notes.pdf'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && $request['text'] === 'And a word');

        // A contact shared with the bot is written in the room as who it is
        $this->postJson("/hooks/telegram/{$bot->ref_id}", ['update_id' => 701, 'message' => ['message_id' => 2, 'chat' => ['id' => 77, 'type' => 'private'], 'from' => ['id' => 77, 'first_name' => 'Ada'], 'contact' => ['phone_number' => '+6620000000', 'first_name' => 'Bo', 'last_name' => 'Lee']]], ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();
        $this->work();
        $this->assertSame("👤 Bo Lee\n+6620000000", $room->messages()->where('role', 'workflow')->reorder('id', 'desc')->first()->content);

        // A place goes out by Telegram's own call for one, and any words with it follow
        $this->actingAs($user)->postJson(route('chats.messages.store', $room), ['content' => 'Meet here', 'place' => ['lat' => 13.7563, 'lng' => 100.5018, 'title' => 'Grand Palace']])->assertOk();
        $this->work();
        $this->assertSame([], $workflow->runs()->where('status', '!=', RunStatus::Succeeded->value)->pluck('error')->all());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendVenue') && $request['title'] === 'Grand Palace' && $request['latitude'] === 13.7563 && $request['chat_id'] === '77');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && $request['text'] === 'Meet here');

        // One already in the Drive is sent by naming it: the chat offers its own Drive's, and takes no one else's
        $this->actingAs($user)->getJson(route('chats.files', ['room' => $room, 'q' => 'map']))->assertOk()->assertJsonPath('files.0.ref_id', $file->ref_id)->assertJsonCount(1, 'files');
        $this->actingAs($user)->postJson(route('chats.messages.store', $room), ['file' => $file->ref_id])->assertOk();
        $this->assertSame($file->ref_id, $room->messages()->reorder('id', 'desc')->first()->toChat()['file']['ref_id']);
        $theirs = $this->picture(User::factory()->create(), 'theirs.png');
        $this->actingAs($user)->postJson(route('chats.messages.store', $room), ['file' => $theirs->ref_id])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(2, $user->driveFiles()->count());
        $this->work();

        // Neither words nor a file is nothing to send; and two people's own chat has no Drive both can open
        $this->actingAs($user)->postJson(route('chats.messages.store', $room), [])->assertStatus(422);
        $other = User::factory()->create();
        $direct = ChatRoom::between($user, $other);
        $this->actingAs($user)->post(route('chats.messages.store', $direct), ['upload' => UploadedFile::fake()->createWithContent('x.png', base64_decode(self::PNG))], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_a_line_chat_and_its_room_are_one_conversation_whatever_is_sent_and_read()
    {
        Http::fake([
            'api-data.line.me/v2/bot/message/m1/content' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            'stickershop.line-scdn.net/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            'api.line.me/v2/bot/profile/*' => Http::response(['displayName' => 'Ada', 'pictureUrl' => 'https://sprofile.line-scdn.net/ada']),
            'api.line.me/*' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $channel = $this->channel($user);

        $this->actingAs($user)->post(route('workflows.store'), ['template' => 'line-room', 'connections' => ['line' => $channel->ref_id]])->assertRedirect();
        $workflow = $user->workflows()->sole();
        $this->assertSame([], References::allProblems($workflow));
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        $from = ['source' => ['type' => 'user', 'userId' => 'U1']];
        $said = fn () => ChatRoom::sole()->messages()->orderBy('id')->get()->map->toChat();
        $pushed = fn () => Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/message/push'))->map(fn (array $pair) => $pair[0])->values();

        // Someone adds the channel: their room is there before they write
        $this->lineSends($channel, [...$from, 'type' => 'follow', 'webhookEventId' => 'ev0']);
        $this->work();
        $this->assertSame(['Ada on LINE', 'Added the channel as a friend.'], [ChatRoom::sole()->title, $said()->sole()['content']]);

        // In: a picture, a sticker, a place -- each as what it is, all in the one room
        $this->lineSends($channel, [...$from, 'type' => 'message', 'webhookEventId' => 'ev1', 'message' => ['type' => 'image', 'id' => 'm1', 'contentProvider' => ['type' => 'line']]]);
        $this->lineSends($channel, [...$from, 'type' => 'message', 'webhookEventId' => 'ev2', 'message' => ['type' => 'sticker', 'id' => 'm2', 'packageId' => '446', 'stickerId' => '1988']]);
        $this->lineSends($channel, [...$from, 'type' => 'message', 'webhookEventId' => 'ev3', 'message' => ['type' => 'location', 'id' => 'm3', 'title' => 'Office', 'address' => '1 Road', 'latitude' => 13.75, 'longitude' => 100.5]]);
        $this->work();
        // Words with one of LINE's own emoji among them: its name in the text, and where it is
        $this->lineSends($channel, [...$from, 'type' => 'message', 'webhookEventId' => 'ev4', 'message' => [
            'type' => 'text', 'id' => 'm4', 'text' => 'hello 😀 (angel Moon)!', 'markAsReadToken' => 'read-4',
            'emojis' => [['index' => 9, 'length' => 12, 'productId' => '670e0cce840a8236ddd4ee4c', 'emojiId' => '003']],
        ]]);
        $this->work();

        $this->assertSame([], $workflow->runs()->where('status', '!=', RunStatus::Succeeded->value)->pluck('error')->all());
        $this->assertTrue($said()->contains(fn (array $message) => $message['content'] === '' && ($message['file']['is_image'] ?? false)));
        // A sticker is drawn as one, small and bare -- or only named, when its picture could not be had
        $this->assertTrue($said()->contains(fn (array $message) => ($message['sticker'] && $message['content'] === '') || $message['content'] === 'Sticker'));
        // A place is kept as a place, to be drawn on a map: not as words about one
        $this->assertTrue($said()->contains(fn (array $message) => $message['content'] === '' && $message['place'] === ['lat' => 13.75, 'lng' => 100.5, 'title' => 'Office', 'address' => '1 Road']));
        // The words stay words; the emoji's picture goes where its name is, counted as the page counts
        $hello = $said()->last();
        $this->assertSame('hello 😀 (angel Moon)!', $hello['content']);
        $this->assertSame([['index' => 9, 'length' => 12, 'url' => 'https://stickershop.line-scdn.net/sticonshop/v1/sticon/670e0cce840a8236ddd4ee4c/android/003.png']], $hello['emojis']);
        $this->assertSame(['Ada (LINE)'], $said()->pluck('sender.name')->unique()->values()->all());
        // Their picture is kept once, with the room -- not written into every message
        $this->assertSame([null], $said()->map(fn (array $message) => $message['sender']['picture'] ?? null)->unique()->values()->all());

        // Who it is with is kept with the room: their face, and what LINE says of them by name
        $room = ChatRoom::sole();
        $this->assertSame('https://sprofile.line-scdn.net/ada', $room->picture);
        $this->assertSame(['name' => 'Ada', 'line_id' => 'U1'], $room->details);
        $this->actingAs($user)->get(route('chats.show', $room))->assertInertia(fn ($page) => $page
            ->where('room.picture', 'https://sprofile.line-scdn.net/ada')
            ->where('room.details.line_id', 'U1'));
        $this->actingAs($user)->get(route('chats.index'))->assertInertia(fn ($page) => $page->where('personal.0.picture', 'https://sprofile.line-scdn.net/ada'));

        // Its owner notes more by hand, by names a workflow can read; and a later run reads them
        $this->actingAs($user)->patch(route('chats.update', $room), ['details' => [...$room->details, 'tier' => 'gold']])->assertRedirect();
        $this->actingAs($user)->patch(route('chats.update', $room), ['details' => ['not a name' => 'x']])->assertSessionHasErrors('details');
        $this->assertSame(['name' => 'Ada', 'line_id' => 'U1', 'tier' => 'gold'], $room->fresh()->details);

        // What the workflow keeps -- who they are on LINE -- is not theirs to change or take away
        $this->actingAs($user)->patch(route('chats.update', $room), ['details' => ['line_id' => 'U-someone-else', 'tier' => 'silver']])->assertRedirect();
        $this->assertSame(['name' => 'Ada', 'line_id' => 'U1', 'tier' => 'silver'], $room->fresh()->details);
        $this->actingAs($user)->get(route('chats.show', $room))->assertInertia(fn ($page) => $page->where('room.kept', ['name', 'line_id']));
        $this->actingAs($user)->patch(route('chats.update', $room), ['details' => ['tier' => 'gold']])->assertRedirect();

        // Read here, read there -- once, with the token the last thing they sent came with
        $this->actingAs($user)->postJson(route('chats.read', $room), ['message' => $room->messages()->max('id')])->assertOk();
        $this->actingAs($user)->postJson(route('chats.read', $room), ['message' => $room->messages()->max('id')])->assertOk();
        $this->work();
        $marked = fn () => Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/chat/markAsRead'))->map(fn (array $pair) => $pair[0]['markAsReadToken'])->values()->all();
        $this->assertSame(['read-4'], $marked());

        // LINE sends again, late, what did not get through: the token is the last one sent's, which
        // marks all before it read -- not that of the last to come
        $at = fn (int $seconds) => now()->addSeconds($seconds)->getTimestampMs();
        $this->lineSends($channel, [...$from, 'type' => 'message', 'webhookEventId' => 'ev5', 'timestamp' => $at(60), 'message' => ['type' => 'text', 'id' => 'm5', 'text' => 'newer', 'markAsReadToken' => 'read-5']]);
        $this->work();
        $this->lineSends($channel, [...$from, 'type' => 'message', 'webhookEventId' => 'ev0', 'timestamp' => $at(-600), 'deliveryContext' => ['isRedelivery' => true], 'message' => ['type' => 'text', 'id' => 'm0', 'text' => 'sent a while ago', 'markAsReadToken' => 'read-0']]);
        $this->work();
        $this->actingAs($user)->postJson(route('chats.read', $room), ['message' => $room->messages()->max('id')])->assertOk();
        $this->work();
        $this->assertSame(['read-4', 'read-5'], $marked());

        // A tap comes with no token: what was written before it is marked read all the same
        $this->lineSends($channel, [...$from, 'type' => 'message', 'webhookEventId' => 'ev6', 'timestamp' => $at(120), 'message' => ['type' => 'text', 'id' => 'm6', 'text' => 'and then', 'markAsReadToken' => 'read-6']]);
        $this->work();
        $this->lineSends($channel, [...$from, 'type' => 'postback', 'webhookEventId' => 'ev7', 'timestamp' => $at(130), 'postback' => ['data' => 'help']]);
        $this->work();
        $this->actingAs($user)->postJson(route('chats.read', $room), ['message' => $room->messages()->max('id')])->assertOk();
        $this->work();
        $this->assertSame(['read-4', 'read-5', 'read-6'], $marked());

        // Out: words; a picture as a picture; any other file as a link that opens it
        $this->actingAs($user)->postJson(route('chats.messages.store', $room), ['content' => 'On my way'])->assertOk();
        $this->work();
        $this->actingAs($user)->post(route('chats.messages.store', $room), ['content' => 'Look', 'upload' => UploadedFile::fake()->createWithContent('map.png', base64_decode(self::PNG))])->assertOk();
        $this->work();
        $this->actingAs($user)->post(route('chats.messages.store', $room), ['upload' => UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf')])->assertOk();
        $this->work();

        // A place shared in the room goes out as a place, with what was written beside it
        $this->actingAs($user)->postJson(route('chats.messages.store', $room), ['content' => 'Meet here', 'place' => ['lat' => 13.7563, 'lng' => 100.5018, 'title' => 'Grand Palace', 'address' => 'Na Phra Lan Rd']])->assertOk();
        $this->work();
        $this->actingAs($user)->postJson(route('chats.messages.store', $room), ['place' => ['lat' => 200, 'lng' => 100]])->assertStatus(422);

        $this->assertSame([], $workflow->runs()->where('status', '!=', RunStatus::Succeeded->value)->pluck('error')->all());
        [$words, $picture, $link, $where] = $pushed()->all();
        // What a workflow hears of a room includes what is kept with it: here, what was noted by hand
        $this->assertSame('gold', $workflow->runs()->where('trigger', 'chat')->latest('id')->first()->trigger_data['room']['details']['tier']);
        $this->assertSame(['type' => 'location', 'title' => 'Grand Palace', 'address' => 'Na Phra Lan Rd', 'latitude' => 13.7563, 'longitude' => 100.5018], $where['messages'][0]);
        $this->assertSame('Meet here', $where['messages'][1]['text']);
        $this->assertSame(['Meet here', 'Grand Palace'], [($shared = $room->messages()->reorder('id', 'desc')->first()->toChat())['content'], $shared['place']['title']]);
        $this->assertSame(['U1', [['type' => 'text', 'text' => 'On my way']]], [$words['to'], $words['messages']]);
        $this->assertSame(['image', 'Look'], [$picture['messages'][0]['type'], $picture['messages'][1]['text']]);
        $pdf = $user->driveFiles()->where('name', 'notes.pdf')->sole();
        $this->assertStringContainsString("📎 notes.pdf\nhttps://hooks.example.test/hooks/files/{$pdf->ref_id}?", $link['messages'][0]['text']);

        // Written soon after the other end wrote, it goes as a reply to that -- which LINE does not
        // count -- and LINE takes a token once: what is written next is pushed
        $replied = fn () => Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/message/reply'))->map(fn (array $pair) => $pair[0]->data())->values()->all();
        $say = function (string $words) use ($user, $room) {
            $this->actingAs($user)->postJson(route('chats.messages.store', $room), ['content' => $words])->assertOk();
            $this->work();
        };
        $this->assertSame([], $replied());
        $this->lineSends($channel, [...$from, 'type' => 'message', 'webhookEventId' => 'ev8', 'replyToken' => 'rt-8', 'message' => ['type' => 'text', 'id' => 'm20', 'text' => 'Are you there?']]);
        $this->work();
        $before = $pushed()->count();
        $say('Yes');
        $say('Still here');
        $this->assertSame([['replyToken' => 'rt-8', 'messages' => [['type' => 'text', 'text' => 'Yes']]]], $replied());
        $this->assertSame([$before + 1, 'Still here'], [$pushed()->count(), $pushed()->last()['messages'][0]['text']]);

        // And only for a while after: a token whose minute is up is not tried
        $this->lineSends($channel, [...$from, 'type' => 'message', 'webhookEventId' => 'ev9', 'replyToken' => 'rt-9', 'message' => ['type' => 'text', 'id' => 'm21', 'text' => 'Hello?']]);
        $this->work();
        $this->travel(2)->minutes();
        $say('Sorry, I was away');
        $this->assertCount(1, $replied());
        $this->assertSame([$before + 2, 'Sorry, I was away'], [$pushed()->count(), $pushed()->last()['messages'][0]['text']]);
        $this->assertSame([], $workflow->runs()->where('status', '!=', RunStatus::Succeeded->value)->pluck('error')->all());

        // The link opens the file for whoever has it, and nothing else
        preg_match('#https://hooks\.example\.test(/hooks/files/\S+)#', $link['messages'][0]['text'], $found);
        $this->get($found[1])->assertOk();
        $this->travel(25)->hours();
        $this->get($found[1])->assertForbidden();
    }

    // --- around: the Drive itself ---

    public function test_an_ai_answers_on_line_only_when_asked_for_by_a_reply_or_a_push()
    {
        Http::fake([
            // Anew each time it is asked: an answer streamed is read once
            'openrouter.ai/*' => fn () => Http::response("data: {\"choices\":[{\"delta\":{\"content\":\"We open at nine.\"}}]}\n\ndata: [DONE]\n\n"),
            'api.line.me/*' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $channel = $this->channel($user);
        $ai = Connection::factory()->openrouter()->for($user)->create(['settings' => ['base_url' => 'https://openrouter.ai/api/v1', 'default_model' => 'some/model']]);
        $answer = '{{ node("ask").text }}';

        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('heard', 'trigger.line', ['connection' => $channel->ref_id]),
            $this->node('asked', 'logic.if', ['condition' => 'starts(trigger.text, "@ai")']),
            $this->node('ask', 'ai.chat', ['connection' => $ai->ref_id, 'prompt' => '{{ trigger.text }}']),
            $this->node('aireply', 'line.reply', ['connection' => $channel->ref_id, 'token' => '{{ trigger.reply.token }}', 'within' => 60, 'text' => $answer]),
            $this->node('aipush', 'line.send', ['connection' => $channel->ref_id, 'to' => '{{ trigger.chat.id }}', 'text' => $answer]),
            $this->node('after', 'output.log', ['message' => 'Answered: '.$answer]),
        ], [
            $this->edge('heard', 'asked'), $this->edge('asked', 'ask', 'true'), $this->edge('ask', 'aireply'),
            $this->edge('aireply', 'aipush', 'unsent'), $this->edge('aireply', 'after'), $this->edge('aipush', 'after'),
        ])->create();
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        $says = function (string $event, string $text, ?string $token) use ($channel) {
            $this->lineSends($channel, array_filter(['type' => 'message', 'webhookEventId' => $event, 'replyToken' => $token, 'source' => ['type' => 'user', 'userId' => 'U1'], 'message' => ['type' => 'text', 'id' => "m-{$event}", 'text' => $text]]));
            $this->work();
        };
        $sent = fn (string $how) => Http::recorded(fn (Request $request) => str_ends_with($request->url(), "/message/{$how}"))->map(fn (array $pair) => $pair[0]->data())->values()->all();
        $asked = fn () => Http::recorded(fn (Request $request) => str_contains($request->url(), 'openrouter.ai'))->count();
        $went = fn (string $key) => $workflow->runs()->latest('id')->first()->steps()->where('node_key', $key)->sole()->status->value;

        // Not asked for: the model is not put the question, and nothing goes back
        $says('ev1', 'Hello there', 'rt-1');
        $this->assertSame([0, [], []], [$asked(), $sent('reply'), $sent('push')]);

        // Asked for, however it is written: answered as a reply, which is free, and the push passed by
        $says('ev2', '  @AI when do you open?', 'rt-2');
        $this->assertSame([1, [['replyToken' => 'rt-2', 'messages' => [['type' => 'text', 'text' => 'We open at nine.']]]], []], [$asked(), $sent('reply'), $sent('push')]);
        $this->assertSame(['skipped', 'succeeded'], [$went('aipush'), $went('after')]);

        // A token LINE has taken can't answer again: the same words are pushed instead, and the run goes on
        $says('ev3', '@ai and on Sunday?', 'rt-2');
        $this->assertCount(1, $sent('reply'));
        $this->assertSame([['to' => 'U1', 'messages' => [['type' => 'text', 'text' => 'We open at nine.']]]], $sent('push'));
        $this->assertSame(['succeeded', 'succeeded'], [$went('aipush'), $went('after')]);
        $this->assertSame([], $workflow->runs()->where('status', '!=', RunStatus::Succeeded->value)->pluck('error')->all());
    }

    public function test_text_is_written_to_a_file_found_and_read_back()
    {
        $user = User::factory()->create();
        $this->picture($user);

        $run = $this->runChain($user, [
            $this->node('save', 'drive.save', ['from' => 'text', 'text' => 'Total: {{ trigger.total }}', 'name' => 'sum-{{ trigger.day }}.md', 'folder' => 'Reports']),
            $this->node('find', 'drive.find', ['folder' => 'reports', 'name' => 'sum', 'kind' => 'any', 'limit' => 5]),
            $this->node('read', 'drive.read', ['file' => '{{ item(node("find").files, 1).ref_id }}']),
            $this->node('pictures', 'drive.find', ['kind' => 'image', 'limit' => 5]),
            $this->node('nowhere', 'drive.find', ['folder' => 'No such folder', 'kind' => 'any', 'limit' => 5]),
        ], ['total' => 42, 'day' => 'mon']);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(['sum-mon.md', 'Reports'], [$this->step($run, 'save')->output['name'], $this->step($run, 'save')->output['folder']]);
        $this->assertSame(1, $this->step($run, 'find')->output['count']);
        $this->assertSame(['Total: 42', false], [$this->step($run, 'read')->output['text'], $this->step($run, 'read')->output['cut_short']]);
        $this->assertSame(['cat.png'], array_column($this->step($run, 'pictures')->output['files'], 'name'));
        $this->assertSame([], $this->step($run, 'nowhere')->output['files']);
        // Text needs a name of its own
        $run = $this->runChain($user, [$this->node('save', 'drive.save', ['from' => 'text', 'text' => 'x'])]);
        $this->assertStringContainsString('Give the file a name', $this->step($run, 'save')->error['message']);
    }

    public function test_a_projects_workflow_keeps_its_files_in_the_projects_drive_and_reads_no_one_elses()
    {
        $editor = User::factory()->create();
        $project = Project::factory()->withMember($editor, Project::EDITOR)->create();
        $own = $this->picture($editor);

        $run = $this->runChain($editor, [$this->node('save', 'drive.save', ['from' => 'text', 'text' => 'x', 'name' => 'x.txt', 'folder' => 'Shared'])], [], $project);
        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $file = $project->driveFiles()->sole();
        $this->assertSame([null, $editor->id, 'Shared'], [$file->user_id, $file->created_by, $file->folder->name]);
        $this->assertSame($project->id, $file->folder->project_id);

        // Their own Drive is not the project's to read, by ref_id or by address
        foreach ([$own->ref_id, $own->url()] as $named) {
            $run = $this->runChain($editor, [$this->node('read', 'drive.read', ['file' => $named])], [], $project);
            $this->assertStringContainsString('not in this workflow\'s Drive', $this->step($run, 'read')->error['message']);
        }
    }

    // --- out: to a model ---

    public function test_pictures_from_the_drive_are_put_to_a_model_with_the_question()
    {
        Http::fake(['openrouter.ai/*' => Http::response("data: {\"choices\":[{\"delta\":{\"content\":\"A cat\"}}]}\n\ndata: [DONE]\n\n")]);
        $user = User::factory()->create();
        $ai = Connection::factory()->openrouter()->for($user)->create(['settings' => ['base_url' => 'https://openrouter.ai/api/v1', 'default_model' => 'some/model']]);
        $cat = $this->picture($user);
        $dog = $this->picture($user, 'dog.png');
        $notes = $this->runChain($user, [$this->node('save', 'drive.save', ['from' => 'text', 'text' => 'x', 'name' => 'x.txt'])]);

        $run = $this->runChain($user, [$this->node('ask', 'ai.chat', ['connection' => $ai->ref_id, 'prompt' => 'What is this?', 'pictures' => "{$cat->ref_id}, {$dog->ref_id}"])]);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame('A cat', $this->step($run, 'ask')->output['text']);
        Http::assertSent(fn (Request $request) => $request['messages'][0]['content'][0] === ['type' => 'text', 'text' => 'What is this?']
            && count($request['messages'][0]['content']) === 3
            && $request['messages'][0]['content'][2]['image_url']['url'] === 'data:image/png;base64,'.self::PNG);

        // Only what a model can look at
        $run = $this->runChain($user, [$this->node('ask', 'ai.chat', ['connection' => $ai->ref_id, 'prompt' => 'And this?', 'pictures' => $this->step($notes, 'save')->output['ref_id']])]);
        $this->assertStringContainsString('not a picture a model can look at', $this->step($run, 'ask')->error['message']);
    }
}
