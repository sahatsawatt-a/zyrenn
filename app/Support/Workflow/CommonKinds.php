<?php

namespace App\Support\Workflow;

/**
 * Steps most workflows want sooner or later, beside the ones NodeTypes and
 * ServiceKinds list: a feed read, only what is new, CSV, dates, numbers and
 * text written as wanted, hashes and signatures -- and a message to Slack,
 * to Discord, or by email. Each in the group of what it works with.
 *
 * @phpstan-import-type Setting from NodeTypes
 * @phpstan-import-type NodeType from NodeTypes
 */
final class CommonKinds
{
    /**
     * @return list<NodeType>
     */
    public static function all(): array
    {
        return [...self::web(), ...self::data(), ...self::messages()];
    }

    /**
     * @return list<NodeType>
     */
    private static function web(): array
    {
        return [
            self::kind('feed.read', Steps\FeedRead::class, 'Read a feed', 'rss', 'web', 'Reads a news feed -- RSS, Atom or JSON Feed -- at a public address. Gives out title and link (the feed\'s own), items, newest first -- each with its id, title, link, summary (plain words), content (as the feed has it, often HTML), published (ISO 8601), author, categories and picture -- and count. With "Only new ones" and "Every ..." before it, it is a feed\'s trigger: each run gives only what came since.', [
                ['name' => 'url', 'label' => 'Feed address', 'type' => 'template', 'required' => true, 'placeholder' => 'https://example.com/feed.xml'],
                ['name' => 'only_new', 'label' => 'Give out', 'type' => 'choice', 'required' => true, 'default' => 'all', 'options' => ['all' => 'Every item it has', 'new' => 'Only new ones: none given out before'], 'help' => 'New ones are remembered for this step on live runs. A test run shows what is new without using it up.'],
                ['name' => 'count', 'label' => 'At most', 'type' => 'number', 'required' => false, 'default' => 20, 'help' => 'At most '.Steps\FeedRead::MOST.'.'],
            ]),
        ];
    }

    /**
     * @return list<NodeType>
     */
    private static function data(): array
    {
        $language = ['name' => 'language', 'label' => 'Language', 'type' => 'template', 'required' => false, 'placeholder' => 'en', 'help' => 'Two letters: en, th, ja. Left empty, English.'];

        return [
            self::kind('data.dedupe', Steps\OnlyNew::class, 'Only new items', 'list-checks', 'data', 'Of a list, gives out only the items not seen before -- each told apart by a part of it, or by the whole item. Gives out items, count, and left_out: how many were dropped.', [
                ['name' => 'items', 'label' => 'Items', 'type' => 'formula', 'required' => true, 'placeholder' => 'node("feed").items'],
                ['name' => 'by', 'label' => 'Told apart by', 'type' => 'text', 'required' => false, 'placeholder' => 'id', 'help' => 'The part of each item that is its own: id, link, email -- a.b for a part within one. Left empty, the whole item.'],
                ['name' => 'across', 'label' => 'Seen before', 'type' => 'choice', 'required' => true, 'default' => 'runs', 'options' => ['runs' => 'In any run before (remembered)', 'list' => 'Only earlier in this list'], 'help' => 'Remembered on live runs, the newest '.Seen::MOST.'. A test run shows what is new without using it up.'],
            ]),
            self::kind('data.csv', Steps\CsvStep::class, 'CSV', 'sheet', 'data', 'Reads CSV text into rows, or writes rows out as CSV text. Reading gives out rows -- each a record named by the first row, or a list when it names none -- columns and count; writing gives out text, columns and count.', [
                ['name' => 'do', 'label' => 'Do', 'type' => 'choice', 'required' => true, 'default' => 'read', 'options' => ['read' => 'Read CSV into rows', 'write' => 'Write rows as CSV']],
                ['name' => 'text', 'label' => 'CSV', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("file").text }}', 'when' => ['do' => 'read']],
                ['name' => 'rows', 'label' => 'Rows', 'type' => 'formula', 'required' => false, 'placeholder' => 'node("find").rows', 'help' => 'A list of records -- each name a column -- or of lists.', 'when' => ['do' => 'write']],
                ['name' => 'separator', 'label' => 'Between values', 'type' => 'choice', 'required' => true, 'default' => 'comma', 'options' => ['comma' => 'A comma', 'semicolon' => 'A semicolon', 'tab' => 'A tab', 'bar' => 'A bar |', 'guess' => 'Whichever the first line has most of']],
                ['name' => 'header', 'label' => 'First row', 'type' => 'choice', 'required' => true, 'default' => 'names', 'options' => ['names' => 'Names the columns', 'none' => 'Is a row like the rest']],
            ]),
            self::kind('data.date', Steps\DateStep::class, 'Date and time', 'calendar-clock', 'data', 'Reads a date, moves it on or back, shows it in a time zone and writes it in a format and a language. Gives out value (as written), iso, date, time, weekday, unix (seconds since 1970) and zone.', [
                ['name' => 'value', 'label' => 'Date', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.created_at }}', 'help' => 'Left empty, now.'],
                ['name' => 'from', 'label' => 'Written as', 'type' => 'choice', 'required' => true, 'default' => 'auto', 'options' => ['auto' => 'Any usual way', 'pattern' => 'A pattern of my own', 'unix' => 'Seconds since 1970', 'unix_ms' => 'Milliseconds since 1970'], 'help' => 'Any usual way: 2026-10-17, 17 Oct 2026 14:30, ISO 8601.'],
                ['name' => 'pattern_in', 'label' => 'Its pattern', 'type' => 'text', 'required' => false, 'placeholder' => 'd/m/Y H:i', 'help' => 'As PHP writes dates: d day, m month, Y year, H hour, i minute.', 'when' => ['from' => 'pattern']],
                ['name' => 'zone_in', 'label' => 'Its time zone', 'type' => 'timezone', 'required' => false, 'help' => 'For a date that does not say. Left empty, the app\'s own.'],
                ['name' => 'add', 'label' => 'Move it', 'type' => 'template', 'required' => false, 'placeholder' => '+3 days', 'help' => '+3 days, -2 hours, +1 month, next monday.'],
                ['name' => 'zone', 'label' => 'Show it in', 'type' => 'timezone', 'required' => false, 'help' => 'Left empty, its own time zone.'],
                ['name' => 'format', 'label' => 'Write it as', 'type' => 'choice', 'required' => true, 'default' => 'iso', 'options' => ['iso' => 'ISO 8601: 2026-10-17T14:30:00+07:00', 'date' => '2026-10-17', 'datetime' => '2026-10-17 14:30', 'long' => '17 October 2026', 'long_time' => '17 October 2026, 14:30', 'short' => '17/10/2026', 'us' => '10/17/2026', 'weekday' => 'Friday', 'time' => '14:30', 'relative' => 'From now: 3 days ago, in 2 hours', 'unix' => 'Seconds since 1970', 'custom' => 'A pattern of my own']],
                ['name' => 'pattern', 'label' => 'Pattern', 'type' => 'text', 'required' => false, 'placeholder' => 'l j F Y', 'when' => ['format' => 'custom']],
                $language,
            ]),
            self::kind('data.number', Steps\NumberStep::class, 'Format a number', 'calculator', 'data', 'Writes a number for people -- with thousands marked, as money, as a percentage, or in words -- as a language writes it; text with a number in it ("฿1,234.50") is read first. Gives out value (as written) and number.', [
                ['name' => 'value', 'label' => 'Number', 'type' => 'template', 'required' => true, 'placeholder' => '{{ input.total }}'],
                ['name' => 'style', 'label' => 'Write it', 'type' => 'choice', 'required' => true, 'default' => 'thousands', 'options' => ['thousands' => 'With thousands marked: 1,234.50', 'plain' => 'Plain: 1234.50', 'currency' => 'As money: ฿1,234.50', 'percent' => 'As a percentage: 0.25 is 25%', 'words' => 'In words: one thousand two hundred']],
                ['name' => 'decimals', 'label' => 'Decimal places', 'type' => 'number', 'required' => false, 'default' => 2],
                ['name' => 'currency', 'label' => 'Currency', 'type' => 'template', 'required' => false, 'placeholder' => 'THB', 'help' => 'Three letters: THB, USD, EUR.', 'when' => ['style' => 'currency']],
                $language,
            ]),
            self::kind('data.text', Steps\TextStep::class, 'Change text', 'type', 'data', 'Changes text one way: trims it, puts it in upper or lower case or as a title, makes a slug, replaces something in it, splits it into a list, joins a list, cuts it short, or takes out its HTML. Gives out value -- a list, with count, when split.', [
                ['name' => 'value', 'label' => 'Text', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.text }}'],
                ['name' => 'do', 'label' => 'Do', 'type' => 'choice', 'required' => true, 'default' => 'trim', 'options' => ['trim' => 'Trim: no spaces at the ends, one between words', 'upper' => 'UPPER CASE', 'lower' => 'lower case', 'title' => 'Title Case', 'slug' => 'A slug: title-case-like-this', 'replace' => 'Replace', 'split' => 'Split into a list', 'join' => 'Join a list into text', 'cut' => 'Cut short', 'strip_html' => 'Take out HTML']],
                ['name' => 'find', 'label' => 'Find', 'type' => 'template', 'required' => false, 'when' => ['do' => 'replace']],
                ['name' => 'with', 'label' => 'Put instead', 'type' => 'template', 'required' => false, 'help' => 'With a pattern, $1 is what its first ( ) found.', 'when' => ['do' => 'replace']],
                ['name' => 'pattern', 'label' => 'Find is', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'Words, as they are', 'yes' => 'A pattern (a regular expression)'], 'when' => ['do' => 'replace']],
                ['name' => 'by', 'label' => 'Between them', 'type' => 'text', 'required' => false, 'placeholder' => ',', 'help' => '\n for a new line. Left empty, a comma (and a space when joining).', 'when' => ['do' => ['split', 'join']]],
                ['name' => 'length', 'label' => 'At most, in letters', 'type' => 'number', 'required' => false, 'default' => 100, 'when' => ['do' => 'cut']],
            ]),
            self::kind('data.hash', Steps\HashStep::class, 'Hash and encode', 'fingerprint', 'data', 'Makes a hash of text, an HMAC signature with a secret -- what a service checks a request by -- encodes or decodes base64, base64url, hex or URL, or makes a new UUID. Gives out value.', [
                ['name' => 'do', 'label' => 'Do', 'type' => 'choice', 'required' => true, 'default' => 'hash', 'options' => ['hash' => 'A hash', 'hmac' => 'An HMAC signature', 'encode' => 'Encode', 'decode' => 'Decode', 'uuid' => 'A new UUID']],
                ['name' => 'value', 'label' => 'Text', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.body }}', 'when' => ['do' => ['hash', 'hmac', 'encode', 'decode']]],
                ['name' => 'algorithm', 'label' => 'Algorithm', 'type' => 'choice', 'required' => true, 'default' => 'sha256', 'options' => Steps\HashStep::ALGORITHMS, 'when' => ['do' => ['hash', 'hmac']]],
                ['name' => 'secret', 'label' => 'Secret', 'type' => 'connection', 'required' => false, 'services' => ['webhook_secret'], 'help' => 'Kept as a "Webhook secret" connection.', 'when' => ['do' => 'hmac']],
                ['name' => 'as', 'label' => 'Written as', 'type' => 'choice', 'required' => true, 'default' => 'hex', 'options' => ['hex' => 'Hex', 'base64' => 'Base64'], 'when' => ['do' => ['hash', 'hmac']]],
                ['name' => 'encoding', 'label' => 'Encoding', 'type' => 'choice', 'required' => true, 'default' => 'base64', 'options' => ['base64' => 'Base64', 'base64url' => 'Base64url', 'hex' => 'Hex', 'url' => 'URL (percent)'], 'when' => ['do' => ['encode', 'decode']]],
            ]),
        ];
    }

    /**
     * @return list<NodeType>
     */
    private static function messages(): array
    {
        $unsure = 'When its worker stops partway, the message may have gone out already.';

        return [
            self::kind('slack.send', Steps\WebhookSend::class, 'Send to Slack', 'hash', 'slack', 'Posts a message to a Slack channel, through its incoming webhook. Gives out sent.', [
                ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['slack'], 'help' => 'A Slack connection: one channel\'s incoming webhook.'],
                ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => false, 'placeholder' => 'New order from {{ trigger.name }}', 'help' => 'Slack\'s own marks: *bold*, _italic_, <https://example.com|a link>. With blocks, what a notification says.'],
                ['name' => 'blocks', 'label' => 'Blocks', 'type' => 'template', 'required' => false, 'placeholder' => '[{"type": "section", "text": {"type": "mrkdwn", "text": "*Hello*"}}]', 'help' => 'Optional: Slack\'s Block Kit, as a JSON list.'],
            ], sends: true, unsure: $unsure),
            self::kind('discord.send', Steps\WebhookSend::class, 'Send to Discord', 'message-square', 'discord', 'Posts a message to a Discord channel, through its webhook. Gives out id, channel_id and sent_at.', [
                ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['discord'], 'help' => 'A Discord connection: one channel\'s webhook.'],
                ['name' => 'content', 'label' => 'Message', 'type' => 'template', 'required' => false, 'placeholder' => 'New order from {{ trigger.name }}', 'help' => 'Up to 2000 letters. Discord\'s own marks: **bold**, *italic*, [a link](https://example.com).'],
                ['name' => 'username', 'label' => 'Post as', 'type' => 'template', 'required' => false, 'placeholder' => 'Shop bot', 'help' => 'Left empty, the webhook\'s own name.'],
                ['name' => 'avatar', 'label' => 'Picture to post with', 'type' => 'template', 'required' => false, 'placeholder' => 'https://example.com/bot.png'],
                ['name' => 'embeds', 'label' => 'Embeds', 'type' => 'template', 'required' => false, 'placeholder' => '[{"title": "Order 42", "description": "2 items", "color": 5814783}]', 'help' => 'Optional: up to ten, as a JSON list.'],
            ], sends: true, unsure: $unsure),
            self::kind('mail.send', Steps\MailSend::class, 'Send an email', 'mail', 'mail', 'Sends an email through a mail server of your own, by SMTP. Gives out message_id, to and subject.', [
                ['name' => 'connection', 'label' => 'Mail server', 'type' => 'connection', 'required' => true, 'services' => ['smtp'], 'help' => 'An "Email (SMTP)" connection: the server, and whom it is sent from.'],
                ['name' => 'to', 'label' => 'To', 'type' => 'template', 'required' => true, 'placeholder' => 'ada@example.com, Bo <bo@example.com>', 'help' => 'One or more, with commas between. At most '.Steps\MailSend::MOST_TO.' people, with cc and bcc.'],
                ['name' => 'cc', 'label' => 'Cc', 'type' => 'template', 'required' => false],
                ['name' => 'bcc', 'label' => 'Bcc', 'type' => 'template', 'required' => false],
                ['name' => 'reply_to', 'label' => 'Replies go to', 'type' => 'template', 'required' => false],
                ['name' => 'subject', 'label' => 'Subject', 'type' => 'template', 'required' => true, 'placeholder' => 'Your order {{ trigger.order }}'],
                ['name' => 'body', 'label' => 'Message', 'type' => 'template', 'required' => true],
                ['name' => 'format', 'label' => 'Written in', 'type' => 'choice', 'required' => true, 'default' => 'text', 'options' => ['text' => 'Plain text', 'markdown' => 'Markdown, sent as HTML', 'html' => 'HTML']],
            ], sends: true, unsure: $unsure),
        ];
    }

    /**
     * One kind, with one way in and one out, in `$group`. One that sends is
     * not simply made again, and says what happens instead.
     *
     * @param  list<Setting>  $settings
     * @param  class-string<Steps\Step>  $runs
     * @return NodeType
     */
    private static function kind(string $type, string $runs, string $label, string $icon, string $group, string $description, array $settings, bool $sends = false, ?string $unsure = null): array
    {
        return [
            'type' => $type,
            'runs' => $runs,
            'version' => 1,
            'label' => $label,
            'group' => $group,
            'icon' => $icon,
            'description' => $description,
            'trigger' => false,
            ...$sends ? ['repeatable' => false] : [],
            'inputs' => [NodeTypes::IN],
            'outputs' => [NodeTypes::OUT],
            'config' => [
                ...$settings,
                ...$sends ? [[...ServiceKinds::UNSURE, 'help' => $unsure]] : [],
            ],
        ];
    }
}
