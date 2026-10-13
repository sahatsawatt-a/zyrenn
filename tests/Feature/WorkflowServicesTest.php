<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Connections\Meta;
use App\Support\Workflow\Engine;
use App\Support\Workflow\Inbound\Envelope;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\NodeTypes;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use App\Support\Workflow\Steps\WhatsAppApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every call Telegram, LINE and LinkedIn offer as a step of its own: each
 * asks the call it stands for, with what its settings came to -- and the
 * triggers start on the kind of thing they are set to. Through the real
 * queue; the services are faked.
 */
class WorkflowServicesTest extends TestCase
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

    private function node(string $key, string $type, array $config = []): array
    {
        return ['id' => $key, 'key' => $key, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    private function edge(string $from, string $to, string $handle = 'out'): array
    {
        return ['id' => "{$from}.{$handle}.{$to}", 'source' => $from, 'sourceHandle' => $handle, 'target' => $to];
    }

    /**
     * Start → each step in turn, run by hand and worked off; nothing may
     * stand in the way of publishing it.
     */
    private function runChain(User $user, array $steps, array $payload): WorkflowRun
    {
        $nodes = [$this->node('start', 'trigger.manual', ['payload' => json_encode($payload)]), ...$steps];
        $edges = [];

        foreach (array_slice($nodes, 1) as $at => $node) {
            $edges[] = $this->edge($nodes[$at]['id'], $node['id']);
        }

        $workflow = Workflow::factory()->for($user)->steps($nodes, $edges)->create();
        $this->assertSame([], References::allProblems($workflow));
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        return $run->fresh();
    }

    private function step(WorkflowRun $run, string $key): WorkflowNodeRun
    {
        return $run->steps()->where('node_key', $key)->sole();
    }

    /**
     * Every step of a run went through, or the first that didn't says why.
     */
    private function assertAllWentThrough(WorkflowRun $run): void
    {
        $failed = $run->steps()->where('status', '!=', NodeStatus::Succeeded->value)->orderBy('id')->first();

        $this->assertNull($failed, $failed ? "{$failed->node_key}: ".json_encode($failed->error) : '');
        $this->assertSame(RunStatus::Succeeded, $run->status);
    }

    private function file(User $user, string $name, ?string $mime = null, ?string $bytes = null): DriveFile
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'test-file-');
        file_put_contents($temp, $bytes ?? base64_decode(self::PNG));
        $file = DriveFile::keep($temp, $name, $user);
        unlink($temp);

        // What it would be had it really been one
        if ($mime !== null) {
            $file->forceFill(['mime' => $mime])->save();
        }

        return $file;
    }

    /**
     * A grey PNG of a size, written out by hand: the tests have nothing to draw with.
     */
    private function png(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat("\0".str_repeat("\x80", $width), $height)))
            .$chunk('IEND', '');
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

    /**
     * What was asked of a service, by the last part of each call's address.
     *
     * @return array<string, Request>
     */
    private function asked(string $host): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $host))
            ->mapWithKeys(fn (array $pair) => [basename((string) parse_url($pair[0]->url(), PHP_URL_PATH)) => $pair[0]])
            ->all();
    }

    // --- the catalogue ---

    public function test_every_kind_of_step_has_something_to_run_it_and_belongs_to_a_group()
    {
        $steps = (new \ReflectionClassConstant(Engine::class, 'STEPS'))->getValue();

        foreach (NodeTypes::all() as $type => $kind) {
            $this->assertArrayHasKey($type, $steps, "Nothing runs {$type}.");
            $this->assertArrayHasKey($kind['group'], NodeTypes::groups(), "{$type} is in no group.");
            // A step that sends is never simply made again, so it says what happens instead
            $this->assertTrue(($kind['repeatable'] ?? true) || in_array('unsure', array_column($kind['config'], 'name'), true), $type);
        }

        $this->assertSame([], array_values(array_diff(array_keys($steps), array_keys(NodeTypes::all()))));
    }

    // --- Telegram ---

    public function test_each_telegram_call_is_a_step_that_asks_the_method_it_stands_for()
    {
        Http::fake(['api.telegram.org/*' => fn (Request $request) => Http::response(['ok' => true, 'result' => match (basename($request->url())) {
            'getChatMemberCount' => 42,
            'getChat' => ['id' => -100, 'type' => 'supergroup', 'title' => 'Team', 'is_forum' => true],
            'getChatMember' => ['status' => 'administrator', 'user' => ['id' => 5, 'first_name' => 'Ada']],
            'sendMediaGroup' => [['message_id' => 1], ['message_id' => 2]],
            'createForumTopic' => ['message_thread_id' => 7, 'name' => 'Orders'],
            'sendMessage', 'sendVenue', 'sendPoll', 'copyMessage' => ['message_id' => 9],
            default => true,
        }])]);
        $user = User::factory()->create();
        $bot = $this->bot($user)->ref_id;
        [$one, $two] = [$this->file($user, 'one.png'), $this->file($user, 'two.png')];
        $on = fn (array $settings = []) => ['connection' => $bot, ...$settings];

        $run = $this->runChain($user, [
            $this->node('send', 'telegram.send', $on(['chat' => '{{ trigger.chat.id }}', 'text' => 'Go ahead?', 'per_row' => 2, 'buttons' => [
                ['name' => 'Yes please', 'value' => 'yes:{{ trigger.sender.id }}'], ['name' => 'Read more', 'value' => 'https://example.com'], ['name' => 'No'],
            ]])),
            $this->node('album', 'telegram.album', $on(['files' => "{$one->ref_id}, {$two->ref_id}", 'text' => 'Both'])),
            $this->node('sticker', 'telegram.sticker', $on(['sticker' => 'CAAC'])),
            $this->node('place', 'telegram.location', $on(['lat' => '{{ trigger.location.lat }}', 'lng' => '{{ trigger.location.lng }}', 'title' => 'Office'])),
            $this->node('contact', 'telegram.contact', $on(['phone' => '+6620000000', 'first_name' => 'Ada'])),
            $this->node('poll', 'telegram.poll', $on(['question' => 'Lunch?', 'options' => "Noodles\n\nRice", 'anonymous' => 'no', 'several' => 'yes'])),
            $this->node('forward', 'telegram.forward', $on(['chat' => '-200'])),
            $this->node('copy', 'telegram.copy', $on(['chat' => '-200'])),
            $this->node('invoice', 'telegram.invoice', $on(['title' => 'Beans', 'amount' => '5'])),
            $this->node('topic', 'telegram.topic', $on(['name' => 'Orders'])),
            $this->node('typing', 'telegram.action', $on()),
            $this->node('edit', 'telegram.edit', $on(['message' => '{{ node("send").message_id }}', 'text' => 'Done.'])),
            $this->node('rebutton', 'telegram.edit_buttons', $on(['buttons' => [['name' => 'Undo', 'value' => 'undo'], ['name' => 'Where', 'value' => 'link:{{ trigger.site }}']]])),
            $this->node('delete', 'telegram.delete', $on()),
            $this->node('pin', 'telegram.pin', $on()),
            $this->node('unpin', 'telegram.unpin', $on()),
            $this->node('react', 'telegram.react', $on(['emoji' => '🔥'])),
            $this->node('heard', 'telegram.answer_button', $on(['text' => 'Saved!', 'show' => 'alert'])),
            $this->node('commands', 'telegram.commands', $on(['commands' => [['name' => 'expense', 'value' => 'Note an expense']]])),
            $this->node('ban', 'telegram.ban', $on(['minutes' => '10'])),
            $this->node('unban', 'telegram.unban', $on()),
            $this->node('quiet', 'telegram.restrict', $on(['allow' => 'messages'])),
            $this->node('inline', 'telegram.answer_inline', $on(['results' => [['name' => 'Say hello', 'value' => 'Hello from {{ trigger.sender.id }}']]])),
            $this->node('checkout', 'telegram.answer_checkout', $on(['answer' => 'refuse', 'why' => 'Sold out'])),
            $this->node('about', 'telegram.chat', $on()),
            $this->node('who', 'telegram.member', $on()),
            $this->node('many', 'telegram.member_count', $on()),
        ], [
            'chat' => ['id' => '77'], 'sender' => ['id' => '5'], 'reply' => ['to' => 3], 'location' => ['lat' => 13.75, 'lng' => 100.5], 'site' => 'https://example.com/shop',
            'button' => ['id' => 'press-1'], 'inline' => ['id' => 'ask-1'], 'checkout' => ['id' => 'pay-1'],
        ]);

        $this->assertAllWentThrough($run);
        $asked = $this->asked('api.telegram.org');

        // Buttons: what one says, and what pressing it does
        $this->assertSame([
            [['text' => 'Yes please', 'callback_data' => 'yes:5'], ['text' => 'Read more', 'url' => 'https://example.com']],
            [['text' => 'No', 'callback_data' => 'No']],
        ], $asked['sendMessage']['reply_markup']['inline_keyboard']);

        // An album goes as one message, its files beside it
        $this->assertTrue($asked['sendMediaGroup']->hasFile('file0', null, 'one.png') && $asked['sendMediaGroup']->hasFile('file1', null, 'two.png'));
        $media = json_decode(collect($asked['sendMediaGroup']->data())->firstWhere('name', 'media')['contents'], true);
        $this->assertSame([['type' => 'photo', 'media' => 'attach://file0', 'caption' => 'Both'], ['type' => 'photo', 'media' => 'attach://file1']], $media);
        $this->assertSame([1, 2], $this->step($run, 'album')->output['message_ids']);

        $this->assertSame(['77', 'CAAC'], [$asked['sendSticker']['chat_id'], $asked['sendSticker']['sticker']]);
        $this->assertSame([13.75, 100.5, 'Office', 'Office'], [$asked['sendVenue']['latitude'], $asked['sendVenue']['longitude'], $asked['sendVenue']['title'], $asked['sendVenue']['address']]);
        $this->assertSame(['+6620000000', 'Ada'], [$asked['sendContact']['phone_number'], $asked['sendContact']['first_name']]);
        $this->assertSame([[['text' => 'Noodles'], ['text' => 'Rice']], false, true], [$asked['sendPoll']['options'], $asked['sendPoll']['is_anonymous'], $asked['sendPoll']['allows_multiple_answers']]);
        $this->assertSame(['-200', '77', '3'], [$asked['forwardMessage']['chat_id'], $asked['forwardMessage']['from_chat_id'], $asked['forwardMessage']['message_id']]);
        $this->assertSame(['-200', '77', '3'], [$asked['copyMessage']['chat_id'], $asked['copyMessage']['from_chat_id'], $asked['copyMessage']['message_id']]);
        // Stars need no provider; the run stands in for a reference of one's own
        $this->assertSame(['XTR', [['label' => 'Beans', 'amount' => 5]], $run->ref_id], [$asked['sendInvoice']['currency'], $asked['sendInvoice']['prices'], $asked['sendInvoice']['payload']]);
        $this->assertFalse(isset($asked['sendInvoice']['provider_token']));
        $this->assertSame(7, $this->step($run, 'topic')->output['topic']);
        $this->assertSame('typing', $asked['sendChatAction']['action']);

        // A message changed in place loses its buttons unless some are named
        $this->assertSame(['9', 'Done.', ['inline_keyboard' => []]], [$asked['editMessageText']['message_id'], $asked['editMessageText']['text'], $asked['editMessageText']['reply_markup']]);
        // "link:" says what a formula gives is an address
        $this->assertSame([[['text' => 'Undo', 'callback_data' => 'undo']], [['text' => 'Where', 'url' => 'https://example.com/shop']]], $asked['editMessageReplyMarkup']['reply_markup']['inline_keyboard']);
        $this->assertSame(['77', '3'], [$asked['deleteMessage']['chat_id'], $asked['deleteMessage']['message_id']]);
        $this->assertTrue($asked['pinChatMessage']['disable_notification']);
        $this->assertFalse(isset($asked['unpinChatMessage']['message_id']));
        $this->assertSame([['type' => 'emoji', 'emoji' => '🔥']], $asked['setMessageReaction']['reaction']);
        $this->assertSame(['press-1', 'Saved!', true], [$asked['answerCallbackQuery']['callback_query_id'], $asked['answerCallbackQuery']['text'], $asked['answerCallbackQuery']['show_alert']]);
        $this->assertSame([['command' => 'expense', 'description' => 'Note an expense']], $asked['setMyCommands']['commands']);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), $asked['banChatMember']['until_date'], 120);
        $this->assertSame(['77', '5', true], [$asked['unbanChatMember']['chat_id'], $asked['unbanChatMember']['user_id'], $asked['unbanChatMember']['only_if_banned']]);
        $this->assertSame([true, false], [$asked['restrictChatMember']['permissions']['can_send_messages'], $asked['restrictChatMember']['permissions']['can_send_photos']]);
        $this->assertSame(['ask-1', 'Say hello', 'Hello from 5'], [$asked['answerInlineQuery']['inline_query_id'], $asked['answerInlineQuery']['results'][0]['title'], $asked['answerInlineQuery']['results'][0]['input_message_content']['message_text']]);
        $this->assertSame(['pay-1', false, 'Sold out'], [$asked['answerPreCheckoutQuery']['pre_checkout_query_id'], $asked['answerPreCheckoutQuery']['ok'], $asked['answerPreCheckoutQuery']['error_message']]);

        // Asked: what Telegram says, in the step's own words
        $this->assertSame(['-100', 'supergroup', 'Team', true], array_values(array_intersect_key($this->step($run, 'about')->output, array_flip(['id', 'kind', 'title', 'topics']))));
        $this->assertSame(['Ada', 'administrator', true, true], array_values(array_intersect_key($this->step($run, 'who')->output, array_flip(['name', 'status', 'in', 'admin']))));
        $this->assertSame(42, $this->step($run, 'many')->output['count']);
    }

    public function test_a_telegram_step_says_what_is_missing_and_money_needs_a_provider()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: message to edit not found'], 400)]);
        $user = User::factory()->create();
        $bot = $this->bot($user)->ref_id;
        $run = fn (string $type, array $settings) => $this->step($this->runChain($user, [$this->node('it', $type, ['connection' => $bot, ...$settings])], ['chat' => ['id' => '77'], 'reply' => ['to' => 3]]), 'it')->error['message'];

        $this->assertStringContainsString('from 2 to 12 answers', $run('telegram.poll', ['question' => 'Lunch?', 'options' => 'Only one']));
        $this->assertStringContainsString('needs a payment provider', $run('telegram.invoice', ['title' => 'Beans', 'amount' => '500', 'currency' => 'thb']));
        $this->assertStringContainsString('whole number', $run('telegram.invoice', ['title' => 'Beans', 'amount' => '10.50']));
        $this->assertStringContainsString('two numbers', $run('telegram.location', ['lat' => 'north', 'lng' => '100']));
        // What Telegram says is passed on as it said it
        $this->assertSame('Telegram said: Bad Request: message to edit not found', $run('telegram.edit', ['message' => '4', 'text' => 'Done.']));
    }

    // --- LINE ---

    public function test_each_line_call_is_a_step_and_a_message_is_made_of_what_its_kind_needs()
    {
        Http::fake([
            'api.line.me/v2/bot/message/quota' => Http::response(['type' => 'limited', 'value' => 200]),
            'api.line.me/v2/bot/message/quota/consumption' => Http::response(['totalUsage' => 12]),
            'api.line.me/v2/bot/group/C1/summary' => Http::response(['groupName' => 'Team', 'pictureUrl' => 'https://example.com/t.png']),
            'api.line.me/v2/bot/group/C1/members/count' => Http::response(['count' => 5]),
            'api.line.me/*' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $channel = $this->channel($user)->ref_id;
        $video = $this->file($user, 'clip.mp4', 'video/mp4');
        $cover = $this->file($user, 'cover.png');
        $on = fn (array $settings = []) => ['connection' => $channel, ...$settings];
        $to = fn (array $settings) => $on(['to' => 'U9', ...$settings]);

        $run = $this->runChain($user, [
            $this->node('answer', 'line.reply', $on(['kind' => 'buttons', 'title' => 'Order 12', 'text' => 'Send it?', 'choices' => [
                ['name' => 'Yes', 'value' => 'postback:send:12'], ['name' => 'Look first', 'value' => 'https://example.com/12'], ['name' => 'Not yet'], ['name' => 'The shop', 'value' => 'link:{{ trigger.site }}'],
            ]])),
            $this->node('words', 'line.send', $to(['text' => 'On its way', 'quote' => '{{ trigger.reply.quote }}', 'quick' => [['name' => 'Thanks', 'value' => 'thank you']]])),
            $this->node('sticker', 'line.send', $to(['kind' => 'sticker', 'package' => '446', 'sticker' => '1988'])),
            $this->node('place', 'line.send', $to(['kind' => 'location', 'title' => 'Office', 'lat' => '13.75', 'lng' => '100.5'])),
            $this->node('video', 'line.send', $to(['kind' => 'video', 'media' => $video->ref_id, 'preview' => $cover->ref_id])),
            $this->node('ask', 'line.send', $to(['kind' => 'confirm', 'text' => 'Sure?', 'yes' => 'Yes, go'])),
            $this->node('card', 'line.send', $to(['kind' => 'flex', 'alt' => 'Your order', 'flex' => '{"type": "bubble", "body": {"type": "box", "layout": "vertical", "contents": [{"type": "text", "text": "For {{ trigger.sender.id }}"}]}}'])),
            $this->node('many', 'line.multicast', $on(['to' => 'U1, U2 U1', 'text' => 'News'])),
            $this->node('all', 'line.broadcast', $on(['text' => 'To everyone'])),
            $this->node('dots', 'line.loading', $on(['seconds' => 12])),
            $this->node('read', 'line.read', $on()),
            $this->node('quota', 'line.quota', $on()),
            $this->node('used', 'line.consumption', $on()),
            $this->node('group', 'line.group', $on(['chat' => 'C1'])),
            $this->node('count', 'line.group_count', $on(['chat' => 'C1'])),
            $this->node('leave', 'line.leave', $on(['chat' => 'C1'])),
        ], ['chat' => ['id' => 'U9'], 'sender' => ['id' => 'U9'], 'reply' => ['token' => 'rt-9', 'quote' => 'q-9', 'read' => 'read-9'], 'site' => 'https://example.com/shop']);

        $this->assertAllWentThrough($run);
        $sent = fn (string $how) => Http::recorded(fn (Request $request) => str_ends_with($request->url(), "/message/{$how}"))->map(fn (array $pair) => $pair[0])->values();

        // Buttons: an address opens, a postback comes back unseen, anything else
        // is said -- and "link:" says what a formula gives is an address
        $buttons = $sent('reply')->sole()['messages'][0];
        $this->assertSame(['template', 'Send it?', 'buttons', 'Order 12'], [$buttons['type'], $buttons['altText'], $buttons['template']['type'], $buttons['template']['title']]);
        $this->assertSame([
            ['type' => 'postback', 'label' => 'Yes', 'data' => 'send:12', 'displayText' => 'Yes'],
            ['type' => 'uri', 'label' => 'Look first', 'uri' => 'https://example.com/12'],
            ['type' => 'message', 'label' => 'Not yet', 'text' => 'Not yet'],
            ['type' => 'uri', 'label' => 'The shop', 'uri' => 'https://example.com/shop'],
        ], $buttons['template']['actions']);

        $pushed = $sent('push')->map(fn (Request $request) => $request['messages'][0])->all();
        $this->assertSame(['text', 'On its way', 'q-9'], [$pushed[0]['type'], $pushed[0]['text'], $pushed[0]['quoteToken']]);
        $this->assertSame([['type' => 'action', 'action' => ['type' => 'message', 'label' => 'Thanks', 'text' => 'thank you']]], $pushed[0]['quickReply']['items']);
        $this->assertSame(['type' => 'sticker', 'packageId' => '446', 'stickerId' => '1988'], $pushed[1]);
        $this->assertSame(['type' => 'location', 'title' => 'Office', 'address' => 'Office', 'latitude' => 13.75, 'longitude' => 100.5], $pushed[2]);
        $this->assertStringStartsWith("https://hooks.example.test/hooks/files/{$video->ref_id}?", $pushed[3]['originalContentUrl']);
        $this->assertStringStartsWith("https://hooks.example.test/hooks/files/{$cover->ref_id}?", $pushed[3]['previewImageUrl']);
        $this->assertSame(['Yes, go', 'No'], array_column($pushed[4]['template']['actions'], 'label'));
        $this->assertSame(['flex', 'Your order', 'For U9'], [$pushed[5]['type'], $pushed[5]['altText'], $pushed[5]['contents']['body']['contents'][0]['text']]);

        // To many, each once; to everyone, no one named; both keyed so LINE never doubles them
        $this->assertSame(['U1', 'U2'], $sent('multicast')->sole()['to']);
        $this->assertFalse(isset($sent('broadcast')->sole()['to']));
        $this->assertTrue($sent('multicast')->sole()->hasHeader('X-Line-Retry-Key') && $sent('broadcast')->sole()->hasHeader('X-Line-Retry-Key'));

        $asked = $this->asked('api.line.me');
        $this->assertSame(['U9', 15], [$asked['start']['chatId'], $asked['start']['loadingSeconds']]);
        $this->assertSame('read-9', $asked['markAsRead']['markAsReadToken']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/group/C1/leave'));

        $this->assertSame(['limited' => true, 'limit' => 200], $this->step($run, 'quota')->output);
        $this->assertSame(12, $this->step($run, 'used')->output['sent']);
        $this->assertSame('Team', $this->step($run, 'group')->output['name']);
        $this->assertSame(5, $this->step($run, 'count')->output['count']);
    }

    // --- LINE's rich menus ---

    /**
     * LINE as far as rich menus go: it makes one, keeps the short names
     * given, and says which menu everyone sees.
     *
     * @param  array<string, string>  $aliases  the short names it has, by reference
     */
    private function fakeRichMenus(string $made, array &$aliases, int $upload = 200): void
    {
        Http::fake([
            'api-data.line.me/*' => Http::response($upload === 200 ? [] : ['message' => 'The request body has 1 error(s)', 'details' => [['message' => 'image size must match richmenu size']]], $upload),
            'api.line.me/*' => function (Request $request) use ($made, &$aliases) {
                $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/v2/bot/'));
                $does = "{$request->method()} {$path}";

                if (str_starts_with($does, 'GET richmenu/alias/') && $path !== 'richmenu/alias/list') {
                    $alias = basename($path);

                    return isset($aliases[$alias]) ? Http::response(['richMenuAliasId' => $alias, 'richMenuId' => $aliases[$alias]]) : Http::response(['message' => 'richmenu alias not found'], 404);
                }

                if (str_starts_with($does, 'POST richmenu/alias')) {
                    $aliases[$request['richMenuAliasId'] ?? basename($path)] = $request['richMenuId'];
                }

                return match ($does) {
                    'POST richmenu' => Http::response(['richMenuId' => $made]),
                    'GET richmenu/list' => Http::response(['richmenus' => [['richMenuId' => $made, 'name' => 'Main', 'chatBarText' => 'Open me', 'size' => ['width' => 1200, 'height' => 810], 'areas' => [[], [], []]]]]),
                    'GET richmenu/alias/list' => Http::response(['aliases' => collect($aliases)->map(fn (string $menu, string $alias) => ['richMenuAliasId' => $alias, 'richMenuId' => $menu])->values()->all()]),
                    'GET user/all/richmenu' => Http::response(['richMenuId' => $made]),
                    'GET user/U9/richmenu' => Http::response(['message' => 'the user has no richmenu'], 404),
                    default => Http::response([]),
                };
            },
        ]);
    }

    public function test_a_rich_menu_is_made_the_size_of_its_picture_named_and_shown()
    {
        $made = 'richmenu-'.str_repeat('ab', 16);
        $other = 'richmenu-'.str_repeat('cd', 16);
        $aliases = [];
        $this->fakeRichMenus($made, $aliases);
        $user = User::factory()->create();
        $channel = $this->channel($user)->ref_id;
        $picture = $this->file($user, 'menu.png', bytes: $this->png(1200, 810));
        $on = fn (array $settings = []) => ['connection' => $channel, ...$settings];

        $run = $this->runChain($user, [
            $this->node('make', 'line.richmenu_make', $on(['name' => 'Main', 'picture' => $picture->ref_id, 'layout' => 'large', 'bar' => 'Open me', 'buttons' => [
                ['name' => 'Order', 'value' => 'https://example.com/order'], ['name' => 'Hours', 'value' => 'postback:hours'], ['name' => 'More', 'value' => 'menu:more'],
            ]])),
            $this->node('name', 'line.richmenu_alias', $on(['alias' => 'main', 'menu' => '{{ node("make").id }}'])),
            // From here on its short name will do for it
            $this->node('everyone', 'line.richmenu_default', $on(['menu' => 'main'])),
            $this->node('one', 'line.richmenu', $on(['menu' => 'main'])),
            $this->node('many', 'line.richmenu_many', $on(['to' => 'U1, U2 U1', 'menu' => 'main'])),
            $this->node('fewer', 'line.richmenu_many_off', $on(['to' => 'U1'])),
            $this->node('whose', 'line.richmenu_of', $on()),
            $this->node('menus', 'line.richmenus', $on()),
            $this->node('again', 'line.richmenu_alias', $on(['alias' => 'main', 'menu' => '{{ node("make").id }}'])),
            $this->node('moved', 'line.richmenu_alias', $on(['alias' => 'main', 'menu' => $other])),
            $this->node('unnamed', 'line.richmenu_alias_off', $on(['alias' => 'old'])),
            $this->node('nomenu', 'line.richmenu_off', $on()),
            $this->node('noone', 'line.richmenu_default_off', $on()),
            $this->node('gone', 'line.richmenu_delete', $on(['menu' => '{{ node("make").id }}'])),
        ], ['sender' => ['id' => 'U9']]);

        $this->assertAllWentThrough($run);
        $sent = fn (string $does) => Http::recorded(fn (Request $request) => "{$request->method()} ".substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/v2/bot/')) === $does)->map(fn (array $pair) => $pair[0])->values();

        // The menu is the picture's size, its buttons where the layout puts them: neighbours meet, and none runs off it
        $menu = $sent('POST richmenu')->sole();
        $this->assertSame([['width' => 1200, 'height' => 810], true, 'Main', 'Open me'], [$menu['size'], $menu['selected'], $menu['name'], $menu['chatBarText']]);
        $this->assertSame([
            ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 810],
            ['x' => 800, 'y' => 0, 'width' => 400, 'height' => 405],
            ['x' => 800, 'y' => 405, 'width' => 400, 'height' => 405],
        ], array_column($menu['areas'], 'bounds'));
        // An address opens, a postback comes back unseen, and a tab turns to another menu by its short name
        $this->assertSame([
            ['type' => 'uri', 'label' => 'Order', 'uri' => 'https://example.com/order'],
            ['type' => 'postback', 'label' => 'Hours', 'data' => 'hours', 'displayText' => 'Hours'],
            ['type' => 'richmenuswitch', 'label' => 'More', 'richMenuAliasId' => 'more', 'data' => 'menu:more'],
        ], array_column($menu['areas'], 'action'));
        $this->assertSame(['id' => $made, 'name' => 'Main', 'width' => 1200, 'height' => 810, 'areas' => 3], $this->step($run, 'make')->output);

        // Its picture goes where LINE takes files, as the bytes it is
        $picture = Http::recorded(fn (Request $request) => str_contains($request->url(), 'api-data.line.me'))->sole()[0];
        $this->assertSame(["https://api-data.line.me/v2/bot/richmenu/{$made}/content", 'image/png', $this->png(1200, 810)], [$picture->url(), $picture->header('Content-Type')[0], $picture->body()]);

        // A name is made once, left alone when it already says so, and moved when it names another
        $this->assertSame(['richMenuAliasId' => 'main', 'richMenuId' => $made], $sent('POST richmenu/alias')->sole()->data());
        $this->assertSame(['richMenuId' => $other], $sent('POST richmenu/alias/main')->sole()->data());
        $this->assertSame([false, false, true], array_map(fn (string $key) => $this->step($run, $key)->output['moved'], ['name', 'again', 'moved']));
        $this->assertCount(1, $sent('DELETE richmenu/alias/old'));

        // Shown to everyone, to one, to many -- each named by the short name, asked for by its id
        $this->assertCount(1, $sent("POST user/all/richmenu/{$made}"));
        $this->assertCount(1, $sent("POST user/U9/richmenu/{$made}"));
        $this->assertSame(['richMenuId' => $made, 'userIds' => ['U1', 'U2']], $sent('POST richmenu/bulk/link')->sole()->data());
        $this->assertSame(['userIds' => ['U1']], $sent('POST richmenu/bulk/unlink')->sole()->data());
        $this->assertCount(1, $sent('DELETE user/U9/richmenu'));
        $this->assertCount(1, $sent('DELETE user/all/richmenu'));
        $this->assertCount(1, $sent("DELETE richmenu/{$made}"));

        // Someone with none of their own has none, which is no failure
        $this->assertNull($this->step($run, 'whose')->output['menu']);
        $this->assertSame([
            'menus' => [['id' => $made, 'name' => 'Main', 'bar' => 'Open me', 'width' => 1200, 'height' => 810, 'areas' => 3, 'aliases' => ['main'], 'default' => true]],
            'count' => 1,
            'default' => $made,
        ], $this->step($run, 'menus')->output);
    }

    public function test_a_rich_menu_that_cant_be_made_says_why_and_leaves_none_behind()
    {
        $made = 'richmenu-'.str_repeat('ab', 16);
        $aliases = [];
        $this->fakeRichMenus($made, $aliases, upload: 400);
        $user = User::factory()->create();
        $channel = $this->channel($user)->ref_id;
        $picture = $this->file($user, 'menu.png', bytes: $this->png(1200, 810))->ref_id;
        $buttons = fn (int $many) => array_map(fn (int $at) => ['name' => "Button {$at}", 'value' => ''], range(1, $many));
        $run = fn (string $type, array $settings) => $this->step($this->runChain($user, [$this->node('it', $type, ['connection' => $channel, ...$settings])], ['sender' => ['id' => 'U9']]), 'it')->error['message'];
        $make = fn (array $settings) => $run('line.richmenu_make', ['name' => 'Main', 'picture' => $picture, 'layout' => 'two', 'buttons' => $buttons(2), ...$settings]);

        $this->assertStringContainsString('"tiny.png" is 1 × 1', $make(['picture' => $this->file($user, 'tiny.png')->ref_id]));
        $this->assertStringContainsString('"tall.png" is 1000 × 800', $make(['picture' => $this->file($user, 'tall.png', bytes: $this->png(1000, 800))->ref_id]));
        $this->assertStringContainsString('is a JPEG or a PNG', $make(['picture' => $this->file($user, 'menu.gif', 'image/gif')->ref_id]));
        $this->assertStringContainsString('at most 2 of these, and there are 3', $make(['buttons' => $buttons(3)]));
        $this->assertStringContainsString('Name at least one button', $make(['buttons' => []]));
        $this->assertStringContainsString('at most 14 letters', $make(['bar' => 'Tap here to open it']));
        $this->assertStringContainsString('JSON list of up to 20', $make(['layout' => 'custom', 'areas' => '{"bounds": {}}']));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v2/bot/richmenu'));

        // LINE turns the picture down: what it said is passed on, and the menu made for it is taken away again
        $this->assertSame('LINE said: The request body has 1 error(s) (image size must match richmenu size)', $make([]));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), "/v2/bot/richmenu/{$made}"));

        // Areas of one's own go as they are written
        $areas = [['bounds' => ['x' => 0, 'y' => 0, 'width' => 1200, 'height' => 810], 'action' => ['type' => 'message', 'text' => 'Hi {{ trigger.sender.id }}']]];
        $make(['layout' => 'custom', 'areas' => json_encode($areas)]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v2/bot/richmenu') && $request['areas'][0]['action']['text'] === 'Hi U9');

        $this->assertStringContainsString('no rich menu with the id or the short name "members"', $run('line.richmenu_default', ['menu' => 'members']));
        $this->assertStringContainsString('"Main Menu" is not', $run('line.richmenu_alias', ['alias' => 'Main Menu', 'menu' => $made]));
        $this->assertStringContainsString('There is no one named', $run('line.richmenu_many', ['to' => ' , ', 'menu' => $made]));
    }

    public function test_the_rich_menu_template_makes_names_and_shows_one_once_given_a_picture()
    {
        $made = 'richmenu-'.str_repeat('ab', 16);
        $aliases = [];
        $this->fakeRichMenus($made, $aliases);
        $user = User::factory()->create();
        $channel = $this->channel($user);

        $this->actingAs($user)->post(route('workflows.store'), ['template' => 'line-menu', 'connections' => ['line' => $channel->ref_id]])->assertRedirect();
        $workflow = $user->workflows()->sole();
        $nodes = collect($workflow->definition['nodes'])->map(fn (array $node) => $node['key'] !== 'make' ? $node : [...$node, 'config' => [...$node['config'], 'picture' => $this->file($user, 'menu.png', bytes: $this->png(2500, 843))->ref_id]]);
        $workflow->update(['definition' => [...$workflow->definition, 'nodes' => $nodes->all()]]);
        $this->assertSame([], References::allProblems($workflow));

        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        $this->assertAllWentThrough($run->fresh());
        // Three side by side, the whole height of a short picture
        $menu = Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v2/bot/richmenu'))->sole()[0];
        $this->assertSame([[0, 833], [833, 834], [1667, 833]], array_map(fn (array $area) => [$area['bounds']['x'], $area['bounds']['width']], $menu['areas']));
        $this->assertSame([843, 843, 843], array_map(fn (array $area) => $area['bounds']['height'], $menu['areas']));
        $this->assertSame(['main' => $made], $aliases);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), "/user/all/richmenu/{$made}"));
    }

    // --- what starts a run ---

    public function test_a_telegram_update_reads_as_its_kind_with_the_parts_it_brings()
    {
        $from = ['id' => 5, 'first_name' => 'Ada'];
        $chat = ['id' => -100, 'type' => 'supergroup', 'title' => 'Team'];
        $read = fn (array $update) => Envelope::telegram(['update_id' => 1, ...$update]);

        $press = $read(['callback_query' => ['id' => 'press-1', 'from' => $from, 'data' => 'yes', 'message' => ['message_id' => 9, 'chat' => $chat, 'photo' => [['file_id' => 'p']]]]]);
        $this->assertSame(['button', 'yes', ['id' => 'press-1', 'data' => 'yes'], 9, []], [$press['type'], $press['text'], $press['button'], $press['reply']['to'], $press['files']]);
        // What a message was is always said, whatever it was
        $this->assertSame(['button', 'location', 'other'], [$press['kind'], $read(['message' => ['message_id' => 2, 'chat' => $chat, 'from' => $from, 'location' => ['latitude' => 1.0, 'longitude' => 2.0]]])['kind'], $read(['poll_answer' => ['poll_id' => 'p', 'user' => $from, 'option_ids' => []]])['kind']]);

        $place = $read(['message' => ['message_id' => 2, 'chat' => $chat, 'from' => $from, 'message_thread_id' => 7, 'venue' => ['title' => 'Office', 'address' => '1 Road', 'location' => ['latitude' => 13.75, 'longitude' => 100.5]]]]);
        $this->assertSame(['message', 7, ['lat' => 13.75, 'lng' => 100.5, 'title' => 'Office', 'address' => '1 Road']], [$place['type'], $place['topic'], $place['location']]);

        $this->assertSame('channel_post', $read(['channel_post' => ['message_id' => 3, 'chat' => $chat, 'text' => 'News']])['type']);
        $joined = $read(['message' => ['message_id' => 4, 'chat' => $chat, 'from' => $from, 'new_chat_members' => [['id' => 6, 'first_name' => 'Bo']]]]);
        $this->assertSame(['joined', 'Bo', false], [$joined['type'], $joined['member']['name'], $joined['member']['bot']]);
        $this->assertSame(['left', true], [($put = $read(['my_chat_member' => ['from' => $from, 'chat' => $chat, 'new_chat_member' => ['status' => 'kicked', 'user' => ['id' => 9, 'is_bot' => true]]]]))['type'], $put['member']['self']]);
        $this->assertSame(['reaction', '👍', 9], [($liked = $read(['message_reaction' => ['chat' => $chat, 'user' => $from, 'message_id' => 9, 'new_reaction' => [['type' => 'emoji', 'emoji' => '👍']]]]))['type'], $liked['text'], $liked['reaction']['message']]);
        $this->assertSame(['poll_answer', [1]], [($chosen = $read(['poll_answer' => ['poll_id' => 'poll-1', 'user' => $from, 'option_ids' => [1]]]))['type'], $chosen['poll']['chosen']]);

        // Outside any chat, it is between the bot and whoever did it
        $asked = $read(['inline_query' => ['id' => 'ask-1', 'from' => $from, 'query' => 'weather']]);
        $this->assertSame(['inline_query', 'weather', 'ask-1', '5'], [$asked['type'], $asked['text'], $asked['inline']['id'], $asked['chat']['id']]);
        $this->assertSame(['checkout', 'pay-1', 500], [($due = $read(['pre_checkout_query' => ['id' => 'pay-1', 'from' => $from, 'currency' => 'XTR', 'total_amount' => 500, 'invoice_payload' => 'order-1']]))['type'], $due['checkout']['id'], $due['checkout']['amount']]);
        $this->assertSame(['payment', 'order-1'], [($paid = $read(['message' => ['message_id' => 5, 'chat' => $chat, 'from' => $from, 'successful_payment' => ['currency' => 'XTR', 'total_amount' => 500, 'invoice_payload' => 'order-1']]]))['type'], $paid['payment']['payload']]);

        $this->assertNull($read(['chat_join_request' => []]));
    }

    public function test_a_trigger_starts_only_on_what_it_is_set_to()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true]), 'api.line.me/*' => Http::response([])]);
        $user = User::factory()->create();
        $bot = $this->bot($user);
        $channel = $this->channel($user);
        $listening = function (string $title, array $trigger) use ($user): Workflow {
            $workflow = Workflow::factory()->for($user)->steps([$trigger, $this->node('log', 'output.log', ['message' => '{{ trigger.type }}'])], [$this->edge('heard', 'log')])->create(['title' => $title]);
            $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
            $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

            return $workflow;
        };
        // One set up before it could choose listens for messages, as it always did
        $messages = $listening('messages', $this->node('heard', 'trigger.telegram', ['connection' => $bot->ref_id, 'command' => '/go']));
        $presses = $listening('presses', $this->node('heard', 'trigger.telegram', ['connection' => $bot->ref_id, 'on' => 'button', 'command' => '/go']));
        $comings = $listening('comings', $this->node('heard', 'trigger.telegram', ['connection' => $bot->ref_id, 'on' => 'member']));
        $said = $listening('said', $this->node('heard', 'trigger.line', ['connection' => $channel->ref_id]));
        $friends = $listening('friends', $this->node('heard', 'trigger.line', ['connection' => $channel->ref_id, 'on' => 'follow']));
        $taps = $listening('taps', $this->node('heard', 'trigger.line', ['connection' => $channel->ref_id, 'on' => 'button']));

        // The bot is told of everything it can be
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook') && $request['allowed_updates'] === Envelope::TELEGRAM_UPDATES);

        $chat = ['id' => 77, 'type' => 'private'];
        $from = ['id' => 77, 'first_name' => 'Ada'];

        foreach ([
            ['message' => ['message_id' => 1, 'text' => '/go now', 'chat' => $chat, 'from' => $from]],
            ['message' => ['message_id' => 2, 'text' => 'hello', 'chat' => $chat, 'from' => $from]],
            ['callback_query' => ['id' => 'press-1', 'from' => $from, 'data' => 'yes', 'message' => ['message_id' => 1, 'chat' => $chat]]],
            ['message' => ['message_id' => 3, 'chat' => $chat, 'from' => $from, 'new_chat_members' => [['id' => 6, 'first_name' => 'Bo']]]],
        ] as $at => $update) {
            $this->postJson("/hooks/telegram/{$bot->ref_id}", ['update_id' => 900 + $at, ...$update], ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();
        }

        $body = (string) json_encode(['events' => [
            ['type' => 'follow', 'webhookEventId' => 'ev1', 'replyToken' => 'rt-1', 'source' => ['type' => 'user', 'userId' => 'U1']],
            ['type' => 'message', 'webhookEventId' => 'ev2', 'replyToken' => 'rt-2', 'source' => ['type' => 'user', 'userId' => 'U1'], 'message' => ['type' => 'sticker', 'id' => 'm2', 'packageId' => '446', 'stickerId' => '1988']],
            ['type' => 'postback', 'webhookEventId' => 'ev3', 'replyToken' => 'rt-3', 'source' => ['type' => 'user', 'userId' => 'U1'], 'postback' => ['data' => 'send:12']],
            ['type' => 'postback', 'webhookEventId' => 'ev4', 'replyToken' => 'rt-4', 'source' => ['type' => 'user', 'userId' => 'U1'], 'postback' => ['data' => 'menu:more', 'params' => ['newRichMenuAliasId' => 'more', 'status' => 'SUCCESS']]],
        ]]);
        $this->call('POST', "/hooks/line/{$channel->ref_id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LINE_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 'line-secret', true))], $body)->assertOk();
        $this->work();

        $heard = fn (Workflow $workflow) => $workflow->runs()->orderBy('id')->get()->map(fn (WorkflowRun $run) => $run->trigger_data['type'])->all();

        // A command is something written; a press has none to match, and a coming is not a message
        $this->assertSame(['message', 'button'], $heard($messages));
        $this->assertSame(['button'], $heard($presses));
        $this->assertSame(['joined'], $heard($comings));
        $this->assertSame(['message', 'postback'], $heard($said));
        $this->assertSame(['follow'], $heard($friends));
        // A rich menu turned to another is a tap, and nothing anyone said
        $this->assertSame(['postback', 'menu'], $heard($taps));
        $this->assertSame(['alias' => 'more', 'turned' => true], $taps->runs()->orderByDesc('id')->first()->trigger_data['menu']);

        // A sticker is not a file to fetch, and says which it is
        $sticker = $said->runs()->orderBy('id')->first()->trigger_data;
        $this->assertSame([[], 'sticker'], [$sticker['files'], $sticker['kind']]);
        $this->assertSame(['id' => '1988', 'package' => '446', 'animated' => false, 'url' => 'https://stickershop.line-scdn.net/stickershop/v1/sticker/1988/android/sticker.png'], $sticker['sticker']);

        // One that moves is shown by its moving picture; Telegram's, by a file it can be shown by
        $moving = Envelope::line(['type' => 'message', 'source' => ['type' => 'user', 'userId' => 'U1'], 'message' => ['type' => 'sticker', 'id' => 'm3', 'packageId' => '11537', 'stickerId' => '52002734', 'stickerResourceType' => 'ANIMATION_SOUND']])['sticker'];
        $this->assertSame([true, 'https://stickershop.line-scdn.net/stickershop/v1/sticker/52002734/android/sticker_animation.png'], [$moving['animated'], $moving['url']]);
        $drawn = Envelope::telegram(['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => $chat, 'from' => $from, 'sticker' => ['file_id' => 'tgs-1', 'is_animated' => true, 'thumbnail' => ['file_id' => 'thumb-1']]]])['sticker'];
        $filmed = Envelope::telegram(['update_id' => 2, 'message' => ['message_id' => 2, 'chat' => $chat, 'from' => $from, 'sticker' => ['file_id' => 'webm-1', 'is_video' => true, 'emoji' => '🎉']]])['sticker'];
        $this->assertSame([true, 'thumb-1', true, 'webm-1'], [$drawn['animated'], $drawn['picture'], $filmed['animated'], $filmed['picture']]);
    }

    // --- LinkedIn ---

    public function test_a_post_on_linkedin_goes_out_as_the_member_with_a_link_or_a_picture()
    {
        Http::fake([
            'api.linkedin.com/v2/assets?action=registerUpload' => Http::response(['value' => [
                'uploadMechanism' => ['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest' => ['uploadUrl' => 'https://api.linkedin.com/mediaUpload/abc/0']],
                'asset' => 'urn:li:digitalmediaAsset:abc',
            ]]),
            'api.linkedin.com/mediaUpload/abc/0' => Http::response('', 201),
            'api.linkedin.com/v2/ugcPosts' => Http::response(['id' => 'urn:li:share:77'], 201, ['X-RestLi-Id' => 'urn:li:share:77']),
        ]);
        $user = User::factory()->create();
        $as = Connection::factory()->linkedin()->for($user)->create()->ref_id;
        $picture = $this->file($user, 'launch.png');

        $run = $this->runChain($user, [
            $this->node('words', 'linkedin.post', ['connection' => $as, 'text' => 'Out today: {{ trigger.what }}']),
            $this->node('card', 'linkedin.post', ['connection' => $as, 'text' => 'Read on', 'with' => 'link', 'link' => 'https://example.com/{{ trigger.what }}', 'title' => 'The news', 'description' => 'All of it', 'visibility' => 'CONNECTIONS']),
            $this->node('shown', 'linkedin.post', ['connection' => $as, 'with' => 'picture', 'picture' => $picture->ref_id]),
        ], ['what' => 'beans']);

        $this->assertAllWentThrough($run);
        $this->assertSame(['id' => 'urn:li:share:77', 'url' => 'https://www.linkedin.com/feed/update/urn:li:share:77/', 'as' => 'Ada Lovelace'], $this->step($run, 'words')->output);

        $posts = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v2/ugcPosts'))->map(fn (array $pair) => $pair[0])->values();
        $content = fn (int $n) => $posts[$n]['specificContent']['com.linkedin.ugc.ShareContent'];

        // As the member who signed in, with their token and never the app's secret
        $this->assertSame(['urn:li:person:abc123', 'PUBLISHED'], [$posts[0]['author'], $posts[0]['lifecycleState']]);
        $this->assertTrue($posts[0]->hasHeader('Authorization', 'Bearer linkedin-token'));
        $this->assertSame(['shareCommentary' => ['text' => 'Out today: beans'], 'shareMediaCategory' => 'NONE'], $content(0));
        $this->assertSame('PUBLIC', $posts[0]['visibility']['com.linkedin.ugc.MemberNetworkVisibility']);

        $this->assertSame(['ARTICLE', [['status' => 'READY', 'originalUrl' => 'https://example.com/beans', 'title' => ['text' => 'The news'], 'description' => ['text' => 'All of it']]]], [$content(1)['shareMediaCategory'], $content(1)['media']]);
        $this->assertSame('CONNECTIONS', $posts[1]['visibility']['com.linkedin.ugc.MemberNetworkVisibility']);

        // A picture is handed over first, as the member's own, and the post carries what LinkedIn named it
        $asked = $this->asked('api.linkedin.com');
        $this->assertSame('urn:li:person:abc123', $asked['assets']['registerUploadRequest']['owner']);
        $this->assertSame(['PUT', base64_decode(self::PNG)], [$asked['0']->method(), $asked['0']->body()]);
        $this->assertSame(['IMAGE', [['status' => 'READY', 'media' => 'urn:li:digitalmediaAsset:abc']]], [$content(2)['shareMediaCategory'], $content(2)['media']]);
    }

    public function test_a_linkedin_post_says_what_is_missing_and_when_its_sign_in_has_run_out()
    {
        Http::fake(['api.linkedin.com/v2/ugcPosts' => Http::response(['message' => 'Content is a duplicate of urn:li:share:77', 'status' => 422], 422)]);
        $user = User::factory()->create();
        $as = Connection::factory()->linkedin()->for($user)->create();
        $run = fn (array $settings, ?Connection $through = null) => $this->step($this->runChain($user, [$this->node('it', 'linkedin.post', ['connection' => ($through ?? $as)->ref_id, ...$settings])], ['n' => 1]), 'it')->error['message'];

        $this->assertSame('The post came to nothing.', $run(['text' => ' ']));
        $this->assertStringContainsString('at most 3,000 letters, and this is 3,001', $run(['text' => str_repeat('a', 3001)]));
        $this->assertStringContainsString('not an address', $run(['with' => 'link', 'link' => 'example.com']));
        $this->assertStringContainsString('JPEG, a PNG or a GIF', $run(['with' => 'picture', 'picture' => $this->file($user, 'notes.pdf', 'application/pdf')->ref_id]));
        // What LinkedIn says is passed on as it said it
        $this->assertSame('LinkedIn said: Content is a duplicate of urn:li:share:77.', $run(['text' => 'Again']));
        Http::assertSentCount(1);

        // Never asked with a sign-in that is over, or one not made yet
        $old = Connection::factory()->linkedin()->for($user)->create(['name' => 'Old']);
        $old->forceFill(['settings' => [...$old->settings, 'expires_at' => now()->subDay()->toIso8601String()]])->save();
        $new = Connection::factory()->for($user)->create(['name' => 'New', 'service' => 'linkedin', 'settings' => ['client_id' => 'c'], 'secrets' => ['client_secret' => 's']]);

        $this->assertStringContainsString('ran out on', $run(['text' => 'Hi'], $old));
        $this->assertStringContainsString('"New" is not signed in to LinkedIn yet', $run(['text' => 'Hi'], $new));
        Http::assertSentCount(1);
    }

    public function test_a_linkedin_post_carries_a_video_several_pictures_a_document_or_a_poll()
    {
        Http::fake([
            'api.linkedin.com/v2/assets?action=registerUpload' => Http::response(['value' => [
                'uploadMechanism' => ['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/vid/0', 'headers' => ['media-type-family' => 'VIDEO']]],
                'asset' => 'urn:li:digitalmediaAsset:vid',
            ]]),
            'api.linkedin.com/rest/images?action=initializeUpload' => Http::sequence()
                ->push(['value' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/img/1', 'image' => 'urn:li:image:1']])
                ->push(['value' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/img/2', 'image' => 'urn:li:image:2']]),
            'api.linkedin.com/rest/documents?action=initializeUpload' => Http::response(['value' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/doc/0', 'document' => 'urn:li:document:9']]),
            'www.linkedin.com/dms-uploads/*' => Http::response('', 201),
            'api.linkedin.com/v2/ugcPosts' => Http::response([], 201, ['X-RestLi-Id' => 'urn:li:ugcPost:70']),
            'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:ugcPost:88']),
        ]);
        $user = User::factory()->create();
        $as = Connection::factory()->linkedin()->for($user)->create()->ref_id;
        $film = $this->file($user, 'launch.mp4', 'video/mp4');
        $deck = $this->file($user, 'plan.pdf', 'application/pdf');
        $views = [$this->file($user, 'front.png')->ref_id, $this->file($user, 'back.png')->ref_id];

        $run = $this->runChain($user, [
            $this->node('film', 'linkedin.post', ['connection' => $as, 'text' => 'Watch', 'with' => 'video', 'video' => $film->ref_id, 'visibility' => 'CONNECTIONS']),
            $this->node('album', 'linkedin.post', ['connection' => $as, 'text' => 'Two (2) views #launch', 'with' => 'pictures', 'pictures' => implode(', ', $views)]),
            $this->node('deck', 'linkedin.post', ['connection' => $as, 'with' => 'document', 'document' => $deck->ref_id, 'visibility' => 'CONNECTIONS']),
            $this->node('ask', 'linkedin.post', ['connection' => $as, 'text' => 'Tell us', 'with' => 'poll', 'question' => 'Which next?', 'answers' => "Maps\nWorkflows\n\nChat", 'lasts' => 'SEVEN_DAYS']),
        ], ['n' => 1]);

        $this->assertAllWentThrough($run);
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:ugcPost:88/', $this->step($run, 'ask')->output['url']);

        // A video goes by the older call; where it is put is its own key
        $old = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v2/ugcPosts'))->sole()[0];
        $this->assertSame(['CONNECTIONS', 'VIDEO', 'urn:li:digitalmediaAsset:vid'], [
            $old['visibility']['com.linkedin.ugc.MemberNetworkVisibility'],
            $old['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'], $old['specificContent']['com.linkedin.ugc.ShareContent']['media'][0]['media'],
        ]);
        $put = fn (string $where) => Http::recorded(fn (Request $request) => str_ends_with($request->url(), "/dms-uploads/{$where}"))->sole()[0];
        $this->assertSame(['PUT', false, 'VIDEO'], [$put('vid/0')->method(), $put('vid/0')->hasHeader('Authorization'), $put('vid/0')->header('media-type-family')[0]]);
        $this->assertTrue($put('img/1')->hasHeader('Authorization', 'Bearer linkedin-token'));

        // The rest go by the newer call, asked for by its month, its words written so their marks stand for themselves
        $new = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/rest/posts'))->map(fn (array $pair) => $pair[0])->values();
        $this->assertTrue($new[0]->hasHeader('LinkedIn-Version', '202609'));
        $this->assertSame(['urn:li:person:abc123', 'Two \\(2\\) views #launch', 'PUBLIC', 'PUBLISHED'], [$new[0]['author'], $new[0]['commentary'], $new[0]['visibility'], $new[0]['lifecycleState']]);
        $this->assertSame(['multiImage' => ['images' => [['id' => 'urn:li:image:1'], ['id' => 'urn:li:image:2']]]], $new[0]['content']);
        $this->assertSame([['media' => ['title' => 'plan.pdf', 'id' => 'urn:li:document:9']], 'CONNECTIONS'], [$new[1]['content'], $new[1]['visibility']]);
        $this->assertSame(['poll' => ['question' => 'Which next?', 'options' => [['text' => 'Maps'], ['text' => 'Workflows'], ['text' => 'Chat']], 'settings' => ['duration' => 'SEVEN_DAYS']]], $new[2]['content']);
    }

    public function test_each_other_linkedin_call_is_a_step_and_a_post_is_named_by_its_id_or_its_link()
    {
        Http::fake(fn (Request $request) => match (true) {
            str_ends_with($request->url(), '/rest/posts') => Http::response('', 201, ['x-restli-id' => 'urn:li:share:99']),
            $request->method() === 'POST' && str_ends_with($request->url(), '/comments') => Http::response(['id' => '555', '$URN' => 'urn:li:comment:(urn:li:activity:77,555)'], 201, ['x-restli-id' => '555']),
            str_ends_with($request->url(), '/v2/userinfo') => Http::response(['sub' => 'abc123', 'name' => 'Ada Lovelace', 'given_name' => 'Ada', 'family_name' => 'Lovelace', 'picture' => 'https://media.example/ada.jpg', 'locale' => ['country' => 'GB', 'language' => 'en']]),
            $request->method() === 'DELETE' => Http::response('', 204),
            default => Http::response('', 201),
        });
        $user = User::factory()->create();
        $on = fn (array $settings = []) => ['connection' => Connection::factory()->linkedin()->for($user)->create()->ref_id, ...$settings];

        $run = $this->runChain($user, [
            $this->node('again', 'linkedin.repost', $on(['post' => 'https://www.linkedin.com/feed/update/urn:li:share:77/', 'text' => 'Worth a read'])),
            $this->node('said', 'linkedin.comment', $on(['post' => 'urn:li:share:77', 'text' => 'Well said'])),
            $this->node('liked', 'linkedin.like', $on(['post' => '{{ trigger.post }}'])),
            $this->node('unliked', 'linkedin.unlike', $on(['post' => 'urn:li:share:77'])),
            $this->node('unsaid', 'linkedin.comment_delete', $on(['comment' => '{{ node("said").urn }}'])),
            $this->node('gone', 'linkedin.delete', $on(['post' => '{{ node("again").id }}'])),
            $this->node('who', 'linkedin.me', $on()),
        ], ['post' => 'https://www.linkedin.com/posts/ada_some-words-activity-7123-AbCd']);

        $this->assertAllWentThrough($run);
        $sent = Http::recorded()->map(fn (array $pair) => [$pair[0]->method().' '.rawurldecode(str_replace('https://api.linkedin.com', '', $pair[0]->url())), $pair[0]->data()])->all();
        $me = 'urn:li:person:abc123';

        $this->assertSame(['POST /rest/posts', 'urn:li:share:77', 'Worth a read'], [$sent[0][0], $sent[0][1]['reshareContext']['parent'], $sent[0][1]['commentary']]);
        $this->assertSame(['POST /v2/socialActions/urn:li:share:77/comments', ['actor' => $me, 'message' => ['text' => 'Well said']]], $sent[1]);
        // A post's own page names it in its last words
        $this->assertSame(['POST /v2/socialActions/urn:li:activity:7123/likes', ['actor' => $me, 'object' => 'urn:li:activity:7123']], $sent[2]);
        $this->assertSame("DELETE /v2/socialActions/urn:li:share:77/likes/{$me}?actor={$me}", $sent[3][0]);
        // A comment is taken away by what the step that made it gave out
        $this->assertSame("DELETE /v2/socialActions/urn:li:activity:77/comments/555?actor={$me}", $sent[4][0]);
        $this->assertSame('DELETE /v2/ugcPosts/urn:li:share:99', $sent[5][0]);

        $this->assertSame(['id' => 'urn:li:share:99', 'url' => 'https://www.linkedin.com/feed/update/urn:li:share:99/', 'as' => 'Ada Lovelace', 'of' => 'urn:li:share:77'], $this->step($run, 'again')->output);
        $this->assertSame(['id' => '555', 'urn' => 'urn:li:comment:(urn:li:activity:77,555)', 'post' => 'urn:li:share:77'], $this->step($run, 'said')->output);
        $this->assertSame(['id' => 'abc123', 'name' => 'Ada Lovelace', 'first' => 'Ada', 'last' => 'Lovelace', 'picture' => 'https://media.example/ada.jpg', 'language' => 'en'], $this->step($run, 'who')->output);
    }

    public function test_a_linkedin_step_says_what_is_wrong_before_linkedin_is_asked()
    {
        Http::fake();
        $user = User::factory()->create();
        $as = Connection::factory()->linkedin()->for($user)->create()->ref_id;
        $one = $this->file($user, 'front.png')->ref_id;
        $run = fn (string $type, array $settings) => $this->step($this->runChain($user, [$this->node('it', $type, ['connection' => $as, ...$settings])], ['n' => 1]), 'it')->error['message'];

        $this->assertStringContainsString('from 2 to 20, and these are 1', $run('linkedin.post', ['with' => 'pictures', 'pictures' => $one]));
        $this->assertStringContainsString('not a video', $run('linkedin.post', ['with' => 'video', 'video' => $one]));
        $this->assertStringContainsString('a PDF, a Word file or a PowerPoint', $run('linkedin.post', ['with' => 'document', 'document' => $one]));
        $this->assertStringContainsString('from 2 to 4 answers', $run('linkedin.post', ['with' => 'poll', 'question' => 'Well?', 'answers' => 'Yes']));
        $this->assertStringContainsString('at most 30 letters', $run('linkedin.post', ['with' => 'poll', 'question' => 'Well?', 'answers' => "Yes\n".str_repeat('n', 31)]));
        $this->assertStringContainsString('not a LinkedIn post', $run('linkedin.like', ['post' => 'the one from Tuesday']));
        $this->assertStringContainsString('not a LinkedIn comment', $run('linkedin.comment_delete', ['comment' => 'that one']));
        Http::assertNothingSent();
    }

    // --- Facebook ---

    public function test_a_facebook_page_posts_comments_likes_deletes_and_reads_as_the_page()
    {
        Http::fake(fn (Request $request) => match (true) {
            str_ends_with($request->url(), '/1001/photos') => Http::response(['id' => 'photo-'.Http::recorded()->count(), 'post_id' => '1001_77']),
            str_contains($request->url(), 'graph-video.facebook.com') => Http::response(['id' => '9001']),
            str_ends_with($request->url(), '/1001/feed') && $request->method() === 'POST' => Http::response(['id' => '1001_55']),
            str_contains($request->url(), '/1001/feed') => Http::response(['data' => [['id' => '1001_55', 'message' => 'Out today', 'created_time' => '2026-10-12T09:00:00+0000', 'permalink_url' => 'https://www.facebook.com/1001/posts/55']]]),
            str_contains($request->url(), '/1001_55/comments') && $request->method() === 'GET' => Http::response(['data' => [['id' => '55_1', 'message' => 'Nice', 'created_time' => '2026-10-12T10:00:00+0000', 'from' => ['id' => '7', 'name' => 'Bo']]]]),
            str_ends_with($request->url(), '/comments') => Http::response(['id' => '55_2']),
            $request->method() === 'GET' => Http::response(['id' => '1001', 'name' => 'Shop', 'link' => 'https://www.facebook.com/shop', 'fan_count' => 12, 'followers_count' => 15, 'picture' => ['data' => ['url' => 'https://example.com/p.jpg']]]),
            default => Http::response(['success' => true]),
        });
        $user = User::factory()->create();
        $page = Connection::factory()->facebook()->for($user)->create()->ref_id;
        $on = fn (array $settings = []) => ['connection' => $page, ...$settings];
        $front = $this->file($user, 'front.png')->ref_id;

        $run = $this->runChain($user, [
            $this->node('words', 'facebook.post', $on(['text' => 'Out today', 'with' => 'link', 'link' => 'https://example.com/news'])),
            $this->node('shown', 'facebook.post', $on(['text' => 'Look', 'with' => 'picture', 'picture' => $front])),
            $this->node('album', 'facebook.post', $on(['text' => 'Both sides', 'with' => 'pictures', 'pictures' => "{$front}, ".$this->file($user, 'back.png')->ref_id])),
            $this->node('film', 'facebook.post', $on(['text' => 'Watch', 'with' => 'video', 'video' => $this->file($user, 'clip.mp4', 'video/mp4')->ref_id, 'title' => 'Launch'])),
            $this->node('said', 'facebook.comment', $on(['post' => '{{ node("words").id }}', 'text' => 'Thanks all'])),
            $this->node('liked', 'facebook.like', $on(['post' => '{{ node("words").id }}'])),
            $this->node('unliked', 'facebook.unlike', $on(['post' => '{{ node("words").url }}'])),
            $this->node('posts', 'facebook.posts', $on(['count' => 5])),
            $this->node('heard', 'facebook.comments', $on(['post' => '{{ node("posts").first.id }}'])),
            $this->node('who', 'facebook.me', $on()),
            $this->node('gone', 'facebook.delete', $on(['post' => '{{ node("said").id }}'])),
        ], ['n' => 1]);

        $this->assertAllWentThrough($run);
        $sent = Http::recorded()->map(fn (array $pair) => [$pair[0]->method().' '.preg_replace('#^https://graph(-video)?\.facebook\.com/v25\.0#', '$1', strtok($pair[0]->url(), '?')), $pair[0]])->all();
        $calls = array_column($sent, 0);

        // Each goes to the Page's own call for it, as the Page
        $this->assertSame(['POST /1001/feed', 'POST /1001/photos', 'POST /1001/photos', 'POST /1001/photos', 'POST /1001/feed', 'POST -video/1001/videos'], array_slice($calls, 0, 6));
        $this->assertTrue($sent[0][1]->hasHeader('Authorization', 'Bearer page-token'));
        $this->assertSame(['message' => 'Out today', 'link' => 'https://example.com/news'], $sent[0][1]->data());
        // Several pictures are handed over unseen, then shown by one post
        $this->assertStringContainsString('name="published"', $sent[2][1]->body());
        $this->assertSame(['Both sides', [['media_fbid' => 'photo-2'], ['media_fbid' => 'photo-3']]], [$sent[4][1]['message'], $sent[4][1]['attached_media']]);
        $this->assertSame(['POST /1001_55/comments', 'POST /1001_55/likes', 'DELETE /1001_55/likes', 'GET /1001/feed', 'GET /1001_55/comments', 'GET /1001', 'DELETE /55_2'], array_slice($calls, 6));

        $this->assertSame(['id' => '1001_55', 'url' => 'https://www.facebook.com/1001_55', 'as' => 'Shop'], $this->step($run, 'words')->output);
        $this->assertSame(['1001_77', 'https://www.facebook.com/1001/videos/9001'], [$this->step($run, 'shown')->output['id'], $this->step($run, 'film')->output['url']]);
        $this->assertSame([['id' => '55_1', 'text' => 'Nice', 'at' => '2026-10-12T10:00:00+0000', 'from' => ['id' => '7', 'name' => 'Bo']]], $this->step($run, 'heard')->output['comments']);
        $this->assertSame(['Shop', 15, 'https://example.com/p.jpg'], array_values(array_intersect_key($this->step($run, 'who')->output, array_flip(['name', 'followers', 'picture']))));
    }

    // --- Instagram and Threads ---

    public function test_instagram_is_handed_a_post_to_fetch_and_publishes_it_once_it_has_worked_through_it()
    {
        $asked = 0;
        Http::fake(function (Request $request) use (&$asked) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/1789/media') && $request->method() === 'POST' => Http::response(['id' => 'box-'.(Http::recorded(fn (Request $made) => str_ends_with((string) parse_url($made->url(), PHP_URL_PATH), '/1789/media'))->count() + 1)]),
                str_ends_with($path, '/1789/media_publish') => Http::response(['id' => '17900']),
                // The reel's video is still being worked through the first time it is asked after
                str_contains($path, '/box-') => Http::response(['status_code' => str_ends_with($path, '/box-5') && $asked++ === 0 ? 'IN_PROGRESS' : 'FINISHED']),
                str_ends_with($path, '/17900') => Http::response(['permalink' => 'https://www.instagram.com/p/AbC/']),
                str_ends_with($path, '/17900/comments') && $request->method() === 'GET' => Http::response(['data' => [['id' => 'c1', 'text' => 'Love it', 'username' => 'bo', 'timestamp' => '2026-10-12T10:00:00+0000', 'like_count' => 2]]]),
                str_ends_with($path, '/1789/media') => Http::response(['data' => [['id' => '17900', 'caption' => 'New in', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'permalink' => 'https://www.instagram.com/p/AbC/', 'timestamp' => '2026-10-12T09:00:00+0000', 'like_count' => 4, 'comments_count' => 1]]]),
                str_ends_with($path, '/me') => Http::response(['user_id' => '1789', 'username' => 'shop', 'account_type' => 'BUSINESS', 'followers_count' => 120, 'media_count' => 9]),
                $request->method() === 'DELETE' => Http::response(['success' => true]),
                default => Http::response(['id' => 'c2']),
            };
        });
        $user = User::factory()->create();
        $as = Connection::factory()->instagram()->for($user)->create()->ref_id;
        $on = fn (array $settings = []) => ['connection' => $as, ...$settings];
        $photo = $this->file($user, 'front.jpg', 'image/jpeg');
        $clip = $this->file($user, 'clip.mp4', 'video/mp4');

        $run = $this->runChain($user, [
            $this->node('shown', 'instagram.post', $on(['what' => 'picture', 'picture' => $photo->ref_id, 'caption' => 'New in #shop'])),
            $this->node('swipe', 'instagram.post', $on(['what' => 'several', 'files' => "{$photo->ref_id}, {$clip->ref_id}", 'caption' => 'Both'])),
            $this->node('reel', 'instagram.post', $on(['what' => 'reel', 'video' => $clip->ref_id])),
        ], ['n' => 1]);

        // The reel waits, holding no worker, and carries on when asked again
        $this->assertSame([RunStatus::Waiting, NodeStatus::Waiting, 'processing'], [$run->status, $this->step($run, 'reel')->status, $this->step($run, 'reel')->waiting_for]);
        $this->travel(15)->seconds();
        $this->work();
        $this->assertAllWentThrough($run->fresh());
        $this->assertSame(['id' => '17900', 'url' => 'https://www.instagram.com/p/AbC/', 'as' => 'shop'], $this->step($run, 'shown')->output);

        $made = Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/1789/media'))->map(fn (array $pair) => $pair[0])->values();
        // Instagram is told where to fetch each from: the app's public address, signed
        $this->assertStringStartsWith('https://hooks.example.test/hooks/files/', $made[0]['image_url']);
        $this->assertSame(['New in #shop', true, 'VIDEO', 'CAROUSEL', 'box-2,box-3', 'REELS'], [$made[0]['caption'], $made[1]['is_carousel_item'], $made[2]['media_type'], $made[3]['media_type'], $made[3]['children'], $made[4]['media_type']]);
        $this->assertSame(['box-1', 'box-4', 'box-5'], Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/media_publish'))->map(fn (array $pair) => $pair[0]['creation_id'])->values()->all());
        // Only once: what was made while it waited is what is published
        $this->assertCount(5, $made);

        $run = $this->runChain($user, [
            $this->node('posts', 'instagram.posts', $on()),
            $this->node('heard', 'instagram.comments', $on(['post' => '{{ node("posts").first.id }}'])),
            $this->node('said', 'instagram.comment', $on(['post' => '{{ node("posts").first.id }}', 'text' => 'Thanks'])),
            $this->node('back', 'instagram.reply', $on(['comment' => '{{ item(node("heard").comments, 1).id }}', 'text' => 'Glad'])),
            $this->node('gone', 'instagram.comment_delete', $on(['comment' => '{{ node("said").id }}'])),
            $this->node('who', 'instagram.me', $on()),
        ], ['n' => 1]);

        $this->assertAllWentThrough($run);
        $this->assertSame(['REELS', 4], [$this->step($run, 'posts')->output['first']['kind'], $this->step($run, 'posts')->output['first']['likes']]);
        $this->assertSame(['bo', 'Love it'], [$this->step($run, 'heard')->output['comments'][0]['from'], $this->step($run, 'heard')->output['comments'][0]['text']]);
        $calls = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.str_replace('https://graph.instagram.com/v25.0', '', strtok($pair[0]->url(), '?')))->all();
        $this->assertSame(['POST /17900/comments', 'POST /c1/replies', 'DELETE /c2', 'GET /me'], array_slice($calls, -4));
        $this->assertSame(['shop', 'BUSINESS', 120], array_values(array_intersect_key($this->step($run, 'who')->output, array_flip(['username', 'kind', 'followers']))));

        // A picture Instagram would not take is said before it is asked
        $before = Http::recorded()->count();
        $png = $this->step($this->runChain($user, [$this->node('it', 'instagram.post', $on(['picture' => $this->file($user, 'front.png')->ref_id]))], ['n' => 1]), 'it');
        $this->assertStringContainsString('only a JPEG', $png->error['message']);
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_instagram_makes_a_post_of_several_once_each_is_worked_through_and_a_video_story_is_a_story()
    {
        $asked = 0;
        Http::fake(function (Request $request) use (&$asked) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/1789/media') => Http::response(['id' => 'box-'.(Http::recorded(fn (Request $made) => str_ends_with((string) parse_url($made->url(), PHP_URL_PATH), '/1789/media'))->count() + 1)]),
                str_ends_with($path, '/1789/media_publish') => Http::response(['id' => '17900']),
                // The video among the several is still being worked through the first time it is asked after
                str_contains($path, '/box-') => Http::response(['status_code' => str_ends_with($path, '/box-2') && $asked++ === 0 ? 'IN_PROGRESS' : 'FINISHED']),
                default => Http::response(['permalink' => 'https://www.instagram.com/p/AbC/']),
            };
        });
        $user = User::factory()->create();
        $as = Connection::factory()->instagram()->for($user)->create()->ref_id;
        $photo = $this->file($user, 'front.jpg', 'image/jpeg');
        $clip = $this->file($user, 'clip.mp4', 'video/mp4');
        $made = fn () => Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/1789/media'))->map(fn (array $pair) => $pair[0])->values();

        $run = $this->runChain($user, [$this->node('swipe', 'instagram.post', ['connection' => $as, 'what' => 'several', 'files' => "{$photo->ref_id}, {$clip->ref_id}", 'caption' => 'Both'])], ['n' => 1]);

        // Instagram makes no post of parts it is still working through: the step waits for them first
        $this->assertSame([NodeStatus::Waiting, 'processing'], [$this->step($run, 'swipe')->status, $this->step($run, 'swipe')->waiting_for]);
        $this->assertCount(2, $made());
        $this->travel(15)->seconds();
        $this->work();
        $this->assertAllWentThrough($run->fresh());
        // The parts made before it waited are the ones the post holds: none is handed over twice
        $this->assertSame([3, 'CAROUSEL', 'box-1,box-2'], [$made()->count(), $made()[2]['media_type'], $made()[2]['children']]);

        $run = $this->runChain($user, [$this->node('story', 'instagram.post', ['connection' => $as, 'what' => 'story', 'story' => $clip->ref_id])], ['n' => 1]);

        $this->assertAllWentThrough($run);
        // A video in a story is still a story
        $this->assertSame('STORIES', $made()[3]['media_type']);
        $this->assertStringStartsWith('https://hooks.example.test/hooks/files/', $made()[3]['video_url']);
    }

    public function test_instagram_signed_in_by_facebook_is_asked_at_facebooks_address_and_only_there_is_a_post_deleted()
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                ! str_starts_with($request->url(), 'https://graph.facebook.com/v25.0/') => Http::response(['error' => ['message' => 'Asked at the wrong address']], 400),
                str_ends_with($path, '/1789/media') && $request->method() === 'POST' => Http::response(['id' => 'box-1']),
                str_ends_with($path, '/1789/media_publish') => Http::response(['id' => '17900']),
                str_ends_with($path, '/box-1') => Http::response(['status_code' => 'FINISHED']),
                str_ends_with($path, '/1789/media') => Http::response(['data' => [['id' => '17900', 'caption' => 'New in', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED']]]),
                str_ends_with($path, '/17900/comments') => Http::response(['id' => 'c2']),
                str_ends_with($path, '/1789') => Http::response(['id' => '1789', 'username' => 'shop', 'followers_count' => 120]),
                $request->method() === 'DELETE' => Http::response(['success' => true]),
                default => Http::response(['permalink' => 'https://www.instagram.com/p/AbC/']),
            };
        });
        $user = User::factory()->create();
        $byFacebook = Connection::factory()->instagram()->for($user)->create(['settings' => ['by' => 'facebook', 'via' => 'facebook', 'client_id' => 'app-1', 'member' => '1789', 'member_name' => 'shop'], 'secrets' => ['client_secret' => 'app-secret', 'access_token' => 'page-token']]);
        $on = fn (array $settings = []) => ['connection' => $byFacebook->ref_id, ...$settings];

        $run = $this->runChain($user, [
            $this->node('shown', 'instagram.post', $on(['what' => 'picture', 'picture' => $this->file($user, 'front.jpg', 'image/jpeg')->ref_id, 'caption' => 'New in'])),
            $this->node('posts', 'instagram.posts', $on()),
            $this->node('said', 'instagram.comment', $on(['post' => '{{ node("shown").id }}', 'text' => 'Thanks'])),
            $this->node('who', 'instagram.me', $on()),
            $this->node('gone', 'instagram.delete', $on(['post' => '{{ node("shown").id }}'])),
        ], ['n' => 1]);

        // Every call went to Facebook's address, as the account the Page is linked to and with the Page's token
        $this->assertAllWentThrough($run);
        $this->assertSame(['17900', 'https://www.instagram.com/p/AbC/', 'FEED', 'c2', '1789', 'shop', true], [$this->step($run, 'shown')->output['id'], $this->step($run, 'shown')->output['url'], $this->step($run, 'posts')->output['first']['kind'], $this->step($run, 'said')->output['id'], $this->step($run, 'who')->output['id'], $this->step($run, 'who')->output['username'], $this->step($run, 'gone')->output['done']]);
        $calls = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.str_replace('https://graph.facebook.com/v25.0', '', strtok($pair[0]->url(), '?')))->all();
        $this->assertSame(['GET /1789/media', 'POST /17900/comments', 'GET /1789', 'DELETE /17900'], array_slice($calls, -4));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->hasHeader('Authorization', 'Bearer page-token'));

        // Signed in with Instagram there is no taking a post down: said before anything is asked
        $own = Connection::factory()->instagram()->for($user)->create(['name' => 'Own login']);
        $before = Http::recorded()->count();
        $kept = $this->step($this->runChain($user, [$this->node('gone', 'instagram.delete', ['connection' => $own->ref_id, 'post' => '17900'])], ['n' => 1]), 'gone');
        $this->assertStringContainsString('"Own login" signs in with Instagram: set "Sign in with" to Facebook', $kept->error['message']);
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_threads_posts_replies_reposts_deletes_and_reads()
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/2456/threads') && $request->method() === 'POST' => Http::response(['id' => 'box-'.(Http::recorded(fn (Request $made) => $made->method() === 'POST' && str_ends_with((string) parse_url($made->url(), PHP_URL_PATH), '/2456/threads'))->count() + 1)]),
                str_ends_with($path, '/threads_publish') => Http::response(['id' => '18000']),
                str_contains($path, '/box-') => Http::response(['status' => 'FINISHED']),
                str_ends_with($path, '/repost') => Http::response(['id' => '18001']),
                str_ends_with($path, '/2456/threads') => Http::response(['data' => [['id' => '18000', 'text' => 'Out today', 'username' => 'shop', 'permalink' => 'https://www.threads.net/@shop/post/AbC', 'timestamp' => '2026-10-12T09:00:00+0000']]]),
                str_ends_with($path, '/18000/replies') => Http::response(['data' => [['id' => '18002', 'text' => 'When?', 'username' => 'bo']]]),
                str_ends_with($path, '/me') => Http::response(['id' => '2456', 'username' => 'shop', 'threads_biography' => 'Beans']),
                $request->method() === 'DELETE' => Http::response(['success' => true, 'deleted_id' => '18000']),
                default => Http::response(['permalink' => 'https://www.threads.net/@shop/post/AbC']),
            };
        });
        $user = User::factory()->create();
        $as = Connection::factory()->threads()->for($user)->create()->ref_id;
        $on = fn (array $settings = []) => ['connection' => $as, ...$settings];

        $run = $this->runChain($user, [
            $this->node('words', 'threads.post', $on(['text' => 'Out today', 'with' => 'link', 'link' => 'https://example.com/news'])),
            $this->node('shown', 'threads.post', $on(['text' => 'Look', 'with' => 'picture', 'picture' => $this->file($user, 'front.png')->ref_id])),
            $this->node('under', 'threads.post', $on(['text' => 'More soon', 'reply_to' => '{{ node("words").id }}'])),
            $this->node('again', 'threads.repost', $on(['post' => '{{ node("words").id }}'])),
            $this->node('posts', 'threads.posts', $on()),
            $this->node('heard', 'threads.replies', $on(['post' => '{{ node("posts").first.id }}'])),
            $this->node('who', 'threads.me', $on()),
            $this->node('gone', 'threads.delete', $on(['post' => '{{ node("words").id }}'])),
        ], ['n' => 1]);

        $this->assertAllWentThrough($run);
        $made = Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/2456/threads'))->map(fn (array $pair) => $pair[0])->values();

        $this->assertTrue($made[0]->hasHeader('Authorization', 'Bearer th-token'));
        $this->assertSame(['media_type' => 'TEXT', 'link_attachment' => 'https://example.com/news', 'text' => 'Out today'], $made[0]->data());
        $this->assertSame(['IMAGE', 'Look'], [$made[1]['media_type'], $made[1]['text']]);
        $this->assertStringStartsWith('https://hooks.example.test/hooks/files/', $made[1]['image_url']);
        // Told which post it answers, it is a reply under it
        $this->assertSame(['media_type' => 'TEXT', 'text' => 'More soon', 'reply_to_id' => '18000'], $made[2]->data());
        $this->assertSame(['id' => '18000', 'url' => 'https://www.threads.net/@shop/post/AbC', 'as' => 'shop'], $this->step($run, 'words')->output);

        $calls = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.str_replace('https://graph.threads.net/v1.0', '', strtok($pair[0]->url(), '?')))->all();
        $this->assertSame(['POST /18000/repost', 'GET /2456/threads', 'GET /18000/replies', 'GET /me', 'DELETE /18000'], array_slice($calls, -5));
        $this->assertSame(['When?', 1, 'Beans'], [$this->step($run, 'heard')->output['replies'][0]['text'], $this->step($run, 'heard')->output['count'], $this->step($run, 'who')->output['about']]);

        $long = $this->step($this->runChain($user, [$this->node('it', 'threads.post', $on(['text' => str_repeat('a', 501)]))], ['n' => 1]), 'it');
        $this->assertStringContainsString('at most 500 letters, and this is 501', $long->error['message']);
    }

    public function test_threads_makes_a_post_of_several_once_each_is_worked_through()
    {
        $asked = 0;
        Http::fake(function (Request $request) use (&$asked) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/2456/threads') => Http::response(['id' => 'box-'.(Http::recorded(fn (Request $made) => str_ends_with((string) parse_url($made->url(), PHP_URL_PATH), '/2456/threads'))->count() + 1)]),
                str_ends_with($path, '/threads_publish') => Http::response(['id' => '18000']),
                // The video among the several is still being worked through the first time it is asked after
                str_contains($path, '/box-') => Http::response(['status' => str_ends_with($path, '/box-2') && $asked++ === 0 ? 'IN_PROGRESS' : 'FINISHED']),
                default => Http::response(['permalink' => 'https://www.threads.net/@shop/post/AbC']),
            };
        });
        $user = User::factory()->create();
        $as = Connection::factory()->threads()->for($user)->create()->ref_id;
        $made = fn () => Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/2456/threads'))->map(fn (array $pair) => $pair[0])->values();

        $run = $this->runChain($user, [$this->node('swipe', 'threads.post', ['connection' => $as, 'text' => 'Both', 'with' => 'several', 'files' => $this->file($user, 'front.png')->ref_id.', '.$this->file($user, 'clip.mp4', 'video/mp4')->ref_id])], ['n' => 1]);

        // Each is handed over and asked after before the post that holds them is made
        $this->assertSame([NodeStatus::Waiting, 'processing'], [$this->step($run, 'swipe')->status, $this->step($run, 'swipe')->waiting_for]);
        $this->assertSame(['IMAGE', 'VIDEO'], $made()->map(fn (Request $request) => $request['media_type'])->all());
        $this->travel(15)->seconds();
        $this->work();
        $this->assertAllWentThrough($run->fresh());
        // The parts made before it waited are the ones the post holds
        $this->assertSame([3, 'CAROUSEL', 'box-1,box-2', 'Both'], [$made()->count(), $made()[2]['media_type'], $made()[2]['children'], $made()[2]['text']]);
    }

    public function test_an_instagram_comment_and_a_threads_reply_start_runs_by_metas_webhook()
    {
        config(['services.workflows.public_url' => 'https://hooks.example.test']);
        Http::fake(fn (Request $request) => match (true) {
            str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/me/subscribed_apps') => Http::response(['success' => true]),
            str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/me') => Http::response(['id' => '3941', 'user_id' => '1789']),
            default => Http::response(['id' => 'c9']),
        });
        $user = User::factory()->create();
        $account = Connection::factory()->instagram()->for($user)->create();
        $profile = Connection::factory()->threads()->for($user)->create();
        $listening = function (array $nodes) use ($user): Workflow {
            $workflow = Workflow::factory()->for($user)->steps($nodes, [$this->edge($nodes[0]['id'], $nodes[1]['id'])])->create();
            $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
            $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();
            auth()->logout();

            return $workflow;
        };
        $send = fn (string $hook, array $body, string $secret) => $this->call('POST', $hook, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) json_encode($body), $secret),
        ], (string) json_encode($body));

        // --- Instagram: switched on, the account is subscribed to the Meta app, and known by both its ids
        $comments = $listening([
            $this->node('heard', 'trigger.instagram', ['connection' => $account->ref_id]),
            $this->node('answer', 'instagram.reply', ['connection' => $account->ref_id, 'comment' => '{{ trigger.comment.id }}', 'text' => 'Thanks, {{ trigger.sender.name }}']),
        ]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/me/subscribed_apps') && $request['subscribed_fields'] === 'comments,mentions');
        $this->assertSame('3941', $account->fresh()->setting('scoped_id'));

        $hook = "/hooks/instagram/{$account->ref_id}";
        $said = fn (string $to, string $by, string $id = 'c1') => ['object' => 'instagram', 'entry' => [['id' => $to, 'time' => 1749416383, 'changes' => [['field' => 'comments', 'value' => [
            'id' => $id, 'text' => 'Love it', 'from' => ['id' => '77', 'username' => $by], 'media' => ['id' => '17900', 'media_product_type' => 'FEED'],
        ]]]]]];

        // Meta asks first whether the address is the app's: only the connection's own token is answered
        $this->get($hook.'?hub.mode=subscribe&hub.challenge=1158201444&hub.verify_token='.Meta::verifyToken($account))->assertOk()->assertSee('1158201444');
        $this->get($hook.'?hub.mode=subscribe&hub.challenge=1&hub.verify_token=guess')->assertForbidden();
        // The way back from signing in keeps its own address
        $this->get('/hooks/instagram/signed-in?code=x&state=y')->assertRedirect();

        // Signed with another secret, for another account of the same app, or written by the account itself: nothing starts
        $send($hook, $said('1789', 'bo'), 'someone-elses')->assertForbidden();
        $send($hook, $said('999', 'bo'), 'ig-secret')->assertOk();
        $send($hook, $said('1789', 'Shop'), 'ig-secret')->assertOk();
        $this->assertSame(0, $comments->runs()->count());

        // A comment starts a run, named by either id of the account, and the same one sent again starts nothing more
        $send($hook, $said('1789', 'bo'), 'ig-secret')->assertOk();
        $send($hook, $said('1789', 'bo'), 'ig-secret')->assertOk();
        $send($hook, $said('3941', 'bo', 'c2'), 'ig-secret')->assertOk();
        $this->work();
        $this->assertSame(2, $comments->runs()->count());
        $run = $comments->runs()->oldest('id')->first();
        $this->assertAllWentThrough($run);
        $heard = $run->trigger_data;
        $this->assertSame(['instagram', 'comment', 'comment', 'c1', 'Love it', 'bo'], [$heard['service'], $heard['type'], $heard['kind'], $heard['id'], $heard['text'], $heard['sender']['name']]);
        $this->assertSame([['id' => '17900', 'kind' => 'post', 'title' => null], ['id' => '17900', 'kind' => 'FEED'], ['id' => 'c1', 'parent' => null]], [$heard['chat'], $heard['post'], $heard['comment']]);
        // What it read goes into the answer under that comment
        Http::assertSent(fn (Request $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/c1/replies') && $request['message'] === 'Thanks, bo');

        // A mention is told of by ids alone, and a trigger set to comments does not take it
        $mention = Envelope::instagram('mentions', ['media_id' => '17901', 'comment_id' => 'c7']);
        $this->assertSame(['mention', 'comment', 'c7', '17901', ''], [$mention['type'], $mention['kind'], $mention['id'], $mention['post']['id'], $mention['text']]);
        $this->assertTrue(Envelope::wanted('instagram', 'any', 'mention') && ! Envelope::wanted('instagram', 'comment', 'mention'));
        $this->assertNull(Envelope::instagram('story_insights', []));

        // --- Threads: Meta sends one thing at a time, in a shape of its own
        $replies = $listening([
            $this->node('heard', 'trigger.threads', ['connection' => $profile->ref_id]),
            $this->node('note', 'data.set', ['fields' => [['name' => 'said', 'value' => '{{ trigger.text }}']]]),
        ]);
        $hook = "/hooks/threads/{$profile->ref_id}";
        $replied = fn (string $to, string $by, string $field = 'replies') => ['app_id' => '1', 'topic' => 'moderate', 'target_id' => $to, 'time' => 1749416383, 'subscription_id' => '2', 'values' => ['field' => $field, 'value' => [
            'id' => '18002', 'username' => $by, 'text' => 'When?', 'media_type' => 'TEXT_POST', 'permalink' => 'https://www.threads.net/@bo/post/AbC',
            'replied_to' => ['id' => '18000'], 'root_post' => ['id' => '18000', 'owner_id' => '2456', 'username' => 'shop.th'], 'timestamp' => '2026-10-12T10:00:00+0000',
        ]]];

        $this->get($hook.'?hub.mode=subscribe&hub.challenge=42&hub.verify_token='.Meta::verifyToken($profile))->assertOk()->assertSee('42');
        $this->assertNotSame(Meta::verifyToken($profile), Meta::verifyToken($account));
        $send($hook, $replied('2456', 'bo'), 'guess')->assertForbidden();
        // Another profile of the same app, the profile's own reply, or a post published when only replies are waited for
        $send($hook, $replied('999', 'bo'), 'th-secret')->assertOk();
        $send($hook, $replied('2456', 'Shop'), 'th-secret')->assertOk();
        $send($hook, $replied('2456', 'shop', 'publish'), 'th-secret')->assertOk();
        $this->assertSame(0, $replies->runs()->count());

        $send($hook, $replied('2456', 'bo'), 'th-secret')->assertOk();
        $this->work();
        $heard = $replies->runs()->sole()->trigger_data;
        $this->assertSame(['threads', 'reply', 'text_post', '18002', 'When?', 'bo', 'https://www.threads.net/@bo/post/AbC'], [$heard['service'], $heard['type'], $heard['kind'], $heard['id'], $heard['text'], $heard['sender']['name'], $heard['url']]);
        $this->assertSame([['id' => '18000', 'kind' => 'post', 'title' => null], ['to' => '18002', 'quote' => '18000'], '2026-10-12T10:00:00.000+00:00'], [$heard['chat'], $heard['reply'], $heard['at']]);
    }

    public function test_an_instagram_message_starts_a_run_and_each_message_call_is_a_step()
    {
        $path = fn (Request $request) => (string) parse_url($request->url(), PHP_URL_PATH);
        Http::fake(fn (Request $request) => match (true) {
            str_ends_with($path($request), '/me/subscribed_apps') => Http::response(['success' => true]),
            str_ends_with($path($request), '/me/messages') => Http::response(['recipient_id' => '1339', 'message_id' => 'mid.out']),
            str_ends_with($path($request), '/me') => Http::response(['id' => '3941', 'user_id' => '1789']),
            str_ends_with($path($request), '/1339') => Http::response(['id' => '1339', 'name' => 'Bo B', 'username' => 'bo', 'profile_pic' => 'https://cdn.example.test/bo.jpg', 'follower_count' => 12, 'is_verified_user' => false, 'is_user_follow_business' => true, 'is_business_follow_user' => false]),
            default => Http::response(['error' => ['message' => 'Not faked']], 400),
        });
        $user = User::factory()->create();
        $account = Connection::factory()->instagram()->for($user)->create();
        $on = fn (array $settings = []) => ['connection' => $account->ref_id, ...$settings];
        $listening = function (array $nodes) use ($user): Workflow {
            $workflow = Workflow::factory()->for($user)->steps($nodes, array_map(fn (int $at) => $this->edge($nodes[$at - 1]['id'], $nodes[$at]['id']), range(1, count($nodes) - 1)))->create();
            $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
            $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();
            auth()->logout();

            return $workflow;
        };
        $subscribed = fn () => Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($path($request), '/me/subscribed_apps'))->last()[0]['subscribed_fields'];

        $workflow = $listening([
            $this->node('heard', 'trigger.instagram_message', $on()),
            $this->node('who', 'instagram.profile', $on()),
            $this->node('say', 'instagram.send', $on(['text' => 'Hi {{ node("who").name }}: "{{ trigger.text }}"', 'buttons' => [['name' => 'Yes', 'value' => 'ok'], ['name' => 'No', 'value' => '']]])),
            $this->node('show', 'instagram.send_file', $on(['file' => $this->file($user, 'front.jpg', 'image/jpeg')->ref_id])),
            $this->node('heart', 'instagram.react', $on()),
        ]);
        // The account is subscribed to what listens and no more: messages are a permission of their own
        $this->assertSame('messages,messaging_postbacks,message_reactions', $subscribed());
        $comments = $listening([$this->node('heard', 'trigger.instagram', $on(['on' => 'any'])), $this->node('note', 'data.set', ['fields' => [['name' => 'said', 'value' => '{{ trigger.text }}']]])]);
        $this->assertSame('comments,mentions,messages,messaging_postbacks,message_reactions', $subscribed());

        $hook = "/hooks/instagram/{$account->ref_id}";
        $said = fn (string $to, array $event) => ['object' => 'instagram', 'entry' => [['id' => $to, 'time' => 1749416383000, 'messaging' => [['sender' => ['id' => '1339'], 'recipient' => ['id' => $to], 'timestamp' => 1749416383000, ...$event]]]]];
        $send = fn (array $body, string $secret = 'ig-secret') => $this->call('POST', $hook, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) json_encode($body), $secret),
        ], (string) json_encode($body));
        $words = ['message' => ['mid' => 'mid.in', 'text' => 'Open today?']];

        // Signed with another secret, for another account, the account's own message told back, or one only seen: nothing starts
        $send($said('1789', $words), 'someone-elses')->assertForbidden();
        $send($said('999', $words))->assertOk();
        $send($said('1789', ['message' => ['mid' => 'mid.out', 'text' => 'Hi', 'is_echo' => true]]))->assertOk();
        $send($said('1789', ['sender' => ['id' => '1789'], 'message' => ['mid' => 'mid.own', 'text' => 'Hi']]))->assertOk();
        $send($said('1789', ['read' => ['mid' => 'mid.out']]))->assertOk();
        $this->assertSame(0, $workflow->runs()->count());

        // A message starts a run, and the same one sent again starts nothing more
        $send($said('1789', $words))->assertOk();
        $send($said('1789', $words))->assertOk();
        $this->work();
        $run = $workflow->runs()->sole();
        $this->assertAllWentThrough($run);
        // A trigger for comments, set to take any of them, takes no message
        $this->assertSame(0, $comments->runs()->count());

        $heard = $run->trigger_data;
        $this->assertSame(['instagram', 'message', 'text', 'mid.in', 'Open today?', []], [$heard['service'], $heard['type'], $heard['kind'], $heard['id'], $heard['text'], $heard['files']]);
        $this->assertSame([['id' => '1339', 'kind' => 'private', 'title' => null], ['id' => '1339', 'name' => null], ['to' => 'mid.in', 'quote' => null], '2025-06-08T20:59:43.000+00:00'], [$heard['chat'], $heard['sender'], $heard['reply'], $heard['at']]);
        $this->assertSame(['bo', 'Bo B', true, false], [$this->step($run, 'who')->output['username'], $this->step($run, 'who')->output['name'], $this->step($run, 'who')->output['follows'], $this->step($run, 'who')->output['followed']]);
        $this->assertSame(['id' => 'mid.out', 'to' => '1339'], $this->step($run, 'say')->output);

        $sent = Http::recorded(fn (Request $request) => str_ends_with($path($request), '/me/messages'))->map(fn (array $pair) => $pair[0])->values();
        $this->assertTrue($sent[0]->hasHeader('Authorization', 'Bearer ig-token') && str_starts_with($sent[0]->url(), 'https://graph.instagram.com/'));
        // Words with quick answers under them, to whoever wrote
        $this->assertSame(['recipient' => ['id' => '1339'], 'message' => ['text' => 'Hi Bo B: "Open today?"', 'quick_replies' => [['content_type' => 'text', 'title' => 'Yes', 'payload' => 'ok'], ['content_type' => 'text', 'title' => 'No', 'payload' => 'No']]]], $sent[0]->data());
        // A picture is not sent: Instagram is told where to fetch it, at the app's public address
        $this->assertSame(['image', true], [$sent[1]['message']['attachment']['type'], str_starts_with($sent[1]['message']['attachment']['payload']['url'], 'https://hooks.example.test/hooks/files/')]);
        $this->assertSame(['recipient' => ['id' => '1339'], 'sender_action' => 'react', 'payload' => ['message_id' => 'mid.in', 'reaction' => 'love']], $sent[2]->data());

        // The Meta app's own test sends a message as a change, like a comment
        $send(['object' => 'instagram', 'entry' => [['id' => '3941', 'time' => 1, 'changes' => [['field' => 'messages', 'value' => ['sender' => ['id' => '12334'], 'recipient' => ['id' => '3941'], 'timestamp' => '1527459824', 'message' => ['mid' => 'random_mid', 'text' => 'random_text']]]]]]])->assertOk();
        $this->assertSame(2, $workflow->runs()->count());

        // A quick answer tapped, a picture, a heart, a message taken back, a story answered: each read as what it is
        $event = fn (array $event) => Envelope::instagramMessage(['sender' => ['id' => '1339'], 'timestamp' => 1749416383000, ...$event]);
        $tapped = $event(['message' => ['mid' => 'm2', 'text' => 'Yes', 'quick_reply' => ['payload' => 'ok']]]);
        $this->assertSame(['button', 'button', 'ok', 'Yes', ['id' => 'ok', 'title' => 'Yes']], [$tapped['type'], $tapped['kind'], $tapped['text'], $tapped['display'], $tapped['button']]);
        $this->assertTrue(Envelope::wanted('instagram_message', null, 'button') && Envelope::wanted('instagram_message', 'button', 'button') && ! Envelope::wanted('instagram_message', 'button', 'message'));
        $picture = $event(['message' => ['mid' => 'm3', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://lookaside.fbsbx.com/ig/1']], ['type' => 'share', 'payload' => ['url' => 'https://www.instagram.com/p/AbC/']]], 'reply_to' => ['mid' => 'mid.out']]]);
        $this->assertSame(['message', 'image', ['https://lookaside.fbsbx.com/ig/1'], 'mid.out'], [$picture['type'], $picture['kind'], $picture['files'], $picture['reply']['quote']]);
        $heart = $event(['reaction' => ['mid' => 'mid.out', 'action' => 'react', 'reaction' => 'love', 'emoji' => '❤']]);
        $this->assertSame(['reaction', ['message' => 'mid.out', 'emoji' => '❤']], [$heart['type'], $heart['reaction']]);
        $this->assertNull($event(['reaction' => ['mid' => 'mid.out', 'action' => 'unreact']])['reaction']['emoji']);
        $this->assertSame('unsend', $event(['message' => ['mid' => 'm4', 'is_deleted' => true]])['type']);
        $story = $event(['message' => ['mid' => 'm5', 'text' => 'Nice', 'reply_to' => ['story' => ['id' => '179', 'url' => 'https://cdn.example.test/story']]]]);
        $this->assertSame(['text', ['id' => '179', 'url' => 'https://cdn.example.test/story']], [$story['kind'], $story['story']]);
    }

    public function test_instagram_messages_say_what_stands_in_their_way()
    {
        Http::fake(['graph.instagram.com/*' => Http::response(['error' => ['message' => '(#10) This message is sent outside of allowed window.', 'type' => 'OAuthException', 'code' => 10, 'error_subcode' => 2534022]], 400)]);
        $user = User::factory()->create();
        $account = Connection::factory()->instagram()->for($user)->create()->ref_id;
        $byFacebook = Connection::factory()->instagram()->for($user)->create(['name' => 'By its Page', 'settings' => ['by' => 'facebook', 'via' => 'facebook', 'client_id' => 'app-1', 'member' => '1789', 'member_name' => 'shop'], 'secrets' => ['client_secret' => 'app-secret', 'access_token' => 'page-token']])->ref_id;
        $run = fn (string $type, array $settings) => $this->step($this->runChain($user, [$this->node('it', $type, ['connection' => $account, ...$settings])], ['n' => 1]), 'it')->error['message'];

        // No one to write to, too many quick answers, a file Instagram shows in no chat, a sign-in by Facebook: said before anything is asked
        $this->assertStringContainsString('There is no one to send to', $run('instagram.send', ['to' => '@bo', 'text' => 'Hi']));
        $this->assertStringContainsString('at most 13 quick answers', $run('instagram.send', ['to' => '1339', 'text' => 'Hi', 'buttons' => array_map(fn (int $n) => ['name' => "B{$n}", 'value' => ''], range(1, 14))]));
        $this->assertStringContainsString('at most 1,000 bytes', $run('instagram.send', ['to' => '1339', 'text' => str_repeat('ก', 400)]));
        $this->assertStringContainsString('nothing Instagram shows in a chat', $run('instagram.send_file', ['to' => '1339', 'file' => $this->file($user, 'menu.pdf', 'application/pdf')->ref_id]));
        $this->assertStringContainsString('"By its Page" signs in with Facebook: set "Sign in with" to Instagram', $run('instagram.send', ['connection' => $byFacebook, 'to' => '1339', 'text' => 'Hi']));
        Http::assertNothingSent();

        // Past the day since they last wrote, Instagram's refusal is said plainly -- and a commenter is named by the comment
        $this->assertSame('Instagram takes a message only within 24 hours of the other\'s last one.', $run('instagram.send', ['to' => '1339', 'text' => 'Hi']));
        $run('instagram.message_commenter', ['comment' => 'c1', 'text' => 'Thanks for asking']);
        Http::assertSent(fn (Request $request) => $request->data() === ['recipient' => ['comment_id' => 'c1'], 'message' => ['text' => 'Thanks for asking']]);
    }

    // --- WhatsApp ---

    public function test_a_whatsapp_message_starts_a_run_and_each_whatsapp_call_is_a_step()
    {
        Http::fake(fn (Request $request) => match (true) {
            str_ends_with($request->url(), '/106540352242922/media') => Http::response(['id' => 'media-9']),
            str_ends_with($request->url(), '/106540352242922/messages') => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '16505551234', 'wa_id' => '16505551234']], 'messages' => [['id' => 'wamid.out']]]),
            str_contains($request->url(), 'lookaside.fbsbx.com') => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            str_ends_with($request->url(), '/media-in') => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/media-in', 'mime_type' => 'image/png', 'id' => 'media-in']),
            default => Http::response(['error' => ['message' => 'Not faked']], 400),
        });
        $user = User::factory()->create();
        $number = Connection::factory()->whatsapp()->for($user)->create();
        $on = fn (array $settings = []) => ['connection' => $number->ref_id, ...$settings];
        $doc = $this->file($user, 'menu.pdf', 'application/pdf');

        $workflow = Workflow::factory()->for($user)->steps([
            $this->node('heard', 'trigger.whatsapp', $on()),
            $this->node('read', 'whatsapp.read', $on(['typing' => 'yes'])),
            $this->node('save', 'whatsapp.file', $on()),
            $this->node('say', 'whatsapp.send', $on(['text' => 'Got "{{ trigger.text }}" from {{ trigger.sender.name }}', 'reply_to' => '{{ trigger.reply.to }}', 'buttons' => [['name' => 'Yes', 'value' => 'ok'], ['name' => 'No', 'value' => '']]])),
            $this->node('menu', 'whatsapp.send_file', $on(['file' => $doc->ref_id, 'text' => 'Our menu'])),
            $this->node('back', 'whatsapp.send_file', $on(['file' => '{{ node("save").ref_id }}'])),
            $this->node('place', 'whatsapp.location', $on(['lat' => '13.75', 'lng' => '100.5', 'name' => 'Shop'])),
            $this->node('wave', 'whatsapp.react', $on()),
            $this->node('later', 'whatsapp.template', $on(['to' => '+66 81 234 5678', 'name' => 'order_ready', 'language' => 'th', 'values' => "{{ trigger.sender.name }}\n42"])),
        ], array_map(fn (array $pair) => $this->edge($pair[0], $pair[1]), [['heard', 'read'], ['read', 'save'], ['save', 'say'], ['say', 'menu'], ['menu', 'back'], ['back', 'place'], ['place', 'wave'], ['wave', 'later']]))->create();
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();
        auth()->logout();

        $hook = "/hooks/whatsapp/{$number->ref_id}";
        $said = fn (string $to, array $message) => ['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $to],
            'contacts' => [['profile' => ['name' => 'Sheena'], 'wa_id' => '16505551234']],
            'messages' => [$message],
        ]]]]]];
        $send = fn (array $body, ?string $secret = 'wa-app-secret') => $this->call('POST', $hook, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) json_encode($body), (string) $secret),
        ], (string) json_encode($body));

        // Meta asks first whether the address is the app's: only its token is answered
        $this->get($hook.'?hub.mode=subscribe&hub.challenge=1158201444&hub.verify_token='.WhatsAppApi::verifyToken($number))->assertOk()->assertSee('1158201444');
        $this->get($hook.'?hub.mode=subscribe&hub.challenge=1&hub.verify_token=guess')->assertForbidden();

        $picture = ['from' => '16505551234', 'id' => 'wamid.in', 'timestamp' => '1749416383', 'type' => 'image', 'image' => ['id' => 'media-in', 'mime_type' => 'image/png', 'caption' => 'Is this it?']];
        // Signed with another secret, or for another number of the same app: nothing starts
        $send($said('106540352242922', $picture), 'someone-elses')->assertForbidden();
        $send($said('999', $picture))->assertOk();
        $this->assertSame(0, $workflow->runs()->count());

        $send($said('106540352242922', $picture))->assertOk();
        // The same message sent again starts nothing more; nor does word that one was delivered
        $send($said('106540352242922', $picture))->assertOk();
        $send(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '106540352242922'], 'statuses' => [['id' => 'wamid.out', 'status' => 'delivered']]]]]]]])->assertOk();
        $this->work();

        $run = $workflow->runs()->sole();
        $this->assertAllWentThrough($run);
        $heard = $run->trigger_data;
        $this->assertSame(['whatsapp', 'message', 'image', 'wamid.in', 'Is this it?', ['media-in']], [$heard['service'], $heard['type'], $heard['kind'], $heard['id'], $heard['text'], $heard['files']]);
        $this->assertSame([['id' => '16505551234', 'kind' => 'private', 'title' => null], 'Sheena'], [$heard['chat'], $heard['sender']['name']]);
        $this->assertSame(['image/png', 'wamid.out'], [$this->step($run, 'save')->output['mime'], $this->step($run, 'say')->output['id']]);

        $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages'))->map(fn (array $pair) => $pair[0])->values();
        $this->assertTrue($sent[0]->hasHeader('Authorization', 'Bearer wa-token'));
        $this->assertSame(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.in', 'typing_indicator' => ['type' => 'text']], $sent[0]->data());
        // Words with buttons under them, in answer to theirs, to the number they wrote from
        $this->assertSame(['16505551234', ['message_id' => 'wamid.in'], 'interactive'], [$sent[1]['to'], $sent[1]['context'], $sent[1]['type']]);
        $this->assertSame(['Got "Is this it?" from Sheena', [['type' => 'reply', 'reply' => ['id' => 'ok', 'title' => 'Yes']], ['type' => 'reply', 'reply' => ['id' => 'No', 'title' => 'No']]]], [$sent[1]['interactive']['body']['text'], $sent[1]['interactive']['action']['buttons']]);
        // A file is handed over first, then sent as what WhatsApp shows it as
        $this->assertSame(['document', ['id' => 'media-9', 'caption' => 'Our menu', 'filename' => 'menu.pdf']], [$sent[2]['type'], $sent[2]['document']]);
        $this->assertSame(['image', ['id' => 'media-9']], [$sent[3]['type'], $sent[3]['image']]);
        $this->assertSame(['latitude' => 13.75, 'longitude' => 100.5, 'name' => 'Shop'], $sent[4]['location']);
        $this->assertSame(['message_id' => 'wamid.in', 'emoji' => '👍'], $sent[5]['reaction']);
        $this->assertSame(['66812345678', ['name' => 'order_ready', 'language' => ['code' => 'th'], 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Sheena'], ['type' => 'text', 'text' => '42']]]]]], [$sent[6]['to'], $sent[6]['template']]);

        // A press of a button comes back as one, to a trigger that takes buttons
        $pressed = Envelope::whatsapp([], ['from' => '1', 'id' => 'w2', 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'ok', 'title' => 'Yes']]]);
        $this->assertSame(['button', 'button', 'ok', ['id' => 'ok', 'title' => 'Yes']], [$pressed['type'], $pressed['kind'], $pressed['text'], $pressed['button']]);
        $this->assertTrue(Envelope::wanted('whatsapp', 'button', $pressed['type']) && ! Envelope::wanted('whatsapp', 'reaction', $pressed['type']));
    }

    public function test_whatsapp_says_when_only_a_template_will_reach_someone()
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => '(#131047) Re-engagement message', 'type' => 'OAuthException', 'code' => 131047]], 400)]);
        $user = User::factory()->create();
        $number = Connection::factory()->whatsapp()->for($user)->create()->ref_id;
        $run = fn (string $type, array $settings) => $this->step($this->runChain($user, [$this->node('it', $type, ['connection' => $number, ...$settings])], ['n' => 1]), 'it')->error['message'];

        $this->assertStringContainsString('There is no one to send to', $run('whatsapp.send', ['to' => 'no number here', 'text' => 'Hi']));
        $this->assertStringContainsString('at most 3 buttons', $run('whatsapp.send', ['to' => '66812345678', 'text' => 'Hi', 'buttons' => array_map(fn (int $n) => ['name' => "B{$n}", 'value' => ''], [1, 2, 3, 4])]));
        $this->assertStringContainsString('two numbers', $run('whatsapp.location', ['to' => '66812345678', 'lat' => 'north', 'lng' => '1']));
        Http::assertNothingSent();

        $this->assertStringContainsString('only within 24 hours', $run('whatsapp.send', ['to' => '66812345678', 'text' => 'Hi']));
    }

    // --- SerpApi and Tavily ---

    public function test_each_serpapi_call_is_a_step_that_asks_its_engine_and_gives_out_what_it_found()
    {
        Http::fake(fn (Request $request) => Http::response(match (true) {
            str_contains($request->url(), '/locations.json') => [['id' => '585069bdee19ad271e9bc072', 'name' => 'Chiang Mai', 'canonical_name' => 'Chiang Mai,Chiang Mai,Thailand', 'country_code' => 'TH', 'target_type' => 'City', 'reach' => 1410000, 'gps' => [98.9853, 18.7883]]],
            str_contains($request->url(), '/account.json') => ['api_key' => 'serp-key', 'account_email' => 'ada@example.com', 'plan_name' => 'Free Plan', 'searches_per_month' => 250, 'total_searches_left' => 240, 'this_month_usage' => 10, 'this_hour_searches' => 2, 'account_rate_limit_per_hour' => 50, 'plan_renewal_date' => null],
            default => ['search_metadata' => ['id' => 'search-1', 'status' => 'Success'], ...match ($request->data()['engine']) {
                'google' => [
                    'search_information' => ['total_results' => 1200],
                    'answer_box' => ['type' => 'organic_result', 'snippet' => 'Khao soi is a noodle soup.'],
                    'knowledge_graph' => ['title' => 'Khao soi', 'type' => 'Dish', 'description' => 'A soup of the north.', 'header_images' => [['image' => 'https://img.example/1.jpg']]],
                    'organic_results' => [
                        ['position' => 1, 'title' => 'Khao soi', 'link' => 'https://en.example.org/khao-soi', 'snippet' => 'A curry noodle soup.', 'source' => 'Example', 'sitelinks' => ['inline' => []]],
                        ['position' => 2, 'title' => 'Where to eat it', 'link' => 'https://eat.example.com', 'snippet' => 'Ten shops.', 'date' => 'Oct 1, 2026'],
                    ],
                    'related_questions' => [['question' => 'Is khao soi spicy?', 'snippet' => 'Mildly.', 'link' => 'https://q.example.com']],
                    'related_searches' => [['query' => 'khao soi recipe', 'link' => 'https://www.google.com/search?q=khao+soi+recipe']],
                ],
                // Several papers on one story come as the story
                'google_news' => ['news_results' => [
                    ['position' => 1, 'title' => 'Flood warning', 'link' => 'https://news.example.com/flood', 'source' => ['name' => 'The Post', 'icon' => 'https://news.example.com/i.png'], 'date' => '10/12/2026, 08:00 AM, +0700 +07', 'iso_date' => '2026-10-12T01:00:00Z'],
                    ['position' => 2, 'title' => 'Election', 'stories' => [['title' => 'Votes counted', 'link' => 'https://news.example.com/votes', 'source' => ['name' => 'The Times'], 'iso_date' => '2026-10-11T01:00:00Z']]],
                    ['position' => 3, 'title' => 'Third', 'link' => 'https://news.example.com/third'],
                ]],
                'google_images' => ['images_results' => [['position' => 1, 'title' => 'A bowl', 'original' => 'https://img.example/bowl.jpg', 'original_width' => 1200, 'original_height' => 800, 'thumbnail' => 'https://img.example/t.jpg', 'link' => 'https://eat.example.com/bowl', 'source' => 'Eat', 'is_product' => false]]],
                'google_maps' => ['local_results' => [['position' => 1, 'title' => 'Khao Soi Mae Sai', 'address' => '29/1 Ratchaphuek Alley', 'gps_coordinates' => ['latitude' => 18.7986, 'longitude' => 98.9753], 'rating' => 4.5, 'reviews' => 3100, 'type' => 'Noodle shop', 'open_state' => 'Open', 'place_id' => 'ChIJ1', 'photos_link' => 'https://serpapi.com/x']]],
                'youtube' => ['video_results' => [['position_on_page' => 1, 'title' => 'Making khao soi', 'link' => 'https://www.youtube.com/watch?v=abc', 'video_id' => 'abc', 'channel' => ['name' => 'Cook', 'link' => 'https://www.youtube.com/@cook'], 'published_date' => '1 day ago', 'views' => 5300, 'length' => '12:08', 'description' => 'Step by step.', 'thumbnail' => ['static' => 'https://i.example/abc.jpg', 'rich' => 'https://i.example/abc.webp']]]],
                default => ['search_parameters' => ['engine' => 'google_shopping', 'q' => 'wok', 'api_key' => 'serp-key'], 'shopping_results' => [['title' => 'Wok', 'price' => '฿590']]],
            }],
        }));
        $user = User::factory()->create();
        $on = fn (array $settings = []) => ['connection' => Connection::factory()->serpapi()->for($user)->create()->ref_id, ...$settings];

        $run = $this->runChain($user, [
            $this->node('where', 'serpapi.locations', ['q' => '{{ trigger.city }}']),
            $this->node('web', 'serpapi.google', $on(['q' => '{{ trigger.dish }}', 'location' => '{{ node("where").name }}', 'gl' => 'TH', 'hl' => 'th', 'period' => 'week', 'page' => 3, 'more' => [['name' => 'safe', 'value' => 'active'], ['name' => 'engine', 'value' => 'bing'], ['name' => 'async', 'value' => 'true']]])),
            $this->node('news', 'serpapi.news', $on(['sort' => 'date', 'count' => 2])),
            $this->node('pictures', 'serpapi.images', $on(['q' => 'khao soi', 'kind' => 'photo', 'page' => 2])),
            $this->node('shops', 'serpapi.maps', $on(['q' => 'khao soi', 'around' => '{{ trigger.place }}', 'zoom' => 15])),
            $this->node('near', 'serpapi.maps', $on(['q' => 'khao soi', 'around' => '18.79, 98.98', 'page' => 2])),
            $this->node('videos', 'serpapi.youtube', $on(['q' => 'khao soi'])),
            $this->node('buy', 'serpapi.search', $on(['engine' => 'Google_Shopping', 'params' => [['name' => 'q', 'value' => 'wok'], ['name' => 'gl', 'value' => 'th']]])),
            $this->node('left', 'serpapi.account', $on()),
        ], ['city' => 'Chiang Mai', 'dish' => 'khao soi', 'place' => ['lat' => 18.7883, 'lng' => 98.9853, 'title' => 'Old city']]);

        $this->assertAllWentThrough($run);
        $asked = Http::recorded()->map(fn (array $pair) => [basename((string) parse_url($pair[0]->url(), PHP_URL_PATH)), collect($pair[0]->data())->except('api_key')->all(), $pair[0]->data()['api_key'] ?? null])->all();

        // Asking for places takes no key; everything else carries it
        $this->assertSame(['locations.json', ['q' => 'Chiang Mai', 'limit' => 5], null], $asked[0]);
        $this->assertSame(array_fill(0, 8, 'serp-key'), array_column(array_slice($asked, 1), 2));
        // What a step is set to goes over anything else it is told, and it is always the step's own engine, waited for
        $this->assertSame(['search.json', ['safe' => 'active', 'q' => 'khao soi', 'location' => 'Chiang Mai,Chiang Mai,Thailand', 'gl' => 'th', 'hl' => 'th', 'tbs' => 'qdr:w', 'start' => 20, 'engine' => 'google']], array_slice($asked[1], 0, 2));
        $this->assertSame(['so' => 1, 'engine' => 'google_news'], $asked[2][1]);
        $this->assertSame(['q' => 'khao soi', 'image_type' => 'photo', 'ijn' => 1, 'engine' => 'google_images'], $asked[3][1]);
        // A place sent in a message, or two numbers, is where the map is searched around
        $this->assertSame(['q' => 'khao soi', 'type' => 'search', 'll' => '@18.7883,98.9853,15z', 'engine' => 'google_maps'], $asked[4][1]);
        $this->assertSame(['q' => 'khao soi', 'type' => 'search', 'll' => '@18.79,98.98,14z', 'start' => 20, 'engine' => 'google_maps'], $asked[5][1]);
        $this->assertSame(['search_query' => 'khao soi', 'engine' => 'youtube'], $asked[6][1]);
        $this->assertSame(['q' => 'wok', 'gl' => 'th', 'engine' => 'google_shopping'], $asked[7][1]);
        $this->assertSame('account.json', $asked[8][0]);

        $this->assertSame(['name' => 'Chiang Mai,Chiang Mai,Thailand', 'short' => 'Chiang Mai', 'country' => 'TH', 'kind' => 'City', 'lat' => 18.7883, 'lng' => 98.9853, 'reach' => 1410000], $this->step($run, 'where')->output['places'][0]);
        $web = $this->step($run, 'web')->output;
        $this->assertSame(['position' => 2, 'title' => 'Where to eat it', 'link' => 'https://eat.example.com', 'snippet' => 'Ten shops.', 'source' => null, 'date' => 'Oct 1, 2026'], $web['results'][1]);
        $this->assertSame(['Khao soi is a noodle soup.', ['title' => 'Khao soi', 'type' => 'Dish', 'description' => 'A soup of the north.', 'website' => null], ['khao soi recipe'], 1200, 'search-1'], [$web['answer'], $web['knowledge'], $web['related'], $web['total'], $web['id']]);
        $this->assertSame(['question' => 'Is khao soi spicy?', 'snippet' => 'Mildly.', 'link' => 'https://q.example.com'], $web['questions'][0]);
        $this->assertSame("1. Khao soi\nhttps://en.example.org/khao-soi\nA curry noodle soup.\n\n2. Where to eat it\nhttps://eat.example.com\nTen shops.", $web['text']);

        $news = $this->step($run, 'news')->output['results'];
        $this->assertSame([['position' => 1, 'title' => 'Flood warning', 'link' => 'https://news.example.com/flood', 'source' => 'The Post', 'date' => '2026-10-12T01:00:00Z', 'thumbnail' => null], ['https://news.example.com/votes', 'The Times']], [$news[0], [$news[1]['link'], $news[1]['source']]]);
        $this->assertCount(2, $news);
        $this->assertSame(['position' => 1, 'title' => 'A bowl', 'image' => 'https://img.example/bowl.jpg', 'width' => 1200, 'height' => 800, 'thumbnail' => 'https://img.example/t.jpg', 'link' => 'https://eat.example.com/bowl', 'source' => 'Eat'], $this->step($run, 'pictures')->output['results'][0]);
        $shop = $this->step($run, 'shops')->output['results'][0];
        $this->assertSame(['Khao Soi Mae Sai', '29/1 Ratchaphuek Alley', 18.7986, 98.9753, 4.5, 'Open'], [$shop['title'], $shop['address'], $shop['lat'], $shop['lng'], $shop['rating'], $shop['open']]);
        $video = $this->step($run, 'videos')->output['results'][0];
        $this->assertSame(['abc', 'Cook', 'https://i.example/abc.jpg'], [$video['id'], $video['channel'], $video['thumbnail']]);
        $this->assertSame([['title' => 'Wok', 'price' => '฿590']], $this->step($run, 'buy')->output['body']['shopping_results']);
        $this->assertSame(['plan' => 'Free Plan', 'left' => 240, 'used' => 10, 'per_month' => 250, 'this_hour' => 2, 'per_hour' => 50, 'renews' => null], $this->step($run, 'left')->output);

        // The key is asked with, and kept nowhere a run can be read
        $this->assertStringNotContainsString('serp-key', (string) json_encode($run->steps()->get()->map->only(['input', 'output', 'config', 'error'])));
    }

    public function test_a_serpapi_search_that_finds_nothing_gives_out_nothing_and_a_refusal_says_why()
    {
        $answers = [
            // Nothing found is an answer, with SerpApi saying so
            Http::response(['search_metadata' => ['id' => 's', 'status' => 'Success'], 'search_information' => ['organic_results_state' => 'Fully empty'], 'error' => 'Google hasn\'t returned any results for this query.']),
            Http::response(['error' => 'Invalid API key. Your API key should be here: https://serpapi.com/manage-api-key'], 401),
            Http::response(['error' => 'Your account has run out of searches.'], 429),
            Http::response(['error' => 'Unsupported `engine` parameter.'], 400),
        ];
        Http::fake(function () use (&$answers) {
            return array_shift($answers);
        });
        $user = User::factory()->create();
        $key = Connection::factory()->serpapi()->for($user)->create()->ref_id;
        $run = fn (string $type, array $settings) => $this->step($this->runChain($user, [$this->node('it', $type, ['connection' => $key, ...$settings])], ['none' => '']), 'it');

        $none = $run('serpapi.google', ['q' => 'zxqv']);
        $this->assertSame([NodeStatus::Succeeded, [], null, ''], [$none->status, $none->output['results'], $none->output['answer'], $none->output['text']]);
        $this->assertStringContainsString('refused the key', $run('serpapi.google', ['q' => 'khao soi'])->error['message']);
        $this->assertStringContainsString('run out of searches', $run('serpapi.news', [])->error['message']);
        $this->assertStringContainsString('Unsupported `engine`', $run('serpapi.search', ['engine' => 'nope'])->error['message']);
        Http::assertSentCount(4);

        // What is plainly wrong is said before SerpApi is asked
        $this->assertStringContainsString('not an engine\'s name', $run('serpapi.search', ['engine' => 'google shopping'])->error['message']);
        $this->assertStringContainsString('two numbers', $run('serpapi.maps', ['q' => 'coffee', 'around' => 'somewhere near'])->error['message']);
        $this->assertStringContainsString('nothing to search for', $run('serpapi.google', ['q' => '{{ trigger.none }}'])->error['message']);
        Http::assertSentCount(4);
    }

    public function test_each_tavily_call_is_a_step_that_asks_as_tavily_reads_it_and_gives_out_what_came_back()
    {
        Http::fake(fn (Request $request) => Http::response(match (basename((string) parse_url($request->url(), PHP_URL_PATH))) {
            'search' => [
                'query' => 'khao soi',
                'answer' => 'A curry noodle soup of the north.',
                'images' => [['url' => 'https://img.example/bowl.jpg', 'description' => 'A bowl'], 'https://img.example/2.jpg'],
                'results' => [
                    ['title' => 'Khao soi', 'url' => 'https://en.example.org/khao-soi', 'content' => 'A curry noodle soup.', 'score' => 0.91, 'raw_content' => '# Khao soi', 'published_date' => '2026-10-01'],
                    ['title' => 'Where to eat it', 'url' => 'https://eat.example.com', 'content' => 'Ten shops.', 'score' => 0.72, 'raw_content' => null],
                ],
                'response_time' => 1.2,
                'usage' => ['credits' => 2],
                'request_id' => 'req-1',
            ],
            'extract' => [
                'results' => [['url' => 'https://en.example.org/khao-soi', 'raw_content' => '# Khao soi', 'images' => []], ['url' => 'https://eat.example.com', 'raw_content' => 'Ten shops.']],
                'failed_results' => [['url' => 'https://gone.example.com', 'error' => 'Page not found']],
                'usage' => ['credits' => 1],
                'request_id' => 'req-2',
            ],
            'crawl' => ['base_url' => 'docs.example.com', 'results' => [['url' => 'https://docs.example.com/a', 'raw_content' => 'A']], 'usage' => ['credits' => 1], 'request_id' => 'req-3'],
            'map' => ['base_url' => 'docs.example.com', 'results' => ['https://docs.example.com/a', 'https://docs.example.com/b'], 'usage' => ['credits' => 1], 'request_id' => 'req-4'],
            default => ['key' => ['usage' => 150, 'limit' => 500, 'search_usage' => 100], 'account' => ['current_plan' => 'Researcher', 'plan_usage' => 800, 'plan_limit' => 1000, 'paygo_usage' => 0, 'paygo_limit' => 0]],
        }));
        $user = User::factory()->create();
        $on = fn (array $settings = []) => ['connection' => Connection::factory()->tavily()->for($user)->create()->ref_id, ...$settings];

        $run = $this->runChain($user, [
            $this->node('find', 'tavily.search', $on(['query' => '{{ trigger.dish }}', 'topic' => 'news', 'depth' => 'advanced', 'count' => 40, 'period' => 'week', 'answer' => 'basic', 'raw' => 'markdown', 'only' => "https://www.Wikipedia.org/wiki, bbc.com\nexample.org", 'more' => [['name' => 'country', 'value' => 'thailand'], ['name' => 'include_images', 'value' => 'true'], ['name' => 'chunks_per_source', 'value' => '2']]])),
            $this->node('plain', 'tavily.search', $on(['query' => 'khao soi'])),
            $this->node('read', 'tavily.extract', $on(['urls' => "{{ item(node(\"find\").results, 1).url }}\nhttps://eat.example.com, https://gone.example.com", 'depth' => 'advanced', 'format' => 'text', 'about' => 'ingredients'])),
            $this->node('all', 'tavily.crawl', $on(['url' => 'https://docs.example.com', 'instructions' => 'Pricing', 'max_depth' => 9, 'limit' => 500, 'select_paths' => "/docs/.*\n/guide/(a|b),c", 'external' => 'yes'])),
            $this->node('pages', 'tavily.map', $on(['url' => 'https://docs.example.com'])),
            $this->node('spent', 'tavily.usage', $on()),
        ], ['dish' => 'khao soi']);

        $this->assertAllWentThrough($run);
        $asked = Http::recorded()->map(fn (array $pair) => [$pair[0]->method().' '.str_replace('https://api.tavily.com', '', $pair[0]->url()), $pair[0]->data(), $pair[0]->hasHeader('Authorization', 'Bearer tvly-key')])->all();

        $this->assertSame(array_fill(0, 6, true), array_column($asked, 2));
        // Yes, a number and a list are sent as themselves; a site is named without its address's ends
        $this->assertSame(['POST /search', [
            'country' => 'thailand', 'include_images' => true, 'chunks_per_source' => 2,
            'query' => 'khao soi', 'topic' => 'news', 'search_depth' => 'advanced', 'max_results' => 20, 'time_range' => 'week',
            'include_answer' => 'basic', 'include_raw_content' => 'markdown', 'include_domains' => ['wikipedia.org', 'bbc.com', 'example.org'], 'include_usage' => true,
        ]], array_slice($asked[0], 0, 2));
        $this->assertSame(['query' => 'khao soi', 'topic' => 'general', 'search_depth' => 'basic', 'max_results' => 5, 'include_usage' => true], $asked[1][1]);
        $this->assertSame(['POST /extract', ['urls' => ['https://en.example.org/khao-soi', 'https://eat.example.com', 'https://gone.example.com'], 'extract_depth' => 'advanced', 'format' => 'text', 'query' => 'ingredients', 'include_usage' => true]], array_slice($asked[2], 0, 2));
        // A crawl is told to stop before the step would be; a pattern keeps its comma
        $this->assertSame(['POST /crawl', ['url' => 'https://docs.example.com', 'instructions' => 'Pricing', 'max_depth' => 5, 'limit' => 100, 'select_paths' => ['/docs/.*', '/guide/(a|b),c'], 'allow_external' => true, 'timeout' => 120, 'extract_depth' => 'basic', 'format' => 'markdown', 'include_usage' => true]], array_slice($asked[3], 0, 2));
        $this->assertSame(['POST /map', ['url' => 'https://docs.example.com', 'max_depth' => 1, 'limit' => 50, 'allow_external' => false, 'timeout' => 120, 'include_usage' => true]], array_slice($asked[4], 0, 2));
        $this->assertSame('GET /usage', $asked[5][0]);

        $found = $this->step($run, 'find')->output;
        $this->assertSame(['title' => 'Khao soi', 'url' => 'https://en.example.org/khao-soi', 'content' => 'A curry noodle soup.', 'score' => 0.91, 'date' => '2026-10-01', 'text' => '# Khao soi'], $found['results'][0]);
        $this->assertSame(['A curry noodle soup of the north.', ['https://img.example/bowl.jpg', 'https://img.example/2.jpg'], false, 2, 'req-1'], [$found['answer'], $found['images'], $found['cut'], $found['credits'], $found['id']]);
        $this->assertSame("1. Khao soi\nhttps://en.example.org/khao-soi\nA curry noodle soup.\n\n2. Where to eat it\nhttps://eat.example.com\nTen shops.", $found['text']);
        // Without each page whole, a result has no text of its own
        $this->assertSame(['title', 'url', 'content', 'score', 'date'], array_keys($this->step($run, 'plain')->output['results'][0]));

        $read = $this->step($run, 'read')->output;
        $this->assertSame("https://en.example.org/khao-soi\n\n# Khao soi\n\n---\n\nhttps://eat.example.com\n\nTen shops.", $read['text']);
        $this->assertSame([[['url' => 'https://en.example.org/khao-soi', 'text' => '# Khao soi'], ['url' => 'https://eat.example.com', 'text' => 'Ten shops.']], [['url' => 'https://gone.example.com', 'error' => 'Page not found']], false, 1], [$read['results'], $read['failed'], $read['cut'], $read['credits']]);
        $this->assertSame(['site' => 'docs.example.com', 'results' => [['url' => 'https://docs.example.com/a', 'text' => 'A']], 'count' => 1, 'text' => 'A', 'cut' => false, 'credits' => 1, 'id' => 'req-3'], $this->step($run, 'all')->output);
        $this->assertSame(['site' => 'docs.example.com', 'urls' => ['https://docs.example.com/a', 'https://docs.example.com/b'], 'count' => 2, 'credits' => 1, 'id' => 'req-4'], $this->step($run, 'pages')->output);
        // What is left is the less of what the key and the plan may still spend
        $this->assertSame(['used' => 150, 'limit' => 500, 'left' => 200, 'plan' => 'Researcher', 'plan_used' => 800, 'plan_limit' => 1000], $this->step($run, 'spent')->output);
        $this->assertStringNotContainsString('tvly-key', (string) json_encode($run->steps()->get()->toArray()));
    }

    public function test_tavily_cuts_long_pages_to_what_a_step_may_give_out_and_says_what_it_refused()
    {
        $long = str_repeat('ข้าวซอย ', 20000);
        $answers = [
            Http::response(['results' => [['url' => 'https://a.example.com', 'raw_content' => $long], ['url' => 'https://b.example.com', 'raw_content' => 'Short.']], 'failed_results' => []]),
            Http::response(['results' => [], 'failed_results' => [['url' => 'https://gone.example.com', 'error' => 'Page not found']]]),
            Http::response(['detail' => ['error' => 'Unauthorized: missing or invalid API key.']], 401),
            Http::response(['detail' => ['error' => 'This request exceeds your plan\'s set usage limit.']], 432),
            Http::response(['detail' => [['type' => 'enum', 'loc' => ['body', 'topic'], 'msg' => 'Input should be \'general\', \'news\' or \'finance\'', 'input' => 'sport']]], 422),
        ];
        Http::fake(function () use (&$answers) {
            return array_shift($answers);
        });
        $user = User::factory()->create();
        $key = Connection::factory()->tavily()->for($user)->create()->ref_id;
        $run = fn (string $type, array $settings) => $this->step($this->runChain($user, [$this->node('it', $type, ['connection' => $key, ...$settings])], ['none' => '']), 'it');

        $read = $run('tavily.extract', ['urls' => 'https://a.example.com, https://b.example.com']);
        $this->assertSame([NodeStatus::Succeeded, true, 'Short.'], [$read->status, $read->output['cut'], $read->output['results'][1]['text']]);
        $this->assertStringEndsWith('…', $read->output['results'][0]['text']);
        $this->assertLessThan(Engine::MAX_OUTPUT_BYTES, strlen((string) json_encode($read->output)));

        $this->assertSame('Tavily could not read https://gone.example.com: Page not found', $run('tavily.extract', ['urls' => 'https://gone.example.com'])->error['message']);
        $this->assertStringContainsString('refused the key', $run('tavily.search', ['query' => 'khao soi'])->error['message']);
        $this->assertStringContainsString('credits are used up', $run('tavily.search', ['query' => 'khao soi'])->error['message']);
        $this->assertSame('Tavily said: topic: Input should be \'general\', \'news\' or \'finance\'', $run('tavily.search', ['query' => 'khao soi', 'more' => [['name' => 'topic', 'value' => 'sport']]])->error['message']);
        Http::assertSentCount(5);

        // What is plainly wrong is said before Tavily is asked
        $this->assertStringContainsString('at most 20 pages', $run('tavily.extract', ['urls' => implode("\n", array_map(fn (int $n) => "https://example.com/{$n}", range(1, 21)))])->error['message']);
        $this->assertStringContainsString('no site to start from', $run('tavily.map', ['url' => '{{ trigger.none }}'])->error['message']);
        Http::assertSentCount(5);
    }
}
