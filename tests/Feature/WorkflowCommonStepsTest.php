<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowSeen;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Connections\Services;
use App\Support\Outside\RemoteDownload;
use App\Support\Outside\SmtpTransports;
use App\Support\Workflow\Engine;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The steps most workflows want: a feed read, only new items, CSV, dates,
 * numbers and text written as wanted, hashes -- and Slack, Discord and
 * email. Through the real queue; every outside service is faked.
 */
class WorkflowCommonStepsTest extends TestCase
{
    use RefreshDatabase;

    private const RSS = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel><title>Kitchen &amp; news</title><link>https://news.example.com/</link>
<item><title>Older</title><link>https://news.example.com/1</link><guid>n-1</guid><pubDate>Thu, 15 Oct 2026 09:00:00 +0700</pubDate><description><![CDATA[<p>The <b>first</b> one.</p>]]></description><category>Food</category></item>
<item><title>Newest</title><link>https://news.example.com/2</link><guid>n-2</guid><pubDate>Fri, 16 Oct 2026 09:00:00 +0700</pubDate><dc:creator>Ada</dc:creator><description>Short.</description><content:encoded><![CDATA[<p>Whole story</p>]]></content:encoded><media:thumbnail url="https://news.example.com/2.jpg"/></item>
</channel></rss>
XML;

    private const ATOM = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"><title>Releases</title><link rel="alternate" href="https://code.example.com/"/>
<entry><id>tag:code.example.com,2026:v2</id><title>v2</title><link rel="alternate" href="https://code.example.com/v2"/><updated>2026-10-16T02:00:00Z</updated><summary>Faster.</summary><author><name>Bo</name></author><category term="release"/></entry>
</feed>
XML;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Http::preventStrayRequests();
        // Every name is somewhere public, unless a test says otherwise
        RemoteDownload::resolveUsing(fn (string $host) => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        RemoteDownload::resolveUsing(null);

        parent::tearDown();
    }

    private function node(string $key, string $type, array $config = []): array
    {
        return ['id' => $key, 'key' => $key, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    /**
     * Start → each step in turn, worked off: a test run, or with $live a
     * published one.
     */
    private function runChain(User $user, array $steps, array $payload = [], bool $live = false, ?Workflow $workflow = null): WorkflowRun
    {
        $nodes = [$this->node('start', 'trigger.manual', ['payload' => json_encode((object) $payload)]), ...$steps];
        $edges = [];

        foreach (array_slice($nodes, 1) as $at => $node) {
            $edges[] = ['id' => "e{$at}", 'source' => $nodes[$at]['id'], 'sourceHandle' => 'out', 'target' => $node['id']];
        }

        $workflow ??= Workflow::factory()->for($user)->steps($nodes, $edges)->create();
        $this->assertSame([], References::allProblems($workflow));
        // Started with this run's own data: a workflow run again is given new
        $run = Engine::start($workflow, $workflow->freeze($live ? WorkflowVersion::PUBLISHED : WorkflowVersion::TEST, $user), $user, triggerData: $payload);
        $this->work();

        return $run->fresh();
    }

    private function step(WorkflowRun $run, string $key): WorkflowNodeRun
    {
        return $run->steps()->where('node_key', $key)->sole();
    }

    private function assertAllWentThrough(WorkflowRun $run): void
    {
        $failed = $run->steps()->where('status', '!=', NodeStatus::Succeeded->value)->orderBy('id')->first();

        $this->assertNull($failed, $failed ? "{$failed->node_key}: ".json_encode($failed->error) : '');
        $this->assertSame(RunStatus::Succeeded, $run->status);
    }

    private function connection(User $user, string $service, array $settings, array $secrets): Connection
    {
        return Connection::factory()->for($user)->create(['name' => ucfirst($service), 'service' => $service, 'settings' => $settings, 'secrets' => $secrets]);
    }

    public function test_a_feed_is_read_newest_first_whatever_kind_it_is()
    {
        Http::fake([
            'https://news.example.com/feed.xml' => Http::response(self::RSS, 200, ['Content-Type' => 'application/rss+xml']),
            'https://code.example.com/releases.atom' => Http::response(self::ATOM, 200, ['Content-Type' => 'application/atom+xml']),
            'https://blog.example.com/feed.json' => Http::response(['version' => 'https://jsonfeed.org/version/1.1', 'title' => 'Blog', 'home_page_url' => 'https://blog.example.com/', 'items' => [
                ['id' => 'b-1', 'url' => 'https://blog.example.com/hello', 'title' => 'Hello', 'content_html' => '<p>Hi <i>there</i></p>', 'date_published' => '2026-10-14T08:00:00+07:00', 'tags' => ['news'], 'authors' => [['name' => 'Cy']]],
            ]]),
            'https://plain.example.com/' => Http::response('<html><body>Not a feed</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $user = User::factory()->create();

        $run = $this->runChain($user, [
            $this->node('rss', 'feed.read', ['url' => 'https://news.example.com/feed.xml', 'only_new' => 'all', 'count' => 10]),
            $this->node('atom', 'feed.read', ['url' => 'https://code.example.com/releases.atom', 'only_new' => 'all']),
            $this->node('json', 'feed.read', ['url' => 'https://blog.example.com/feed.json', 'only_new' => 'all']),
        ]);

        $this->assertAllWentThrough($run);
        $rss = $this->step($run, 'rss')->output;
        $this->assertSame(['Kitchen & news', 'https://news.example.com/', 2], [$rss['title'], $rss['link'], $rss['count']]);
        $this->assertSame([
            'id' => 'n-2', 'title' => 'Newest', 'link' => 'https://news.example.com/2', 'summary' => 'Short.', 'content' => '<p>Whole story</p>',
            'published' => '2026-10-16T02:00:00+00:00', 'author' => 'Ada', 'categories' => [], 'picture' => 'https://news.example.com/2.jpg',
        ], $rss['items'][0]);
        $this->assertSame(['n-1', 'The first one.', ['Food']], [$rss['items'][1]['id'], $rss['items'][1]['summary'], $rss['items'][1]['categories']]);

        $atom = $this->step($run, 'atom')->output;
        $this->assertSame(['Releases', 'https://code.example.com/'], [$atom['title'], $atom['link']]);
        $this->assertSame(['tag:code.example.com,2026:v2', 'https://code.example.com/v2', 'Faster.', 'Bo', ['release'], '2026-10-16T02:00:00+00:00'], [
            $atom['items'][0]['id'], $atom['items'][0]['link'], $atom['items'][0]['summary'], $atom['items'][0]['author'], $atom['items'][0]['categories'], $atom['items'][0]['published'],
        ]);

        $json = $this->step($run, 'json')->output;
        $this->assertSame(['Blog', 'b-1', 'Hi there', 'Cy', ['news']], [$json['title'], $json['items'][0]['id'], $json['items'][0]['summary'], $json['items'][0]['author'], $json['items'][0]['categories']]);

        $page = $this->runChain($user, [$this->node('page', 'feed.read', ['url' => 'https://plain.example.com/', 'only_new' => 'all'])]);
        $this->assertSame('plain.example.com did not answer with a feed (RSS, Atom or JSON Feed).', $this->step($page, 'page')->error['message']);

        // Only the public internet is read
        RemoteDownload::resolveUsing(fn () => ['10.0.0.5']);
        $inside = $this->runChain($user, [$this->node('inside', 'feed.read', ['url' => 'https://intranet.example.com/feed.xml', 'only_new' => 'all'])]);
        $this->assertStringContainsString('not on the public internet', $this->step($inside, 'inside')->error['message']);
    }

    public function test_only_new_feed_items_are_given_out_on_live_runs_and_a_test_run_uses_none_up()
    {
        Http::fake(['https://news.example.com/feed.xml' => Http::sequence()
            ->push(self::RSS, 200, ['Content-Type' => 'application/rss+xml'])
            ->push(self::RSS, 200, ['Content-Type' => 'application/rss+xml'])
            ->push(self::RSS, 200, ['Content-Type' => 'application/rss+xml'])
            ->push(str_replace('<channel>', '<channel><item><title>Third</title><guid>n-3</guid><pubDate>Sat, 17 Oct 2026 09:00:00 +0700</pubDate></item>', self::RSS), 200, ['Content-Type' => 'application/rss+xml'])]);
        $user = User::factory()->create();
        $feed = [$this->node('feed', 'feed.read', ['url' => 'https://news.example.com/feed.xml', 'only_new' => 'new', 'count' => 20])];
        $ids = fn (WorkflowRun $run) => array_column($this->step($run, 'feed')->output['items'], 'id');

        // Tried while being made: shows what is new, keeps nothing
        $tried = $this->runChain($user, $feed);
        $this->assertSame(['n-2', 'n-1'], $ids($tried));
        $this->assertSame(0, WorkflowSeen::count());

        $first = $this->runChain($user, $feed, live: true, workflow: $tried->workflow);
        $this->assertSame(['n-2', 'n-1'], $ids($first));
        $again = $this->runChain($user, $feed, live: true, workflow: $tried->workflow);
        $this->assertSame([], $ids($again));
        $this->assertSame(0, $this->step($again, 'feed')->output['count']);
        $later = $this->runChain($user, $feed, live: true, workflow: $tried->workflow);
        $this->assertSame(['n-3'], $ids($later));
    }

    public function test_only_new_items_are_kept_across_runs_or_within_the_list()
    {
        $user = User::factory()->create();
        $items = [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bo'], ['id' => 1, 'name' => 'Ada again']];
        $steps = [
            $this->node('new', 'data.dedupe', ['items' => 'trigger.people', 'by' => 'id', 'across' => 'runs']),
            $this->node('list', 'data.dedupe', ['items' => 'trigger.people', 'by' => 'name', 'across' => 'list']),
            $this->node('whole', 'data.dedupe', ['items' => 'trigger.tags', 'across' => 'list']),
        ];

        $first = $this->runChain($user, $steps, ['people' => $items, 'tags' => ['a', 'b', 'a']], live: true);
        $this->assertAllWentThrough($first);
        $this->assertSame(['items' => [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bo']], 'count' => 2, 'left_out' => 1], $this->step($first, 'new')->output);
        $this->assertSame(3, $this->step($first, 'list')->output['count']);
        $this->assertSame(['a', 'b'], $this->step($first, 'whole')->output['items']);

        $more = [['id' => 2, 'name' => 'Bo'], ['id' => 3, 'name' => 'Cy']];
        $second = $this->runChain($user, $steps, ['people' => $more, 'tags' => []], live: true, workflow: $first->workflow);
        $this->assertSame([['id' => 3, 'name' => 'Cy']], $this->step($second, 'new')->output['items']);
        // Within the list only: nothing remembered
        $this->assertSame(2, $this->step($second, 'list')->output['count']);

        $notList = $this->runChain($user, [$this->node('bad', 'data.dedupe', ['items' => 'trigger.name'])], ['name' => 'Ada']);
        $this->assertStringContainsString('should come to a list', $this->step($notList, 'bad')->error['message']);
    }

    public function test_csv_is_read_into_rows_and_rows_written_as_csv()
    {
        $user = User::factory()->create();
        $csv = "\xEF\xBB\xBFname;note;;name\nAda;\"two\nlines\";x;A2\n\nBo;\"say \"\"hi\"\"\";;B2\n";

        $run = $this->runChain($user, [
            $this->node('read', 'data.csv', ['do' => 'read', 'text' => '{{ trigger.csv }}', 'separator' => 'guess', 'header' => 'names']),
            $this->node('lists', 'data.csv', ['do' => 'read', 'text' => "a,b\n1,2", 'separator' => 'comma', 'header' => 'none']),
            $this->node('write', 'data.csv', ['do' => 'write', 'rows' => 'trigger.rows', 'separator' => 'comma', 'header' => 'names']),
        ], ['csv' => $csv, 'rows' => [['name' => 'Ada', 'tags' => ['a', 'b'], 'paid' => true], ['name' => 'Bo, Jr', 'age' => 30]]]);

        $this->assertAllWentThrough($run);
        $this->assertSame([
            'rows' => [
                ['name' => 'Ada', 'note' => "two\nlines", 'column_3' => 'x', 'name_2' => 'A2'],
                ['name' => 'Bo', 'note' => 'say "hi"', 'column_3' => '', 'name_2' => 'B2'],
            ],
            'columns' => ['name', 'note', 'column_3', 'name_2'],
            'count' => 2,
        ], $this->step($run, 'read')->output);
        $this->assertSame([['a', 'b'], ['1', '2']], $this->step($run, 'lists')->output['rows']);
        // Every name any row has, a list as JSON, a comma kept inside quotes
        $this->assertSame("name,tags,paid,age\nAda,\"[\"\"a\"\",\"\"b\"\"]\",true,\n\"Bo, Jr\",,,30\n", $this->step($run, 'write')->output['text']);
    }

    public function test_dates_numbers_and_text_are_written_as_asked()
    {
        $this->travelTo('2026-10-17 05:00:00');
        $user = User::factory()->create();

        $run = $this->runChain($user, [
            $this->node('thai', 'data.date', ['value' => '17/10/2026 14:30', 'from' => 'pattern', 'pattern_in' => 'd/m/Y H:i', 'zone_in' => 'Asia/Bangkok', 'add' => '+3 days', 'zone' => 'UTC', 'format' => 'long_time', 'language' => '']),
            $this->node('weekday', 'data.date', ['value' => '2026-10-16T02:00:00Z', 'from' => 'auto', 'zone' => 'Asia/Bangkok', 'format' => 'weekday', 'language' => 'th']),
            $this->node('unix', 'data.date', ['value' => '1792200000', 'from' => 'unix', 'zone_in' => 'UTC', 'format' => 'unix']),
            $this->node('bad', 'data.date', ['value' => 'next blue moon', 'from' => 'auto', 'format' => 'iso']),
        ]);

        $thai = $this->step($run, 'thai')->output;
        $this->assertSame(['20 October 2026, 07:30', '2026-10-20T07:30:00+00:00', '2026-10-20', '07:30', 'Tuesday', 'UTC'], [$thai['value'], $thai['iso'], $thai['date'], $thai['time'], $thai['weekday'], $thai['zone']]);
        $this->assertSame('ศุกร์', $this->step($run, 'weekday')->output['value']);
        $this->assertSame('1792200000', $this->step($run, 'unix')->output['value']);
        $this->assertSame('"next blue moon" could not be read as a date.', $this->step($run, 'bad')->error['message']);

        $numbers = $this->runChain($user, [
            $this->node('thousands', 'data.number', ['value' => '{{ trigger.n }}', 'style' => 'thousands', 'decimals' => 2]),
            $this->node('baht', 'data.number', ['value' => '฿1,234.5 total', 'style' => 'currency', 'currency' => 'thb', 'decimals' => 2, 'language' => 'th']),
            $this->node('euro', 'data.number', ['value' => '1.234,5', 'style' => 'plain', 'decimals' => 1]),
            $this->node('percent', 'data.number', ['value' => '0.256', 'style' => 'percent', 'decimals' => 1]),
            $this->node('words', 'data.number', ['value' => '42', 'style' => 'words']),
            $this->node('none', 'data.number', ['value' => 'no digits', 'style' => 'plain']),
        ], ['n' => 1234567.891]);

        $this->assertSame(['1,234,567.89', 1234567.891], array_values($this->step($numbers, 'thousands')->output));
        $this->assertSame(['฿1,234.50', 1234.5], array_values($this->step($numbers, 'baht')->output));
        $this->assertSame('1234.5', $this->step($numbers, 'euro')->output['value']);
        $this->assertSame('25.6%', $this->step($numbers, 'percent')->output['value']);
        $this->assertSame('forty-two', $this->step($numbers, 'words')->output['value']);
        $this->assertSame('"no digits" has no number in it.', $this->step($numbers, 'none')->error['message']);

        $text = $this->runChain($user, [
            $this->node('trim', 'data.text', ['value' => "  two   words \n", 'do' => 'trim']),
            $this->node('slug', 'data.text', ['value' => 'Khao Soi: the North!', 'do' => 'slug']),
            $this->node('replace', 'data.text', ['value' => 'Order 42 and 43', 'do' => 'replace', 'find' => '(\d+)', 'with' => '#$1', 'pattern' => 'yes']),
            $this->node('split', 'data.text', ['value' => 'a, b,, c', 'do' => 'split', 'by' => '']),
            $this->node('join', 'data.text', ['value' => '{{ node("split").value }}', 'do' => 'join', 'by' => '\n']),
            $this->node('cut', 'data.text', ['value' => 'A long sentence here', 'do' => 'cut', 'length' => 8]),
            $this->node('html', 'data.text', ['value' => '<p>Hi&nbsp;<b>you</b></p><p>Bye</p>', 'do' => 'strip_html']),
        ]);

        $this->assertAllWentThrough($text);
        $said = fn (string $key) => $this->step($text, $key)->output['value'];
        $this->assertSame(['two words', 'khao-soi-the-north', 'Order #42 and #43', ['a', 'b', 'c'], "a\nb\nc", 'A long…'], [$said('trim'), $said('slug'), $said('replace'), $said('split'), $said('join'), $said('cut')]);
        $this->assertSame("Hi\u{00A0}you\nBye", $said('html'));
    }

    public function test_text_is_hashed_signed_and_encoded()
    {
        $user = User::factory()->create();
        $secret = $this->connection($user, Services::WEBHOOK_SECRET, [], ['secret' => 'shh']);

        $run = $this->runChain($user, [
            $this->node('sha', 'data.hash', ['do' => 'hash', 'value' => 'hello', 'algorithm' => 'sha256', 'as' => 'hex']),
            $this->node('md5', 'data.hash', ['do' => 'hash', 'value' => 'hello', 'algorithm' => 'md5', 'as' => 'base64']),
            $this->node('sign', 'data.hash', ['do' => 'hmac', 'value' => '{{ trigger.body }}', 'algorithm' => 'sha256', 'secret' => $secret->ref_id, 'as' => 'hex']),
            $this->node('b64url', 'data.hash', ['do' => 'encode', 'value' => 'ไทย?', 'encoding' => 'base64url']),
            $this->node('back', 'data.hash', ['do' => 'decode', 'value' => '{{ node("b64url").value }}', 'encoding' => 'base64url']),
            $this->node('url', 'data.hash', ['do' => 'encode', 'value' => 'a b&c', 'encoding' => 'url']),
            $this->node('id', 'data.hash', ['do' => 'uuid']),
        ], ['body' => '{"a":1}']);

        $this->assertAllWentThrough($run);
        $value = fn (string $key) => $this->step($run, $key)->output['value'];
        $this->assertSame(hash('sha256', 'hello'), $value('sha'));
        $this->assertSame(base64_encode(md5('hello', true)), $value('md5'));
        $this->assertSame(hash_hmac('sha256', '{"a":1}', 'shh'), $value('sign'));
        $this->assertSame(['4LmE4LiX4LiiPw', 'ไทย?', 'a%20b%26c'], [$value('b64url'), $value('back'), $value('url')]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $value('id'));
        $this->assertStringNotContainsString('shh', (string) json_encode($run->steps()->get()->toArray()));

        $bad = $this->runChain($user, [$this->node('hex', 'data.hash', ['do' => 'decode', 'value' => 'zz', 'encoding' => 'hex'])]);
        $this->assertSame('That is not hex text, so it can\'t be read back.', $this->step($bad, 'hex')->error['message']);
    }

    public function test_slack_and_discord_are_posted_to_through_their_webhooks_alone()
    {
        Http::fake([
            'https://hooks.slack.com/*' => Http::response('ok'),
            'https://discord.com/api/webhooks/*' => Http::response(['id' => '991', 'channel_id' => '55', 'timestamp' => '2026-10-17T05:00:00+00:00']),
        ]);
        $user = User::factory()->create();
        $slack = $this->connection($user, Services::SLACK, [], ['url' => 'https://hooks.slack.com/services/T0/B0/secret1']);
        $discord = $this->connection($user, Services::DISCORD, [], ['url' => 'https://discord.com/api/webhooks/123/secret2']);

        $run = $this->runChain($user, [
            $this->node('slack', 'slack.send', ['connection' => $slack->ref_id, 'text' => 'New order from {{ trigger.name }}', 'blocks' => '[{"type": "section", "text": {"type": "mrkdwn", "text": "*{{ trigger.name }}*"}}]', 'unsure' => 'ask']),
            $this->node('discord', 'discord.send', ['connection' => $discord->ref_id, 'content' => 'Hi {{ trigger.name }}', 'username' => 'Shop', 'embeds' => '{"title": "Order 42"}', 'unsure' => 'ask']),
        ], ['name' => 'Ada']);

        $this->assertAllWentThrough($run);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hooks.slack.com/services/T0/B0/secret1'
            && $request->data() === ['text' => 'New order from Ada', 'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*Ada*']]]]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://discord.com/api/webhooks/123/secret2?wait=true'
            && $request->data() === ['content' => 'Hi Ada', 'username' => 'Shop', 'embeds' => [['title' => 'Order 42']]]);
        $this->assertSame(['sent' => true], $this->step($run, 'slack')->output);
        $this->assertSame(['id' => '991', 'channel_id' => '55', 'sent_at' => '2026-10-17T05:00:00+00:00'], $this->step($run, 'discord')->output);
        $this->assertStringNotContainsString('secret1', (string) json_encode($run->steps()->get()->toArray()));

        // Only to their own hosts: a connection made otherwise is refused, and never posted to
        $this->assertTrue(Validator::make(['secrets' => ['url' => 'https://evil.example.com/services/x']], Services::rules(Services::SLACK))->fails());
        $this->assertTrue(Validator::make(['secrets' => ['url' => 'https://discord.com/api/webhooks/1/x']], Services::rules(Services::DISCORD))->passes());
        $odd = $this->connection($user, Services::SLACK, [], ['url' => 'https://evil.example.com/hook']);
        $refused = $this->runChain($user, [$this->node('slack', 'slack.send', ['connection' => $odd->ref_id, 'text' => 'Hi', 'unsure' => 'ask'])]);
        $this->assertSame('"Slack" is not a Slack webhook\'s address.', $this->step($refused, 'slack')->error['message']);
        Http::assertSentCount(2);
    }

    public function test_an_email_goes_through_the_owners_own_mail_server_once()
    {
        $sent = new class extends AbstractTransport
        {
            /** @var list<array{string, int, string, ?string}> */
            public array $made = [];

            /** @var list<Email> */
            public array $emails = [];

            protected function doSend(SentMessage $message): void
            {
                $email = $message->getOriginalMessage();
                $this->emails[] = $email instanceof Email ? $email : throw new \LogicException('Not an email.');
            }

            public function __toString(): string
            {
                return 'test://';
            }
        };
        $transports = new class($sent) extends SmtpTransports
        {
            public function __construct(private readonly AbstractTransport $sent) {}

            public function for(string $host, int $port, string $security, ?string $username, ?string $password): AbstractTransport
            {
                // The real checks, without the real server
                parent::for($host, $port, $security, $username, $password);
                $this->sent->made[] = [$host, $port, $security, $username];

                return $this->sent;
            }
        };
        $this->app->instance(SmtpTransports::class, $transports);
        $user = User::factory()->create();
        $smtp = $this->connection($user, Services::SMTP, ['host' => 'smtp.example.com', 'port' => '587', 'security' => 'tls', 'username' => 'shop@example.com', 'from' => 'shop@example.com', 'from_name' => 'The Shop'], ['password' => 'app-pass']);

        $run = $this->runChain($user, [
            $this->node('mail', 'mail.send', ['connection' => $smtp->ref_id, 'to' => '{{ trigger.email }}, Bo <bo@example.com>', 'cc' => '', 'bcc' => 'boss@example.com', 'reply_to' => 'help@example.com', 'subject' => 'Order {{ trigger.order }}', 'body' => "**Thanks**, {{ trigger.name }}!\n\n<script>x</script>", 'format' => 'markdown', 'unsure' => 'ask']),
        ], ['email' => 'ada@example.com', 'order' => 42, 'name' => 'Ada']);

        $this->assertAllWentThrough($run);
        $this->assertSame([['smtp.example.com', 587, 'tls', 'shop@example.com']], $sent->made);
        $email = $sent->emails[0];
        $this->assertSame(['"The Shop" <shop@example.com>'], array_map(fn ($one) => $one->toString(), $email->getFrom()));
        $this->assertSame(['ada@example.com', 'bo@example.com'], array_map(fn ($one) => $one->getAddress(), $email->getTo()));
        $this->assertSame(['boss@example.com', 'help@example.com', 'Order 42'], [$email->getBcc()[0]->getAddress(), $email->getReplyTo()[0]->getAddress(), $email->getSubject()]);
        // Markdown made HTML, with what is written as HTML in it kept as words
        $this->assertStringContainsString('<strong>Thanks</strong>, Ada!', (string) $email->getHtmlBody());
        $this->assertStringContainsString('&lt;script&gt;', (string) $email->getHtmlBody());
        $output = $this->step($run, 'mail')->output;
        $this->assertSame([['ada@example.com', 'bo@example.com'], 'Order 42'], [$output['to'], $output['subject']]);
        $this->assertSame($output['message_id'], $email->getHeaders()->get('Message-ID')?->getBodyAsString() === "<{$output['message_id']}>" ? $output['message_id'] : null);
        $this->assertStringNotContainsString('app-pass', (string) json_encode($run->steps()->get()->toArray()));

        $bad = $this->runChain($user, [$this->node('mail', 'mail.send', ['connection' => $smtp->ref_id, 'to' => 'not an address', 'subject' => 'Hi', 'body' => 'Hi', 'format' => 'text', 'unsure' => 'ask'])]);
        $this->assertSame('"not an address" in "To" is not an email address.', $this->step($bad, 'mail')->error['message']);

        // Only a server on the public internet, on a mail port
        RemoteDownload::resolveUsing(fn () => ['192.168.1.10']);
        $inside = $this->runChain($user, [$this->node('mail', 'mail.send', ['connection' => $smtp->ref_id, 'to' => 'ada@example.com', 'subject' => 'Hi', 'body' => 'Hi', 'format' => 'text', 'unsure' => 'ask'])]);
        $this->assertStringContainsString('not on the public internet', $this->step($inside, 'mail')->error['message']);
        $this->assertTrue(Validator::make(['settings' => ['host' => 'smtp.example.com', 'port' => '3306', 'from' => 'a@example.com']], Services::rules(Services::SMTP))->fails());
        $this->assertCount(1, $sent->emails);
    }
}
