<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Engine;
use App\Support\Workflow\Inbound\Envelope;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\NodeTypes;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every call Telegram and LINE offer as a step of its own: each asks the
 * call it stands for, with what its settings came to -- and the triggers
 * start on the kind of thing they are set to. Through the real queue; both
 * services are faked.
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

    private function file(User $user, string $name, ?string $mime = null): DriveFile
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'test-file-');
        file_put_contents($temp, base64_decode(self::PNG));
        $file = DriveFile::keep($temp, $name, $user);
        unlink($temp);

        // What it would be had it really been one
        if ($mime !== null) {
            $file->forceFill(['mime' => $mime])->save();
        }

        return $file;
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
            $this->node('rebutton', 'telegram.edit_buttons', $on(['buttons' => [['name' => 'Undo', 'value' => 'undo']]])),
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
            'chat' => ['id' => '77'], 'sender' => ['id' => '5'], 'reply' => ['to' => 3], 'location' => ['lat' => 13.75, 'lng' => 100.5],
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
        $this->assertSame([[['text' => 'Undo', 'callback_data' => 'undo']]], $asked['editMessageReplyMarkup']['reply_markup']['inline_keyboard']);
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
            'api.line.me/v2/bot/richmenu/list' => Http::response(['richmenus' => [['richMenuId' => 'richmenu-1', 'name' => 'Main', 'chatBarText' => 'Menu']]]),
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
                ['name' => 'Yes', 'value' => 'postback:send:12'], ['name' => 'Look first', 'value' => 'https://example.com/12'], ['name' => 'Not yet'],
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
            $this->node('menu', 'line.richmenu', $on(['menu' => 'richmenu-1'])),
            $this->node('nomenu', 'line.richmenu_off', $on()),
            $this->node('menus', 'line.richmenus', $on()),
            $this->node('quota', 'line.quota', $on()),
            $this->node('used', 'line.consumption', $on()),
            $this->node('group', 'line.group', $on(['chat' => 'C1'])),
            $this->node('count', 'line.group_count', $on(['chat' => 'C1'])),
            $this->node('leave', 'line.leave', $on(['chat' => 'C1'])),
        ], ['chat' => ['id' => 'U9'], 'sender' => ['id' => 'U9'], 'reply' => ['token' => 'rt-9', 'quote' => 'q-9', 'read' => 'read-9']]);

        $this->assertAllWentThrough($run);
        $sent = fn (string $how) => Http::recorded(fn (Request $request) => str_ends_with($request->url(), "/message/{$how}"))->map(fn (array $pair) => $pair[0])->values();

        // Buttons: an address opens, a postback comes back unseen, anything else is said
        $buttons = $sent('reply')->sole()['messages'][0];
        $this->assertSame(['template', 'Send it?', 'buttons', 'Order 12'], [$buttons['type'], $buttons['altText'], $buttons['template']['type'], $buttons['template']['title']]);
        $this->assertSame([
            ['type' => 'postback', 'label' => 'Yes', 'data' => 'send:12', 'displayText' => 'Yes'],
            ['type' => 'uri', 'label' => 'Look first', 'uri' => 'https://example.com/12'],
            ['type' => 'message', 'label' => 'Not yet', 'text' => 'Not yet'],
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
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/user/U9/richmenu/richmenu-1'));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/user/U9/richmenu'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/group/C1/leave'));

        $this->assertSame([['id' => 'richmenu-1', 'name' => 'Main', 'bar' => 'Menu']], $this->step($run, 'menus')->output['menus']);
        $this->assertSame(['limited' => true, 'limit' => 200], $this->step($run, 'quota')->output);
        $this->assertSame(12, $this->step($run, 'used')->output['sent']);
        $this->assertSame('Team', $this->step($run, 'group')->output['name']);
        $this->assertSame(5, $this->step($run, 'count')->output['count']);
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
}
