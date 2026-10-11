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
        $this->travel(2)->hours();
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

    // --- around: the Drive itself ---

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
