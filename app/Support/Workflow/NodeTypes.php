<?php

namespace App\Support\Workflow;

use App\Models\Chat\ChatRoom;

/**
 * Every kind of step a workflow can have: what it is called, where it can
 * be connected, and what it is set up with. The editor draws its palette and
 * forms from this, and a definition is checked against it, so the two never
 * disagree about what a step is.
 *
 * A step's version changes when what it is set up with, or what it gives
 * out, changes in a way an older run would not understand. A run keeps the
 * versions it started with.
 *
 * Settings come in a few kinds:
 *   text      written as it is
 *   template  text with {{ formulas }} worked out when the step runs
 *   formula   one formula, e.g. input.total > 100
 *   json      a JSON object, written out
 *   choice    one of a few options
 *   fields    named values, each a template
 *   number    a whole number
 *   cron      when, as a cron expression: "0 9 * * 1-5"
 *   token     a random secret made once, e.g. a webhook's address
 *   connection  one of the owner's connections, of the services listed
 *   room      a chat room: chosen, or a template naming one ({{ node("find").room }})
 *   table     one of the owner's tables
 *   folder    a folder of the owner's Drive, by its path; a template too, and made when not there
 *   column    a column of the step's table
 *   project   a project whose tickets these are: left empty, the workflow's own
 *   model     a model the step's AI connection offers (any other may be typed)
 *   timezone  a time zone, e.g. Asia/Bangkok
 *
 * A "fields" setting with 'names' => 'columns' names each value by one of
 * the step's table's columns; with 'labels', a name is any words -- what a
 * button says -- rather than something a formula can read. A setting with
 * 'when' => [other => value] matters only while that other setting is so
 * (one of several, given a list; or, given true, set at all), and the
 * editor shows it only then. A room setting with 'linked' also
 * offers "any chat linked to Telegram / LINE"; a connection setting with
 * 'linked', "the chat's own" -- the one the run's chat is linked through.
 * A folder setting left empty is the top of the Drive -- or, with
 * 'anywhere', every folder.
 *
 * A step whose output later steps read by part may say what it gives out
 * when empty ('empty'): failing with "carry on", it gives that, with its
 * error, so node("who").name reads as nothing rather than failing too.
 *
 * A step that sends something outside is not "repeatable": when its worker
 * dies partway it may have gone out already, so it is not simply run again
 * (its "unsure" setting says what happens instead).
 *
 * Every call a service offers -- SerpApi, Tavily, Telegram, LINE, WhatsApp,
 * LinkedIn, Facebook, Instagram, Threads -- is a kind of its own, in that service's
 * group; most of them are listed in ServiceKinds.
 *
 * @phpstan-type Setting array{name: string, label: string, type: string, required: bool, default?: mixed, help?: string, placeholder?: string, options?: array<string, string>, services?: list<string>, names?: string, when?: array<string, string|bool|list<string>>, linked?: bool, ways?: bool, anywhere?: bool, labels?: bool, drive?: string, does?: list<array<string, string>>, places?: array{by: string, picture: string, layouts: array<string, list<array{int, int, int, int}>>}}
 * @phpstan-type NodeType array{type: string, version: int, label: string, group: string, icon: string, description: string, trigger: bool, inputs: list<string>, outputs: list<string>, config: list<Setting>, repeatable?: bool, oldest?: int, empty?: array<string, null>, runs: class-string<Steps\Step>, kept?: array<int, class-string<Steps\Step>>}
 */
final class NodeTypes
{
    /** The one handle of a step with a single way in or out. */
    public const IN = 'in';

    public const OUT = 'out';

    /** The way a step carries on when what it was to send could not go, for another to send it. */
    public const UNSENT = 'unsent';

    /** A Switch's way when none of its others is taken. */
    public const OTHERWISE = 'otherwise';

    /** The longest a webhook keeps its caller waiting for a run's answer, in seconds. */
    public const MOST_ANSWER_WAIT = 55;

    /** How a chat's messages that come quickly are run (Engine::start). */
    private const PER_CHAT = [
        'name' => 'per_chat',
        'label' => 'When messages come quickly',
        'type' => 'choice',
        'required' => true,
        'default' => 'each',
        'options' => [
            'each' => 'Run each at once',
            'one' => 'One at a time per chat, in order',
            'latest' => 'Only the latest per chat: a newer message stops the run before',
        ],
        'help' => 'Per chat: one person writing three lines in a row is answered in order, or only once.',
    ];

    /**
     * Every kind of step, by its type.
     *
     * @return array<string, NodeType>
     */
    public static function all(): array
    {
        return self::$all ??= self::build();
    }

    /**
     * Every kind of step, as the browser and MCP are shown them: without the
     * class that runs it.
     *
     * @return list<array<string, mixed>>
     */
    public static function shown(): array
    {
        return array_values(array_map(fn (array $type) => array_diff_key($type, ['runs' => true, 'kept' => true]), self::all()));
    }

    /**
     * @var array<string, NodeType>|null
     */
    private static ?array $all = null;

    /**
     * @return array<string, NodeType>
     */
    private static function build(): array
    {
        $types = [
            [
                'type' => 'trigger.manual',
                'runs' => Steps\ManualTrigger::class,
                'version' => 1,
                'label' => 'Run by hand',
                'group' => 'flow',
                'icon' => 'play',
                'description' => 'Starts a run when you press Run. What it gives out is the test data below.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    [
                        'name' => 'payload',
                        'label' => 'Test data',
                        'type' => 'json',
                        'required' => false,
                        'default' => "{\n}",
                        'help' => 'A JSON object. Later steps read it as trigger, e.g. {{ trigger.name }}.',
                    ],
                ],
            ],
            [
                'type' => 'trigger.call',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'When run by another workflow',
                'group' => 'flow',
                'icon' => 'corner-down-right',
                'description' => 'Starts a run when another workflow\'s "For each" runs this one for an item. Later steps read trigger.item, trigger.index and trigger.of.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [],
            ],
            [
                'type' => 'data.set',
                'runs' => Steps\SetData::class,
                'version' => 1,
                'label' => 'Set data',
                'group' => 'data',
                'icon' => 'braces',
                'description' => 'Gives out the values you name, each worked out from what came before.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    [
                        'name' => 'fields',
                        'label' => 'Values',
                        'type' => 'fields',
                        'required' => true,
                        'default' => [],
                        'help' => 'Each value can hold {{ formulas }}: {{ input.total * 1.07 }}, {{ node("fetch").name }}.',
                    ],
                ],
            ],
            [
                'type' => 'data.code',
                'runs' => Steps\RunCode::class,
                'version' => 1,
                'label' => 'Run code',
                'group' => 'data',
                'icon' => 'code',
                'description' => 'Runs code you write -- JavaScript, or Python where the app\'s admins allow it -- on what came before, and gives out what it returns. It can only work things out, with the libraries chosen for it: there is no network and no file for it to reach, and it is stopped when its time is up.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'language', 'label' => 'Language', 'type' => 'choice', 'required' => true, 'default' => 'javascript', 'options' => ['javascript' => 'JavaScript', 'python' => 'Python']],
                    [
                        'name' => 'code',
                        'label' => 'Code',
                        'type' => 'code',
                        'required' => true,
                        'default' => "// trigger, input and node(\"key\") are what formulas read.\n// What is returned is what the step gives out.\nreturn {\n    text: String(trigger.text ?? '').trim(),\n};\n",
                        // What a new step starts with in each language, swapped while it is untouched
                        'starts' => [
                            'javascript' => "// trigger, input and node(\"key\") are what formulas read.\n// What is returned is what the step gives out.\nreturn {\n    text: String(trigger.text ?? '').trim(),\n};\n",
                            'python' => "# trigger, input and node(\"key\") are what formulas read.\n# What result holds (or the last line) is what the step gives out.\nresult = {\n    \"text\": str(trigger.get(\"text\", \"\")).strip(),\n}\n",
                        ],
                        'help' => 'JavaScript: the body of a function, giving out what it returns. Python: giving out what result holds, or its last line. A record is given out as it is; anything else as "value". console.log, or print, writes in the run\'s log.',
                    ],
                    ['name' => 'libraries', 'label' => 'Libraries', 'type' => 'libraries', 'required' => false, 'default' => [], 'help' => 'What it may use, as the app\'s admins added them: require("lodash") in JavaScript, import numpy in Python.'],
                    ['name' => 'seconds', 'label' => 'Stop it after, in seconds', 'type' => 'number', 'required' => false, 'default' => Steps\RunCode::SECONDS, 'help' => 'At most what the app\'s admins allow: 30, unless they say more.'],
                ],
            ],
            [
                'type' => 'logic.if',
                'runs' => Steps\IfCondition::class,
                'version' => 1,
                'label' => 'If',
                'group' => 'flow',
                'icon' => 'split',
                'description' => 'Carries on down "true" or "false", by a formula. The other way is skipped.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => ['true', 'false'],
                'config' => [
                    [
                        'name' => 'condition',
                        'label' => 'Condition',
                        'type' => 'formula',
                        'required' => true,
                        'placeholder' => 'input.total > 100',
                        'help' => 'A formula, without {{ }}: input.status = "paid" and input.total > 100.',
                    ],
                ],
            ],
            [
                'type' => 'logic.switch',
                'runs' => Steps\SwitchStep::class,
                'version' => 1,
                'label' => 'Switch',
                'group' => 'flow',
                'icon' => 'signpost',
                'description' => 'Carries on down the first way whose test is true, or "otherwise" when none is. The other ways are skipped.',
                'trigger' => false,
                'inputs' => [self::IN],
                // Its ways out are its own: one for each way named, then "otherwise" (outputsOf)
                'outputs' => [self::OTHERWISE],
                'config' => [
                    [
                        'name' => 'ways',
                        'label' => 'Ways',
                        'type' => 'fields',
                        'required' => true,
                        'ways' => true,
                        'help' => 'Each way is named, and taken when its formula is true -- the first that is, in this order: input.total > 100.',
                    ],
                ],
            ],
            [
                'type' => 'logic.merge',
                'runs' => Steps\MergeStep::class,
                'version' => 1,
                'label' => 'Merge',
                'group' => 'flow',
                'icon' => 'merge',
                'description' => 'Brings ways back together: waits for every way in that is taken, or carries on with the first to arrive.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    [
                        'name' => 'wait',
                        'label' => 'Carry on',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'all',
                        'options' => [
                            'all' => 'Once every way in that is taken has come: gives out each by its key',
                            'first' => 'With the first to arrive: gives out what it gave; the rest are let go',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'logic.each',
                'runs' => Steps\ForEachStep::class,
                'version' => 1,
                'label' => 'For each',
                'group' => 'flow',
                'icon' => 'repeat',
                'description' => 'Runs another workflow once for each item of a list, a few at a time, and waits for them all. Gives out results: each item, how its run went, and what its last step gave.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    [
                        'name' => 'workflow',
                        'label' => 'Workflow to run',
                        'type' => 'workflow',
                        'required' => true,
                        'help' => 'One of your published workflows that starts "When run by another workflow".',
                    ],
                    [
                        'name' => 'items',
                        'label' => 'Items',
                        'type' => 'formula',
                        'required' => false,
                        'placeholder' => 'node("find").rows',
                        'help' => 'A list; each item is that run\'s trigger.item. Left empty, it runs once, for what this step was given.',
                    ],
                    [
                        'name' => 'at_once',
                        'label' => 'At once, at most',
                        'type' => 'number',
                        'required' => false,
                        'default' => 5,
                    ],
                ],
            ],
            [
                'type' => 'logic.delay',
                'runs' => Steps\Delay::class,
                'version' => 1,
                'label' => 'Delay',
                'group' => 'flow',
                'icon' => 'timer',
                'description' => 'Waits a while, then passes on what it was given. Nothing is held up meanwhile.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    [
                        'name' => 'amount',
                        'label' => 'How long',
                        'type' => 'template',
                        'required' => true,
                        'default' => '5',
                        'help' => 'A number, or a formula for one: {{ input.minutes }}. At most 30 days.',
                    ],
                    [
                        'name' => 'unit',
                        'label' => 'In',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'minutes',
                        'options' => ['seconds' => 'Seconds', 'minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days'],
                    ],
                ],
            ],
            [
                'type' => 'logic.approval',
                'runs' => Steps\Approval::class,
                'version' => 1,
                'label' => 'Ask for approval',
                'group' => 'flow',
                'icon' => 'user-check',
                'description' => 'Waits for someone who can edit the workflow to approve or reject, then carries on that way.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => ['approved', 'rejected'],
                'config' => [
                    [
                        'name' => 'message',
                        'label' => 'What to ask',
                        'type' => 'template',
                        'required' => true,
                        'default' => 'Go ahead?',
                        'placeholder' => 'Send the refund of {{ input.amount }} to {{ input.name }}?',
                    ],
                    [
                        'name' => 'expires',
                        'label' => 'If nobody answers',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'never',
                        'options' => ['never' => 'Wait for ever', '1h' => 'Reject after 1 hour', '1d' => 'Reject after 1 day', '7d' => 'Reject after 7 days'],
                    ],
                ],
            ],
            [
                'type' => 'output.log',
                'runs' => Steps\Log::class,
                'version' => 1,
                'label' => 'Log',
                'group' => 'data',
                'icon' => 'terminal',
                'description' => 'Writes a line in the run\'s log, and passes on what it was given.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    [
                        'name' => 'message',
                        'label' => 'Message',
                        'type' => 'template',
                        'required' => true,
                        'placeholder' => 'Order {{ input.id }} came to {{ input.total }}',
                    ],
                    [
                        'name' => 'level',
                        'label' => 'Level',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'info',
                        'options' => ['info' => 'Info', 'warning' => 'Warning', 'error' => 'Error'],
                    ],
                ],
            ],

            // --- Starting by themselves (published and switched on) ---
            [
                'type' => 'trigger.chat',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'Chat message',
                'group' => 'chat',
                'icon' => 'message-square',
                'description' => 'Starts a run when something is written in a chat room -- or when someone reads what an outside chat sent there.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'room', 'label' => 'Room', 'type' => 'room', 'required' => true, 'linked' => true, 'help' => 'One chat, or every chat linked to a chat on a service: Telegram, LINE, WhatsApp, Instagram. A note written among a project in a customer\'s chat is never heard. Later steps read the message as trigger.text, its author as trigger.message.author, a file sent with it in trigger.files, a place in trigger.location (trigger.kind says which it was: text, file or location), and a linked chat\'s other end as trigger.room.link.chat.'],
                    [
                        'name' => 'on',
                        'label' => 'When',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'message',
                        'options' => ['message' => 'Something is written', 'read' => 'Someone reads what an outside chat sent'],
                        'help' => 'Read: once for each message a workflow carried in from outside, when someone here first sees it. trigger.reply.read is LINE\'s token for marking it read there.',
                    ],
                    [
                        'name' => 'include',
                        'label' => 'Start for',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'people',
                        'options' => [
                            'people' => 'What people write',
                            'answers' => 'What people and workflows answer (not what came from outside)',
                            'outside' => 'Only what a customer sent, carried in from outside',
                            'all' => 'Everything, workflows\' messages too',
                        ],
                        'help' => 'A bridge sends what is answered out; an AI that answers customers hears only what they sent.',
                        'when' => ['on' => 'message'],
                    ],
                    self::PER_CHAT,
                ],
            ],
            [
                'type' => 'trigger.schedule',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'On a schedule',
                'group' => 'flow',
                'icon' => 'clock',
                'description' => 'Starts a run at the times given. A run still going when the next is due is left to finish; the next is skipped.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'cron', 'label' => 'When', 'type' => 'cron', 'required' => true, 'default' => '0 9 * * 1-5', 'help' => 'A cron line: minute, hour, day, month, weekday. "0 9 * * 1-5" is 9:00 on weekdays; "*/15 * * * *" every quarter hour.'],
                    ['name' => 'timezone', 'label' => 'Time zone', 'type' => 'timezone', 'required' => false, 'help' => 'Left empty, the app\'s own.'],
                ],
            ],
            [
                'type' => 'trigger.webhook',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'Webhook',
                'group' => 'web',
                'icon' => 'webhook',
                'description' => 'Starts a run when something is sent to its address. Later steps read trigger.body, trigger.headers and trigger.query.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'token', 'label' => 'Address', 'type' => 'token', 'required' => true, 'help' => 'Anyone with the address can start a run; keep it to the service that calls it.'],
                    [
                        'name' => 'verify',
                        'label' => 'Check it came from',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'none',
                        'options' => ['none' => 'Anyone with the address', 'hmac' => 'A sender that signs with a secret'],
                    ],
                    ['name' => 'secret', 'label' => 'Signing secret', 'type' => 'connection', 'required' => false, 'services' => ['webhook_secret'], 'when' => ['verify' => 'hmac']],
                    ['name' => 'header', 'label' => 'Signature header', 'type' => 'text', 'required' => false, 'default' => 'X-Hub-Signature-256', 'when' => ['verify' => 'hmac']],
                    ['name' => 'prefix', 'label' => 'Before the signature', 'type' => 'text', 'required' => false, 'default' => 'sha256=', 'help' => 'What the header has before the signature itself, if anything.', 'when' => ['verify' => 'hmac']],
                    [
                        'name' => 'encoding',
                        'label' => 'Signature written as',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'hex',
                        'options' => ['hex' => 'Hex', 'base64' => 'Base64'],
                        'when' => ['verify' => 'hmac'],
                    ],
                    [
                        'name' => 'handshake',
                        'label' => 'Setting it up',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'none',
                        'options' => ['none' => 'Nothing to answer', 'whatsapp' => 'Answer WhatsApp\'s check', 'slack' => 'Answer Slack\'s check'],
                    ],
                    ['name' => 'dedupe', 'label' => 'Its own id', 'type' => 'text', 'required' => false, 'placeholder' => 'event_id', 'help' => 'Where in the body the sender puts an id of its own, so the same thing sent twice starts one run. Dots go deeper: data.id.'],
                    [
                        'name' => 'answer',
                        'label' => 'Answer the caller',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'at_once',
                        'options' => ['at_once' => 'At once: that it was taken', 'result' => 'With what the run gives'],
                        'help' => 'With what the run gives: the caller waits for an "Answer the webhook" step, or, without one, for how the run went -- {ok, run, status} -- or else for the time to be up.',
                    ],
                    ['name' => 'wait', 'label' => 'Wait at most', 'type' => 'number', 'required' => false, 'default' => 25, 'help' => 'Seconds, at most '.self::MOST_ANSWER_WAIT.'. Past it, the caller is answered with the run, still going.', 'when' => ['answer' => 'result']],
                ],
            ],
            [
                'type' => 'trigger.telegram',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'Telegram message',
                'group' => 'telegram',
                'icon' => 'send',
                'description' => 'Starts a run when the bot is sent a message. Later steps read trigger.text, trigger.chat.id and trigger.sender.name; a picture or file it carried is in trigger.files, for "Telegram: get the file".',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Bot', 'type' => 'connection', 'required' => true, 'services' => ['telegram']],
                    [
                        'name' => 'on',
                        'label' => 'When',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'message',
                        'options' => [
                            'message' => 'A message is sent, or a button pressed',
                            'button' => 'A button is pressed',
                            'channel' => 'Something is posted in a channel',
                            'member' => 'Someone joins or leaves, or the bot is added',
                            'reaction' => 'Someone reacts to a message',
                            'poll' => 'Someone answers a poll',
                            'inline' => 'Someone asks the bot inline (@bot …)',
                            'payment' => 'A payment is about to be made, or has been',
                            'any' => 'Any of these',
                        ],
                        'help' => 'A press reads as trigger.text and trigger.button; a place sent, trigger.location; who joined or left, trigger.member; a reaction, trigger.reaction; a payment, trigger.checkout then trigger.payment. trigger.type says which it was. A bot hears reactions only where it looks after the chat.',
                    ],
                    [
                        'name' => 'chats',
                        'label' => 'From',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'any',
                        'options' => ['any' => 'Any chat', 'private' => 'One-to-one chats', 'group' => 'Groups'],
                    ],
                    ['name' => 'command', 'label' => 'Only messages starting', 'type' => 'text', 'required' => false, 'placeholder' => '/expense', 'help' => 'Left empty, every message. In a group the bot sees only commands and mentions unless its privacy mode is off in @BotFather.'],
                    self::PER_CHAT,
                ],
            ],
            [
                'type' => 'trigger.line',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'LINE message',
                'group' => 'line',
                'icon' => 'message-circle',
                'description' => 'Starts a run when the LINE channel is sent a message. Later steps read trigger.text, trigger.chat.id and trigger.sender.id; "LINE: who wrote" finds their name, and "LINE: get the file" fetches a picture or file it carried (trigger.files).',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['line']],
                    [
                        'name' => 'on',
                        'label' => 'When',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'message',
                        'options' => [
                            'message' => 'A message is sent, or a button tapped',
                            'button' => 'A button is tapped',
                            'follow' => 'Someone adds the channel as a friend',
                            'unfollow' => 'Someone blocks the channel',
                            'join' => 'The channel is added to a group',
                            'leave' => 'The channel is taken out of a group',
                            'member' => 'Someone joins or leaves a group',
                            'unsend' => 'A message is taken back',
                            'any' => 'Any of these',
                        ],
                        'help' => 'A tapped button reads as trigger.text and trigger.button; a place sent, trigger.location; a sticker, trigger.sticker; who joined or left, trigger.members. trigger.type says which it was. A rich menu turned to another by one of its tabs counts as a button tapped, not as a message: trigger.menu says which it turned to.',
                    ],
                    [
                        'name' => 'chats',
                        'label' => 'From',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'any',
                        'options' => ['any' => 'Any chat', 'private' => 'One-to-one chats', 'group' => 'Groups and rooms'],
                    ],
                    self::PER_CHAT,
                ],
            ],

            [
                'type' => 'trigger.whatsapp',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'WhatsApp message',
                'group' => 'whatsapp',
                'icon' => 'message-circle',
                'description' => 'Starts a run when the business number is sent a message on WhatsApp. Later steps read trigger.text, trigger.chat.id (their number) and trigger.sender.name; "WhatsApp: get the file" fetches a picture or file it carried (trigger.files).',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Number', 'type' => 'connection', 'required' => true, 'services' => ['whatsapp'], 'help' => 'Its webhook address, shown on the connection, has to be set in the Meta app.'],
                    [
                        'name' => 'on',
                        'label' => 'When',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'message',
                        'options' => [
                            'message' => 'A message is sent, or a button pressed',
                            'button' => 'A button is pressed',
                            'reaction' => 'Someone reacts to a message',
                            'any' => 'Any of these',
                        ],
                        'help' => 'A pressed button reads as trigger.text and trigger.button; a place sent, trigger.location; a reaction, trigger.reaction. trigger.type says which it was, and trigger.kind what was sent: text, image, video, audio, file, sticker, location.',
                    ],
                    self::PER_CHAT,
                ],
            ],

            [
                'type' => 'trigger.instagram',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'Instagram comment',
                'group' => 'instagram',
                'icon' => 'message-square',
                'description' => 'Starts a run when someone comments under one of the account\'s posts, or names the account in a caption or comment of their own. Later steps read trigger.text, trigger.sender.name, trigger.post.id and trigger.comment.id, which "Answer a comment on Instagram" takes. What the account writes itself starts nothing.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Account', 'type' => 'connection', 'required' => true, 'services' => ['instagram'], 'help' => 'One signed in with Instagram. Its webhook address, shown on the connection, has to be set in the Meta app, and Meta sends only once that app is live.'],
                    [
                        'name' => 'on',
                        'label' => 'When',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'comment',
                        'options' => [
                            'comment' => 'Someone comments under a post',
                            'mention' => 'The account is mentioned',
                            'any' => 'Either of these',
                        ],
                        'help' => 'trigger.type says which it was; trigger.kind where it was written: comment, reply (under another comment, trigger.comment.parent) or caption. A mention brings no words: only trigger.post.id and trigger.comment.id.',
                    ],
                ],
            ],

            [
                'type' => 'trigger.instagram_message',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'Instagram message',
                'group' => 'instagram',
                'icon' => 'message-circle',
                'description' => 'Starts a run when the account is sent a message on Instagram. Later steps read trigger.text and trigger.chat.id (who wrote, by the id Instagram gives this account for them), which "Send on Instagram" takes; "Instagram: who wrote" finds their name. A picture or recording it carried comes as its address (trigger.files), which "Save to Drive" takes. What the account writes itself starts nothing.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Account', 'type' => 'connection', 'required' => true, 'services' => ['instagram'], 'help' => 'One signed in with Instagram, allowed its messages. Its webhook address, shown on the connection, has to be set in the Meta app, subscribed to "messages", and Meta sends only once that app is live.'],
                    [
                        'name' => 'on',
                        'label' => 'When',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'message',
                        'options' => [
                            'message' => 'A message is sent, or a quick answer tapped',
                            'button' => 'A quick answer or a button is tapped',
                            'reaction' => 'Someone reacts to a message',
                            'unsend' => 'A message is taken back',
                            'any' => 'Any of these',
                        ],
                        'help' => 'A tap reads as trigger.text and trigger.button; a reaction, trigger.reaction; a story answered or naming the account, trigger.story. trigger.type says which it was, and trigger.kind what was sent: text, image, video, audio, file, share, reel, story_mention.',
                    ],
                    self::PER_CHAT,
                ],
            ],

            [
                'type' => 'trigger.threads',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'Threads reply',
                'group' => 'threads',
                'icon' => 'message-square',
                'description' => 'Starts a run when someone replies under one of the profile\'s posts, or when the profile publishes or deletes a post. Later steps read trigger.text, trigger.sender.name, trigger.url, trigger.post.id (the post the thread began with) and trigger.reply.to, which "Post on Threads" takes as "In answer to". A reply the profile writes itself starts nothing.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Profile', 'type' => 'connection', 'required' => true, 'services' => ['threads'], 'help' => 'Its webhook address, shown on the connection, has to be set in the Meta app, and Meta sends only once that app is live.'],
                    [
                        'name' => 'on',
                        'label' => 'When',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'reply',
                        'options' => [
                            'reply' => 'Someone replies under a post',
                            'publish' => 'The profile publishes a post',
                            'delete' => 'A post of the profile\'s is deleted',
                            'any' => 'Any of these',
                        ],
                        'help' => 'trigger.type says which it was. Started by a post published, a workflow that posts on Threads itself would start again each time.',
                    ],
                ],
            ],

            [
                'type' => 'trigger.channel',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'Custom channel message',
                'group' => 'channel',
                'icon' => 'message-circle',
                'description' => 'Starts a run when a platform of your own -- a shop\'s chat, a website\'s chat box -- sends a message to the connection\'s hook address. Later steps read trigger.text, trigger.chat.id, trigger.sender (id, name, picture), trigger.files (addresses), trigger.location and trigger.platform.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['channel'], 'help' => 'Its hook address and the shape it takes are shown on the connection.'],
                    self::PER_CHAT,
                ],
            ],

            [
                'type' => 'trigger.stripe',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'Something happens on Stripe',
                'group' => 'stripe',
                'icon' => 'credit-card',
                'description' => 'Starts a run when something happens on a Stripe account: a checkout or payment link paid, a payment made or failed, an invoice paid, a refund, a subscription started or ended. Later steps read trigger.type (the event), trigger.text (a line saying what happened), trigger.amount and trigger.currency, trigger.email, trigger.name, trigger.customer, trigger.reference (a checkout\'s own reference), trigger.metadata, trigger.payment, trigger.live and trigger.url (in the Dashboard). trigger.object is all Stripe sent about it.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Stripe', 'type' => 'connection', 'required' => true, 'services' => ['stripe'], 'help' => 'One with the webhook signing secret. Its webhook address, shown on the connection, is added in Stripe\'s Dashboard as an endpoint.'],
                    [
                        'name' => 'on',
                        'label' => 'When',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'checkout',
                        'options' => [
                            'checkout' => 'A checkout or payment link is paid',
                            'payment' => 'A payment comes in',
                            'failed' => 'A payment fails',
                            'invoice' => 'An invoice is paid',
                            'refund' => 'A payment is refunded',
                            'subscription' => 'A subscription starts, changes or ends',
                            'dispute' => 'A payment is disputed',
                            'named' => 'The events named here',
                            'any' => 'Anything Stripe sends',
                        ],
                        'help' => 'Stripe sends only the events picked on its endpoint.',
                    ],
                    ['name' => 'events', 'label' => 'Events', 'type' => 'text', 'required' => false, 'placeholder' => 'invoice.finalized, customer.*', 'help' => 'As Stripe names them, split by commas; .* for all of a kind.', 'when' => ['on' => 'named']],
                ],
            ],

            [
                'type' => 'trigger.github',
                'runs' => Steps\GivenTrigger::class,
                'version' => 1,
                'label' => 'Something happens on GitHub',
                'group' => 'github',
                'icon' => 'github',
                'description' => 'Starts a run when something happens in a repository: an issue opened, a pull request merged, a push, a comment, a release, an Actions run finished. Later steps read trigger.type (the event), trigger.action, trigger.repo, trigger.sender.login, trigger.text (a line saying what happened), trigger.url -- and, by the event, trigger.issue, trigger.pull, trigger.comment, trigger.push (branch, commits), trigger.release or trigger.run. trigger.body is all GitHub sent.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'GitHub', 'type' => 'connection', 'required' => true, 'services' => ['github'], 'help' => 'One with a webhook secret. Its webhook address, shown on the connection, is set on the repository in GitHub, with the same secret.'],
                    [
                        'name' => 'on',
                        'label' => 'When',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'issues',
                        'options' => [
                            'issues' => 'An issue is opened, changed or closed',
                            'pull_request' => 'A pull request is opened, changed or merged',
                            'issue_comment' => 'Someone comments on an issue or pull request',
                            'push' => 'Commits are pushed',
                            'release' => 'A release is published',
                            'workflow_run' => 'An Actions run starts or ends',
                            'any' => 'Anything GitHub sends',
                        ],
                        'help' => 'GitHub sends only the events picked on its webhook.',
                    ],
                    ['name' => 'actions', 'label' => 'Only when', 'type' => 'text', 'required' => false, 'placeholder' => 'opened, closed', 'help' => 'The actions to start on, as GitHub names them: opened, closed, reopened, labeled, completed... Empty, any.', 'when' => ['on' => ['issues', 'pull_request', 'issue_comment', 'release', 'workflow_run', 'any']]],
                    ['name' => 'repo', 'label' => 'Only in', 'type' => 'text', 'required' => false, 'placeholder' => 'owner/name', 'help' => 'For a webhook on a whole organisation: the repositories to start on. Empty, any.'],
                    ['name' => 'branch', 'label' => 'Only on the branch', 'type' => 'text', 'required' => false, 'placeholder' => 'main', 'help' => 'Pushed to, a pull request into, or a run on. Empty, any.', 'when' => ['on' => ['push', 'pull_request', 'workflow_run']]],
                ],
            ],

            // --- Chat ---
            [
                'type' => 'chat.room',
                'runs' => Steps\ChatRoomStep::class,
                'version' => 1,
                'label' => 'Find or make a room',
                'group' => 'chat',
                'icon' => 'messages-square',
                'description' => 'The room for an outside conversation, made the first time: one room for each Telegram chat, say -- with a picture and details kept with it. Gives out room, its ref.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'key', 'label' => 'Stands for', 'type' => 'template', 'required' => false, 'placeholder' => 'telegram:{{ trigger.chat.id }}', 'help' => 'Left empty, a new room every time.'],
                    ['name' => 'title', 'label' => 'Title', 'type' => 'template', 'required' => true, 'default' => 'From a workflow', 'placeholder' => '{{ trigger.sender.name }} on Telegram'],
                    ['name' => 'link', 'label' => 'Link it to', 'type' => 'connection', 'required' => false, 'services' => ChatRoom::LINKS, 'help' => 'A bot, a channel, a number or an account: what is written in the room can then go back through it, to the chat below. In a project, a linked room is a customer\'s chat, in the project\'s inbox.'],
                    ['name' => 'link_chat', 'label' => 'Its chat there', 'type' => 'template', 'required' => false, 'default' => '{{ trigger.chat.id }}', 'when' => ['link' => true]],
                    ['name' => 'picture', 'label' => 'Its picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("who").picture }}', 'help' => 'A picture to show as the room\'s face, at its top and in the list of chats -- its address, or one in the Drive by its ref_id: whoever it is with, as "LINE: who wrote" gives their picture. Kept up to date each time the step runs.'],
                    ['name' => 'contact', 'label' => 'Same person as', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.sender.phone }}', 'help' => 'In a project, something the same for this customer on every service -- an email, a phone number. Their chats given the same are one person\'s: a chat on WhatsApp and one on LINE then show each other, and share what is noted about them. Read later as trigger.room.contact.', 'when' => ['link' => true]],
                    ['name' => 'details', 'label' => 'Details to keep', 'type' => 'fields', 'required' => false, 'default' => [], 'help' => 'Things known about the room, each by a name: language {{ node("who").language }}, line_id {{ trigger.sender.id }}. Shown in the room under "About this chat", and read by later runs as trigger.room.details.language. One that comes to nothing is left as it was.'],
                ],
            ],
            [
                'type' => 'chat.send',
                'runs' => Steps\ChatSend::class,
                'version' => 1,
                'label' => 'Write in a room',
                'group' => 'chat',
                'icon' => 'message-square-plus',
                'description' => 'Writes a message in a chat room, as the workflow, with a file from the Drive when one is named. Can ask the room\'s AI and wait for its answer.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'room', 'label' => 'Room', 'type' => 'room', 'required' => true],
                    ['name' => 'message', 'label' => 'Message', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.display }}', 'help' => 'Needed unless a file or a place goes with it. {{ trigger.display }} is a LINE or Telegram message\'s words with LINE\'s own emoji as pictures, where {{ trigger.text }} has their names.'],
                    ['name' => 'file', 'label' => 'With file', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A file in the Drive, by its ref_id, shown under the message: a picture or a video as one, anything else as a link.'],
                    [
                        'name' => 'shown',
                        'label' => 'Shown as',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'file',
                        'options' => ['file' => 'A picture, a video or a file', 'sticker' => 'A sticker: small, with nothing round it'],
                        'when' => ['file' => true],
                    ],
                    ['name' => 'place', 'label' => 'With a place', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.location }}', 'help' => 'A place shown on a small map under the message: what a message that sent one gives as {{ trigger.location }} -- its lat and lng, and a title and address when it has them.'],
                    ['name' => 'sender', 'label' => 'Shown as', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.sender.name }} (Telegram)', 'help' => 'Left empty, the workflow\'s name.'],
                    ['name' => 'sources', 'label' => 'Its sources', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("ask").sources }}', 'help' => 'What an agent answered from, as "Ask an agent" gives it: shown under the message to the project, never sent out.'],
                    ['name' => 'note', 'label' => 'Written as', 'type' => 'choice', 'required' => true, 'default' => 'message', 'options' => ['message' => 'A message', 'note' => 'A note for the project, never sent out'], 'help' => 'In a customer\'s chat, a note is seen by the project alone, and no workflow hears it.'],
                    // Only while rooms may have an agent of their own (CHAT_AGENTS)
                    ...ChatRoom::agentsOn() ? [[
                        'name' => 'ask',
                        'label' => 'Then',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'nothing',
                        'options' => ['nothing' => 'Carry on', 'agent' => 'Wait for the room\'s AI to answer'],
                    ]] : [],
                ],
            ],
            [
                'type' => 'chat.fetch',
                'runs' => Steps\ChatFetch::class,
                'version' => 1,
                'label' => 'Read a room',
                'group' => 'chat',
                'icon' => 'scroll-text',
                'description' => 'Gives out a room\'s latest messages, oldest first, as messages.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'room', 'label' => 'Room', 'type' => 'room', 'required' => true],
                    ['name' => 'limit', 'label' => 'How many', 'type' => 'number', 'required' => true, 'default' => 20],
                ],
            ],

            [
                'type' => 'chat.update',
                'runs' => Steps\ChatUpdate::class,
                'version' => 1,
                'label' => 'Change a customer\'s chat',
                'group' => 'chat',
                'icon' => 'user-check',
                'description' => 'Sets where a project\'s chat with a customer stands, who has taken it, and its tags: how a chat is handed to a person, or closed. Gives out status, assignee and tags as they then are.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'room', 'label' => 'Room', 'type' => 'room', 'required' => true],
                    [
                        'name' => 'status',
                        'label' => 'Status',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'keep',
                        'options' => ['keep' => 'As it is', ChatRoom::OPEN => 'Open: to be answered', ChatRoom::WAITING => 'Waiting on them', ChatRoom::CLOSED => 'Closed'],
                    ],
                    [
                        'name' => 'assign',
                        'label' => 'Assigned to',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'keep',
                        'options' => ['keep' => 'As it is', 'person' => 'Someone in the project', 'nobody' => 'Nobody'],
                    ],
                    ['name' => 'person', 'label' => 'Who', 'type' => 'template', 'required' => false, 'placeholder' => 'ada@example.com', 'help' => 'An owner or an editor of the project, by their email.', 'when' => ['assign' => 'person']],
                    ['name' => 'tag', 'label' => 'Add tags', 'type' => 'template', 'required' => false, 'placeholder' => 'refund, {{ node("ask").json.topic }}', 'help' => 'With commas between.'],
                    ['name' => 'untag', 'label' => 'Take tags off', 'type' => 'template', 'required' => false, 'placeholder' => 'new'],
                ],
            ],

            // --- Tables ---
            [
                'type' => 'table.find',
                'runs' => Steps\TableFind::class,
                'version' => 1,
                'label' => 'Find rows',
                'group' => 'table',
                'icon' => 'table-2',
                'description' => 'Gives out a table\'s rows, all or those whose column holds a value, as rows.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'table', 'label' => 'Table', 'type' => 'table', 'required' => true],
                    ['name' => 'column', 'label' => 'Where column', 'type' => 'column', 'required' => false],
                    ['name' => 'value', 'label' => 'Is', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.status }}', 'when' => ['column' => true]],
                    ['name' => 'limit', 'label' => 'At most', 'type' => 'number', 'required' => true, 'default' => 50],
                ],
            ],
            [
                'type' => 'table.add',
                'runs' => Steps\TableAdd::class,
                'version' => 1,
                'label' => 'Add a row',
                'group' => 'table',
                'icon' => 'table-2',
                'description' => 'Adds a row to a table, and gives it out as row.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'table', 'label' => 'Table', 'type' => 'table', 'required' => true],
                    ['name' => 'values', 'label' => 'Values', 'type' => 'fields', 'required' => true, 'default' => [], 'names' => 'columns'],
                ],
            ],
            [
                'type' => 'table.update',
                'runs' => Steps\TableUpdate::class,
                'version' => 1,
                'label' => 'Change a row',
                'group' => 'table',
                'icon' => 'table-2',
                'description' => 'Writes values into a row, found by its id, and gives it out as row.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'table', 'label' => 'Table', 'type' => 'table', 'required' => true],
                    ['name' => 'row', 'label' => 'Row id', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("find").rows.id }}'],
                    ['name' => 'values', 'label' => 'Values', 'type' => 'fields', 'required' => true, 'default' => [], 'names' => 'columns'],
                ],
            ],

            // --- Drive ---
            [
                'type' => 'drive.save',
                'runs' => Steps\DriveSave::class,
                'version' => 1,
                'empty' => ['ref_id' => null, 'name' => null, 'url' => null, 'kind' => null, 'mime' => null, 'size' => null],
                'label' => 'Save to Drive',
                'group' => 'drive',
                'icon' => 'hard-drive-download',
                'description' => 'Keeps a file in the Drive: one at an address on the internet, or text written here. Gives out the file: its ref_id, name, kind, size, url and, for a picture, the markdown that shows it. (One sent to a bot or a channel: "Telegram: get the file", "LINE: get the file".)',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    [
                        'name' => 'from',
                        'label' => 'The file is',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'url',
                        'options' => ['url' => 'At an address on the internet', 'text' => 'Text written here'],
                    ],
                    ['name' => 'url', 'label' => 'Address', 'type' => 'template', 'required' => false, 'placeholder' => 'https://example.com/report.pdf', 'when' => ['from' => 'url']],
                    ['name' => 'text', 'label' => 'Text', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("ask").text }}', 'when' => ['from' => 'text']],
                    ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => false, 'placeholder' => 'receipt-{{ trigger.id }}.jpg', 'help' => 'Left empty, the name it has at its address. Text needs a name, like notes.md.'],
                    ['name' => 'folder', 'label' => 'In folder', 'type' => 'folder', 'required' => false],
                    [
                        'name' => 'same',
                        'label' => 'If a file called that is there already',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'new',
                        'options' => ['new' => 'Keep both', 'reuse' => 'Use the one there'],
                        'help' => 'Only when it is given a name: the one there is given out, and nothing is fetched.',
                        'when' => ['name' => true],
                    ],
                ],
            ],
            [
                'type' => 'drive.find',
                'runs' => Steps\DriveFind::class,
                'version' => 1,
                'label' => 'Find files',
                'group' => 'drive',
                'icon' => 'folder-search',
                'description' => 'Gives out what is in the Drive, newest first, as files: in one folder or anywhere, by part of a name, of one kind.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'folder', 'label' => 'In folder', 'type' => 'folder', 'required' => false, 'anywhere' => true],
                    ['name' => 'name', 'label' => 'Name has', 'type' => 'template', 'required' => false, 'placeholder' => 'receipt'],
                    [
                        'name' => 'kind',
                        'label' => 'Kind',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'any',
                        'options' => ['any' => 'Any', 'image' => 'Pictures', 'video' => 'Videos', 'audio' => 'Sound', 'pdf' => 'PDFs', 'doc' => 'Documents', 'archive' => 'Archives', 'other' => 'Anything else'],
                    ],
                    ['name' => 'limit', 'label' => 'At most', 'type' => 'number', 'required' => true, 'default' => 20],
                ],
            ],
            [
                'type' => 'browser.run',
                'runs' => Steps\BrowserChecks::class,
                'version' => 1,
                'label' => 'Run browser checks',
                'group' => 'browser',
                'icon' => 'monitor-check',
                'description' => 'Runs one of your suites of browser checks now, in a real browser, and gives out passed (true or false), summary (how it went, in a sentence), failed (how many did not pass), checks (each one\'s title, passed, says and film) and tickets (the numbers of any bug tickets it filed). A check that fails does not stop the workflow: branch on passed. Filmed, it takes longer -- keep it to a few checks.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'suite', 'label' => 'Suite', 'type' => 'template', 'required' => true, 'placeholder' => 'Demo shop: Swag Labs', 'help' => 'The suite\'s name, as Browser checks shows it.'],
                    ['name' => 'check', 'label' => 'Only the check called', 'type' => 'template', 'required' => false, 'help' => 'Left empty, every check in the suite.'],
                    [
                        'name' => 'film',
                        'label' => 'Film it',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'no',
                        'options' => ['no' => 'No: quick, for testing', 'yes' => 'Yes: slowed, with captions, the film kept in the Drive'],
                    ],
                ],
            ],
            [
                'type' => 'drive.read',
                'runs' => Steps\DriveRead::class,
                'version' => 1,
                'label' => 'Read a file',
                'group' => 'drive',
                'icon' => 'file-text',
                'description' => 'Gives out one of the Drive\'s files -- its name, kind, size and url -- and, for a text file, what it says as text (the first 64 KB).',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'file', 'label' => 'File', 'type' => 'template', 'required' => true, 'placeholder' => '{{ item(node("find").files, 1).ref_id }}', 'help' => 'A file\'s ref_id, as "Save to Drive", "Find files" and the "get the file" steps give it.'],
                ],
            ],
            [
                'type' => 'drive.link',
                'runs' => Steps\DriveLink::class,
                'version' => 1,
                'label' => 'Make a link to a file',
                'group' => 'drive',
                'icon' => 'link',
                'description' => 'Gives out an address, as url, that opens one of the Drive\'s files for anyone who has it, without signing in, until its time is up -- for sending a file where only words can go.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'file', 'label' => 'File', 'type' => 'template', 'required' => true, 'placeholder' => '{{ item(trigger.files, 1) }}', 'help' => 'A file\'s ref_id.'],
                    ['name' => 'hours', 'label' => 'Good for, in hours', 'type' => 'number', 'required' => true, 'default' => 24, 'help' => 'At most a week. The app has to be reachable from outside.'],
                ],
            ],

            // --- Tickets: a project's, worked by the workflow ---
            [
                'type' => 'ticket.file',
                'runs' => Steps\TicketFile::class,
                'version' => 1,
                'empty' => Steps\TicketSteps::EMPTY,
                'label' => 'File a ticket',
                'group' => 'tickets',
                'icon' => 'ticket-plus',
                'description' => 'Files a bug or a feature in the project, open, as whoever the workflow acts as. Gives out the ticket: its number, title, status, priority, unit, assignee and url.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'project', 'label' => 'Project', 'type' => 'project', 'required' => false, 'help' => 'Left empty, this workflow\'s own project.'],
                    ['name' => 'type', 'label' => 'It is', 'type' => 'choice', 'required' => true, 'default' => 'bug', 'options' => ['bug' => 'A bug: something to fix', 'feature' => 'A feature: something to add']],
                    ['name' => 'title', 'label' => 'Title', 'type' => 'template', 'required' => true, 'placeholder' => 'Checkout fails: {{ trigger.error }}'],
                    ['name' => 'body', 'label' => 'What it says', 'type' => 'template', 'required' => false, 'placeholder' => 'What was done, what happened, what was expected.'],
                    ['name' => 'unit', 'label' => 'About the part', 'type' => 'template', 'required' => false, 'placeholder' => 'Boards'],
                    ['name' => 'priority', 'label' => 'Priority', 'type' => 'choice', 'required' => true, 'default' => 'normal', 'options' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High']],
                    ['name' => 'assignee', 'label' => 'Given to', 'type' => 'template', 'required' => false, 'placeholder' => 'name@example.com', 'help' => 'A project member\'s email; left empty, nobody.'],
                ],
            ],
            [
                'type' => 'ticket.update',
                'runs' => Steps\TicketUpdate::class,
                'version' => 1,
                'empty' => Steps\TicketSteps::EMPTY,
                'label' => 'Change a ticket',
                'group' => 'tickets',
                'icon' => 'ticket-check',
                'description' => 'Changes one of the project\'s tickets, by its number: moves it along, or changes its priority, whom it is given to, its title or what it says. What is left empty stays as it was. Gives out the ticket as it is now.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'project', 'label' => 'Project', 'type' => 'project', 'required' => false, 'help' => 'Left empty, this workflow\'s own project.'],
                    ['name' => 'number', 'label' => 'Ticket number', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("find").tickets.number }}'],
                    ['name' => 'status', 'label' => 'Status', 'type' => 'choice', 'required' => true, 'default' => 'keep', 'options' => ['keep' => 'As it is', 'open' => 'Open', 'in_progress' => 'In progress', 'on_hold' => 'On hold', 'done' => 'Done', 'dropped' => 'Dropped']],
                    ['name' => 'reason', 'label' => 'Why', 'type' => 'template', 'required' => false, 'placeholder' => 'Waiting on the supplier\'s answer', 'help' => 'On hold: what it waits on. Dropped: why it will not be done.', 'when' => ['status' => ['on_hold', 'dropped']]],
                    ['name' => 'priority', 'label' => 'Priority', 'type' => 'choice', 'required' => true, 'default' => 'keep', 'options' => ['keep' => 'As it is', 'low' => 'Low', 'normal' => 'Normal', 'high' => 'High']],
                    ['name' => 'assignee', 'label' => 'Given to', 'type' => 'template', 'required' => false, 'placeholder' => 'name@example.com', 'help' => 'A project member\'s email, or "nobody"; left empty, as it is.'],
                    ['name' => 'title', 'label' => 'Title', 'type' => 'template', 'required' => false, 'help' => 'Left empty, as it is.'],
                    ['name' => 'body', 'label' => 'What it says', 'type' => 'template', 'required' => false, 'help' => 'Left empty, as it is.'],
                ],
            ],
            [
                'type' => 'tickets.find',
                'runs' => Steps\TicketsFind::class,
                'version' => 1,
                'label' => 'Find tickets',
                'group' => 'tickets',
                'icon' => 'tickets',
                'description' => 'Gives out the project\'s tickets, newest first, as tickets, and how many as count: those still to be done unless told otherwise, of a type, about a part, with words in their title or what they say.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'project', 'label' => 'Project', 'type' => 'project', 'required' => false, 'help' => 'Left empty, this workflow\'s own project.'],
                    ['name' => 'status', 'label' => 'Which', 'type' => 'choice', 'required' => true, 'default' => 'left', 'options' => ['left' => 'Still to be done', 'all' => 'All of them', 'open' => 'Open', 'in_progress' => 'In progress', 'on_hold' => 'On hold', 'done' => 'Done', 'dropped' => 'Dropped']],
                    ['name' => 'type', 'label' => 'Of the type', 'type' => 'choice', 'required' => true, 'default' => 'any', 'options' => ['any' => 'Bugs and features', 'bug' => 'Bugs', 'feature' => 'Features']],
                    ['name' => 'unit', 'label' => 'About the part', 'type' => 'template', 'required' => false],
                    ['name' => 'search', 'label' => 'With the words', 'type' => 'template', 'required' => false],
                    ['name' => 'limit', 'label' => 'At most', 'type' => 'number', 'required' => true, 'default' => 50],
                ],
            ],

            // --- Notes: the workflow owner's ---
            [
                'type' => 'note.create',
                'runs' => Steps\NoteCreate::class,
                'version' => 1,
                'empty' => Steps\NoteSteps::EMPTY,
                'label' => 'Write a note',
                'group' => 'notes',
                'icon' => 'notebook-pen',
                'description' => 'Makes a note of Markdown -- headings, lists, tables, [[Page]] links -- in a folder by its path, made when it is not there. Gives out the note: its ref_id, title, folder and url.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'title', 'label' => 'Title', 'type' => 'template', 'required' => true, 'placeholder' => 'Daily report {{ now() }}'],
                    ['name' => 'markdown', 'label' => 'What it says (Markdown)', 'type' => 'template', 'required' => false, 'placeholder' => "## Done\n{{ node(\"ask\").text }}"],
                    ['name' => 'folder', 'label' => 'In the folder', 'type' => 'template', 'required' => false, 'placeholder' => 'Reports/2026', 'help' => 'A path; left empty, at the top.'],
                ],
            ],
            [
                'type' => 'note.append',
                'runs' => Steps\NoteAppend::class,
                'version' => 1,
                'empty' => Steps\NoteSteps::EMPTY,
                'label' => 'Add to a note',
                'group' => 'notes',
                'icon' => 'notebook-text',
                'description' => 'Adds Markdown at the end of a note, or at its start -- the note found by its ref_id or its title. Someone with it open sees it come in. Gives out the note.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'note', 'label' => 'Note', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("write").ref_id }}', 'help' => 'Its ref_id, or its title.'],
                    ['name' => 'markdown', 'label' => 'What it adds (Markdown)', 'type' => 'template', 'required' => true, 'placeholder' => '- {{ trigger.text }}'],
                    ['name' => 'where', 'label' => 'Where', 'type' => 'choice', 'required' => true, 'default' => 'end', 'options' => ['end' => 'At the end', 'start' => 'At the start']],
                ],
            ],

            // --- AI ---
            [
                'type' => 'ai.chat',
                'runs' => Steps\AskAi::class,
                'version' => 1,
                'label' => 'Ask AI',
                'group' => 'ai',
                'icon' => 'sparkles',
                'description' => 'Asks a model through one of your AI connections, and gives out its answer as text.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Connection', 'type' => 'connection', 'required' => true, 'services' => ['ai']],
                    ['name' => 'model', 'label' => 'Model', 'type' => 'model', 'required' => false, 'help' => 'Left empty, the connection\'s usual one.'],
                    ['name' => 'system', 'label' => 'Instructions', 'type' => 'template', 'required' => false, 'placeholder' => 'You sort support messages. Answer with one word.'],
                    ['name' => 'prompt', 'label' => 'Ask', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.text }}'],
                    ['name' => 'pictures', 'label' => 'With pictures', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'Pictures in the Drive for the model to look at, by ref_id: one, or a few with commas between. The model has to be one that takes pictures.'],
                    [
                        'name' => 'answer',
                        'label' => 'Answer as',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'text',
                        'options' => ['text' => 'Text', 'json' => 'JSON, read into json'],
                    ],
                ],
            ],
            [
                'type' => 'ai.agent',
                'runs' => Steps\AskAgent::class,
                'version' => 1,
                'label' => 'Ask an agent',
                'group' => 'ai',
                'icon' => 'bot',
                'description' => 'Asks one of your agents -- its model, its instructions, what its knowledge base finds -- given a message and, in a chat, the conversation so far. Gives out text (and json when asked), and the version that answered.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'agent', 'label' => 'Agent', 'type' => 'agent', 'required' => true],
                    ['name' => 'message', 'label' => 'Message', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.text }}'],
                    ['name' => 'room', 'label' => 'In the chat', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.room.ref }}', 'help' => 'A chat\'s ref_id: the agent reads its latest messages first. Left empty, the message alone.'],
                    ['name' => 'version', 'label' => 'Version', 'type' => 'template', 'required' => false, 'placeholder' => 'latest', 'help' => 'A number, to keep to that version whatever is changed after; left empty, the latest.'],
                    [
                        'name' => 'answer',
                        'label' => 'Answer as',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'text',
                        'options' => ['text' => 'Text', 'json' => 'JSON, read into json'],
                    ],
                ],
            ],
            [
                'type' => 'knowledge.search',
                'runs' => Steps\KnowledgeSearch::class,
                'version' => 1,
                'label' => 'Search knowledge',
                'group' => 'ai',
                'icon' => 'book-open-text',
                'description' => 'Finds the passages of a knowledge base -- notes, tables and Drive files read in advance -- nearest a question, best first. Gives out results (each title, url, text and score), found, and context: all of them as one text, for "Ask AI" to answer from.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'knowledge', 'label' => 'Knowledge base', 'type' => 'knowledge', 'required' => true],
                    ['name' => 'query', 'label' => 'Question', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.text }}'],
                    ['name' => 'limit', 'label' => 'At most', 'type' => 'number', 'required' => true, 'default' => 5, 'help' => 'Passages, from 1 to 20.'],
                ],
            ],

            // --- Outside ---
            [
                'type' => 'http.respond',
                'runs' => Steps\WebhookAnswer::class,
                'version' => 1,
                'label' => 'Answer the webhook',
                'group' => 'web',
                'icon' => 'reply',
                'description' => 'What a webhook set to answer "With what the run gives" sends back to its caller: a status and a body. A body that is a record or a list goes as JSON. Gives out status and body.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'status', 'label' => 'Status', 'type' => 'number', 'required' => false, 'default' => 200],
                    ['name' => 'body', 'label' => 'Body', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("flows") }}', 'help' => 'Text, or a value worked out here: a record or a list goes as JSON.'],
                ],
            ],
            [
                'type' => 'http.request',
                'runs' => Steps\HttpRequest::class,
                'version' => 1,
                'label' => 'HTTP request',
                'group' => 'web',
                'icon' => 'globe',
                'description' => 'Calls an address on the internet and gives out status, headers and body. Never one inside this network.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    [
                        'name' => 'method',
                        'label' => 'Method',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'GET',
                        'options' => ['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE'],
                    ],
                    ['name' => 'url', 'label' => 'Address', 'type' => 'template', 'required' => true, 'placeholder' => 'https://api.example.com/orders/{{ trigger.id }}'],
                    ['name' => 'auth', 'label' => 'Key', 'type' => 'connection', 'required' => false, 'services' => ['http_header'], 'help' => 'Sent only to the address the key was saved for.'],
                    ['name' => 'query', 'label' => 'Query', 'type' => 'fields', 'required' => false, 'default' => []],
                    ['name' => 'headers', 'label' => 'Headers', 'type' => 'fields', 'required' => false, 'default' => []],
                    [
                        'name' => 'body_type',
                        'label' => 'Body',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'none',
                        'options' => ['none' => 'None', 'json' => 'JSON', 'form' => 'Form', 'text' => 'Text'],
                    ],
                    ['name' => 'body', 'label' => 'Body', 'type' => 'template', 'required' => false, 'placeholder' => '{{ input }}', 'help' => 'For JSON, a single {{ formula }} is sent as it is; for a form, a record.', 'when' => ['body_type' => true]],
                    [
                        'name' => 'errors',
                        'label' => 'An answer of 400 or more',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'fail',
                        'options' => ['fail' => 'Fails the step', 'continue' => 'Is given out like any other'],
                    ],
                    [
                        'name' => 'unsure',
                        'label' => 'If it may not have gone out',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'ask',
                        'options' => ['ask' => 'Ask me', 'retry' => 'Send it again', 'never' => 'Don\'t send it again'],
                        'help' => 'Only for a request that changes something (not GET), when its worker stops partway.',
                    ],
                ],
            ],
            [
                'type' => 'telegram.file',
                'runs' => Steps\TelegramFile::class,
                'version' => 1,
                'label' => 'Telegram: get the file',
                'group' => 'telegram',
                'icon' => 'file-down',
                'description' => 'Fetches a picture, a document, a video or a recording sent to the bot, and keeps it in the Drive. A Telegram message carries only the file\'s id. Gives out the file: its ref_id, name, kind, size, url and, for a picture, the markdown that shows it.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                // With nothing fetched and the run carrying on, later steps read no file rather than fail
                'empty' => ['ref_id' => null, 'name' => null, 'url' => null, 'kind' => null, 'mime' => null, 'size' => null],
                'config' => [
                    ['name' => 'connection', 'label' => 'Bot', 'type' => 'connection', 'required' => true, 'services' => ['telegram'], 'help' => 'The one the message was sent to: it is asked for the file.'],
                    ['name' => 'file', 'label' => 'Which file', 'type' => 'template', 'required' => false, 'placeholder' => '{{ item(trigger.files, 2) }}', 'help' => 'Left empty, the first the message carried. A message lists them as trigger.files; one without any fails this step, so put an If on len(trigger.files) > 0 before it.'],
                    ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => false, 'placeholder' => 'receipt-{{ trigger.id }}.jpg', 'help' => 'Left empty, what it was sent as; a photo, which has no name, is named for when it came.'],
                    ['name' => 'folder', 'label' => 'Keep it in', 'type' => 'folder', 'required' => false, 'help' => 'A Drive folder. Telegram hands a bot files of at most 20 MB.'],
                    ['name' => 'same', 'label' => 'If a file called that is there already', 'type' => 'choice', 'required' => true, 'default' => 'new', 'options' => ['new' => 'Keep both', 'reuse' => 'Use the one there'], 'help' => 'Only when it is given a name: the one there is given out, and Telegram is not asked again.', 'when' => ['name' => true]],
                ],
            ],
            [
                'type' => 'whatsapp.file',
                'runs' => Steps\WhatsAppFile::class,
                'version' => 1,
                'label' => 'WhatsApp: get the file',
                'group' => 'whatsapp',
                'icon' => 'file-down',
                'description' => 'Fetches a picture, a video, a recording or a document sent to the business number, and keeps it in the Drive. A WhatsApp message carries only its id, and WhatsApp keeps what it was sent for a week. Gives out the file: its ref_id, name, kind, size, url and, for a picture, the markdown that shows it.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'empty' => ['ref_id' => null, 'name' => null, 'url' => null, 'kind' => null, 'mime' => null, 'size' => null],
                'config' => [
                    ['name' => 'connection', 'label' => 'Number', 'type' => 'connection', 'required' => true, 'services' => ['whatsapp'], 'help' => 'The one the message was sent to: it is asked for the file.'],
                    ['name' => 'file', 'label' => 'Which file', 'type' => 'template', 'required' => false, 'placeholder' => '{{ item(trigger.files, 1) }}', 'help' => 'Left empty, the one the message carried. A message without any fails this step, so put an If on len(trigger.files) > 0 before it.'],
                    ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => false, 'placeholder' => 'receipt-{{ trigger.id }}.jpg', 'help' => 'Left empty, what it was sent as; a photo, which has no name, is named for when it came.'],
                    ['name' => 'folder', 'label' => 'Keep it in', 'type' => 'folder', 'required' => false, 'help' => 'A Drive folder.'],
                    ['name' => 'same', 'label' => 'If a file called that is there already', 'type' => 'choice', 'required' => true, 'default' => 'new', 'options' => ['new' => 'Keep both', 'reuse' => 'Use the one there'], 'help' => 'Only when it is given a name: the one there is given out, and WhatsApp is not asked again.', 'when' => ['name' => true]],
                ],
            ],
            [
                'type' => 'telegram.send',
                'runs' => Steps\TelegramSend::class,
                'version' => 1,
                'label' => 'Send on Telegram',
                'group' => 'telegram',
                'icon' => 'send',
                'description' => 'Sends a message from a bot to a chat, with buttons under it when any are named.',
                'trigger' => false,
                'repeatable' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Bot', 'type' => 'connection', 'required' => true, 'services' => ['telegram'], 'linked' => true],
                    ['name' => 'chat', 'label' => 'To chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'From a linked chat, its other end is {{ trigger.room.link.chat }}.'],
                    ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => true],
                    ...ServiceKinds::telegramButtons(),
                    ['name' => 'topic', 'label' => 'In topic', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.topic }}', 'help' => 'In a group with topics: the topic\'s id. Left empty, the general one.'],
                    [
                        'name' => 'format',
                        'label' => 'Written in',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'plain',
                        'options' => ['plain' => 'Plain text', 'markdown' => 'Markdown', 'html' => 'HTML'],
                    ],
                    [
                        'name' => 'unsure',
                        'label' => 'If it may not have gone out',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'ask',
                        'options' => ['ask' => 'Ask me', 'retry' => 'Send it again', 'never' => 'Don\'t send it again'],
                    ],
                ],
            ],
            [
                'type' => 'telegram.send_file',
                'runs' => Steps\TelegramSendFile::class,
                'version' => 1,
                'label' => 'Send a file on Telegram',
                'group' => 'telegram',
                'icon' => 'file-up',
                'description' => 'Sends a file from the Drive, from a bot to a chat: a picture or a video is shown as one, a recording played, anything else goes as a document. At most 50 MB.',
                'trigger' => false,
                'repeatable' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Bot', 'type' => 'connection', 'required' => true, 'services' => ['telegram'], 'linked' => true],
                    ['name' => 'chat', 'label' => 'To chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'From a linked chat, its other end is {{ trigger.room.link.chat }}.'],
                    ['name' => 'file', 'label' => 'File', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A file in the Drive, by its ref_id.'],
                    ['name' => 'text', 'label' => 'Words under it', 'type' => 'template', 'required' => false],
                    ...ServiceKinds::telegramButtons(),
                    ['name' => 'topic', 'label' => 'In topic', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.topic }}', 'help' => 'In a group with topics: the topic\'s id. Left empty, the general one.'],
                    [
                        'name' => 'format',
                        'label' => 'Written in',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'plain',
                        'options' => ['plain' => 'Plain text', 'markdown' => 'Markdown', 'html' => 'HTML'],
                    ],
                    [
                        'name' => 'unsure',
                        'label' => 'If it may not have gone out',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'ask',
                        'options' => ['ask' => 'Ask me', 'retry' => 'Send it again', 'never' => 'Don\'t send it again'],
                    ],
                ],
            ],
            [
                'type' => 'linkedin.post',
                'runs' => Steps\LinkedInPost::class,
                'version' => 1,
                'label' => 'Post on LinkedIn',
                'group' => 'linkedin',
                'icon' => 'megaphone',
                'description' => 'Posts on a LinkedIn member\'s own profile, as them: words, alone or with one thing more -- a link shown as a card, a picture or several, a video, a document shown page by page, or a poll. Gives out the post\'s id and its address as url.',
                'trigger' => false,
                'repeatable' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'As', 'type' => 'connection', 'required' => true, 'services' => ['linkedin'], 'help' => 'A LinkedIn connection, signed in. A sign-in lasts 60 days.'],
                    ['name' => 'text', 'label' => 'Words', 'type' => 'template', 'required' => false, 'help' => 'At most 3,000 letters. LinkedIn refuses words the same as a post just made.'],
                    [
                        'name' => 'with',
                        'label' => 'With',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'none',
                        'options' => [
                            'none' => 'Nothing else',
                            'link' => 'A link, shown as a card',
                            'picture' => 'A picture from the Drive',
                            'pictures' => 'Several pictures from the Drive',
                            'video' => 'A video from the Drive',
                            'document' => 'A document from the Drive',
                            'poll' => 'A poll',
                        ],
                    ],
                    ['name' => 'link', 'label' => 'Link', 'type' => 'template', 'required' => false, 'placeholder' => 'https://example.com/news/{{ trigger.id }}', 'when' => ['with' => 'link']],
                    ['name' => 'picture', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'help' => 'A JPEG, PNG or GIF in the Drive, by its ref_id.', 'when' => ['with' => 'picture']],
                    ['name' => 'pictures', 'label' => 'Pictures', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("find").files }}', 'help' => 'From 2 to 20 in the Drive, each a JPEG, PNG or GIF: a list of them, or their ref_ids with commas between.', 'when' => ['with' => 'pictures']],
                    ['name' => 'video', 'label' => 'Video', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'video', 'help' => 'An MP4 in the Drive, by its ref_id: at most 200 MB. LinkedIn shows the post once it has worked through it.', 'when' => ['with' => 'video']],
                    ['name' => 'document', 'label' => 'Document', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A PDF, a Word file or a PowerPoint in the Drive, by its ref_id: at most 100 MB and 300 pages. Shown to leaf through.', 'when' => ['with' => 'document']],
                    ['name' => 'title', 'label' => 'Its title', 'type' => 'template', 'required' => false, 'help' => 'Left empty: a link takes its page\'s own, a document is called as its file is.', 'when' => ['with' => ['link', 'picture', 'video', 'document']]],
                    ['name' => 'description', 'label' => 'A line about it', 'type' => 'template', 'required' => false, 'help' => 'A short description of the link, the picture or the video.', 'when' => ['with' => ['link', 'picture', 'video']]],
                    ['name' => 'question', 'label' => 'It asks', 'type' => 'template', 'required' => false, 'placeholder' => 'Which should we build next?', 'help' => 'At most 140 letters.', 'when' => ['with' => 'poll']],
                    ['name' => 'answers', 'label' => 'Answers', 'type' => 'template', 'required' => false, 'placeholder' => "Maps\nWorkflows\nChat", 'help' => 'From 2 to 4, one on a line, each at most 30 letters.', 'when' => ['with' => 'poll']],
                    ['name' => 'lasts', 'label' => 'Open for', 'type' => 'choice', 'required' => false, 'default' => 'THREE_DAYS', 'options' => Steps\LinkedInPost::POLL_LASTS, 'when' => ['with' => 'poll']],
                    ...ServiceKinds::linkedinSeenBy(),
                    [
                        'name' => 'unsure',
                        'label' => 'If it may not have gone out',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'ask',
                        'options' => ['ask' => 'Ask me', 'retry' => 'Send it again', 'never' => 'Don\'t send it again'],
                    ],
                ],
            ],
            [
                'type' => 'line.profile',
                'runs' => Steps\LineProfile::class,
                'version' => 1,
                'label' => 'LINE: who wrote',
                'group' => 'line',
                'icon' => 'user-search',
                'description' => 'Asks LINE who sent a message -- their name, picture, status and language -- and, in a group, the group\'s name. A LINE message carries only ids. Gives out name, picture, status, language, group.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'empty' => ['id' => null, 'name' => null, 'picture' => null, 'status' => null, 'language' => null, 'group' => null],
                'config' => [
                    ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['line'], 'linked' => true],
                    [
                        'name' => 'user',
                        'label' => 'Who',
                        'type' => 'template',
                        'required' => true,
                        'default' => '{{ trigger.sender.id }}',
                        'help' => 'A LINE user id (U…).',
                    ],
                    [
                        'name' => 'chat',
                        'label' => 'In',
                        'type' => 'template',
                        'required' => false,
                        'default' => '{{ trigger.chat.id }}',
                        'help' => 'The chat it was sent in: a group (C…) or room (R…) is asked of its member; anything else, their own profile.',
                    ],
                ],
            ],
            [
                'type' => 'line.file',
                'runs' => Steps\LineFile::class,
                'version' => 1,
                'label' => 'LINE: get the file',
                'group' => 'line',
                'icon' => 'file-down',
                'description' => 'Fetches a picture, a video, a recording or a file sent to the channel, and keeps it in the Drive. A LINE message carries only its id, and LINE keeps what it was sent only for a while. Gives out the file: its ref_id, name, kind, size, url and, for a picture, the markdown that shows it.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['line'], 'help' => 'The one the message was sent to: it is asked for the file.'],
                    ['name' => 'file', 'label' => 'Which file', 'type' => 'template', 'required' => false, 'placeholder' => '{{ item(trigger.files, 2) }}', 'help' => 'Left empty, the first the message carried. A message lists them as trigger.files; one without any fails this step, so put an If on len(trigger.files) > 0 before it.'],
                    ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => false, 'placeholder' => 'receipt-{{ trigger.id }}.jpg', 'help' => 'Left empty, what it was sent as; a photo, which has no name, is named for when it came.'],
                    ['name' => 'folder', 'label' => 'Keep it in', 'type' => 'folder', 'required' => false, 'help' => 'A Drive folder.'],
                ],
            ],
            [
                'type' => 'line.reply',
                'runs' => Steps\LineReply::class,
                'version' => 1,
                'label' => 'Reply on LINE',
                'group' => 'line',
                'icon' => 'reply',
                'description' => 'Answers a LINE message, by LINE\'s reply call: free, but only soon after the message and only once. Words, a picture, a sticker, a place, buttons to choose from, a card -- with quick replies under it. When its token can\'t be used it carries on down "not sent", where a "Push on LINE" can send it instead; with nothing that way, it fails.',
                'trigger' => false,
                'repeatable' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT, self::UNSENT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['line'], 'linked' => true],
                    ['name' => 'token', 'label' => 'Reply token', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.token }}', 'help' => 'What the LINE message came with: the message that started the run gives it as {{ trigger.reply.token }}. From a linked chat, that is the token of the last thing they sent.'],
                    ['name' => 'within', 'label' => 'For how long after the message, in seconds', 'type' => 'number', 'required' => false, 'default' => Steps\LineSend::REPLY_WITHIN, 'help' => 'An older token is not tried. LINE promises a minute. Left empty, LINE is asked whatever its age.'],
                    ...ServiceKinds::lineMessage(),
                    [
                        'name' => 'unsure',
                        'label' => 'If it may not have gone out',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'ask',
                        'options' => ['ask' => 'Ask me', 'retry' => 'Send it again', 'never' => 'Don\'t send it again'],
                        'help' => 'LINE takes a reply token once, so a reply sent again is refused rather than doubled.',
                    ],
                ],
            ],
            [
                'type' => 'line.send',
                'runs' => Steps\LineSend::class,
                'version' => 1,
                'label' => 'Push on LINE',
                'group' => 'line',
                'icon' => 'message-circle',
                'description' => 'Sends a message to someone on LINE first, by LINE\'s push call: at any time, but counted against the channel\'s monthly allowance. Words, a picture, a sticker, a place, buttons to choose from, a card -- with quick replies under it.',
                'trigger' => false,
                'repeatable' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['line'], 'linked' => true],
                    ['name' => 'to', 'label' => 'To', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'A user, group or room id. From a linked chat, its other end is {{ trigger.room.link.chat }}.'],
                    ...ServiceKinds::lineMessage(),
                    [
                        'name' => 'unsure',
                        'label' => 'If it may not have gone out',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'ask',
                        'options' => ['ask' => 'Ask me', 'retry' => 'Send it again', 'never' => 'Don\'t send it again'],
                        'help' => 'It never goes out twice: LINE is given a key for it.',
                    ],
                ],
            ],
        ];

        return array_column([...$types, ...ServiceKinds::all(), ...CommonKinds::all()], null, 'type');
    }

    /**
     * One kind of step, or null when there is none of that type.
     *
     * @return NodeType|null
     */
    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /**
     * The ways out of one step: its kind's, or for a Switch its own -- one
     * for each way it names, in order, then "otherwise".
     *
     * @param  array{type: string, config?: array<string, mixed>}  $node
     * @return list<string>
     */
    public static function outputsOf(array $node): array
    {
        if ($node['type'] === 'logic.switch') {
            $named = array_values(array_filter(array_map(
                fn ($way) => is_array($way) && is_string($way['name'] ?? null) ? $way['name'] : '',
                (array) ($node['config']['ways'] ?? []),
            ), fn (string $name) => $name !== '' && $name !== self::OTHERWISE));

            return [...array_values(array_unique($named)), self::OTHERWISE];
        }

        return self::get($node['type'])['outputs'] ?? [self::OUT];
    }

    /**
     * The groups the palette shows them in, in order: by what they work
     * with -- a service's trigger beside its steps -- not by what they do.
     *
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            'flow' => 'Flow',
            'data' => 'Data',
            'chat' => 'Chat',
            'table' => 'Tables',
            'drive' => 'Drive',
            'tickets' => 'Tickets',
            'notes' => 'Notes',
            'browser' => 'Browser checks',
            'ai' => 'AI',
            'web' => 'Web',
            'serpapi' => 'SerpApi',
            'tavily' => 'Tavily',
            'github' => 'GitHub',
            'stripe' => 'Stripe',
            'promptx' => 'PromptX',
            'telegram' => 'Telegram',
            'line' => 'LINE',
            'whatsapp' => 'WhatsApp',
            'channel' => 'Custom channel',
            'slack' => 'Slack',
            'discord' => 'Discord',
            'mail' => 'Email',
            'linkedin' => 'LinkedIn',
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'threads' => 'Threads',
        ];
    }

    /**
     * The heading the palette lists each group under: what works on any data
     * and runs the flow ("general"), what works on the app's own things
     * ("zyrenn"), in this order. Every other group -- a new service among
     * them -- is a service.
     *
     * @return array<string, 'general'|'zyrenn'>
     */
    public static function headings(): array
    {
        return [
            'flow' => 'general',
            'data' => 'general',
            'ai' => 'general',
            'web' => 'general',
            'chat' => 'zyrenn',
            'table' => 'zyrenn',
            'drive' => 'zyrenn',
            'notes' => 'zyrenn',
            'tickets' => 'zyrenn',
            'browser' => 'zyrenn',
        ];
    }

    /**
     * A group with many kinds, set out in parts: each part's name, and its
     * kinds in the order shown. A kind of that group in no part comes
     * before them all.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function sections(): array
    {
        return [
            'serpapi' => [
                'Searches' => ['serpapi.google', 'serpapi.news', 'serpapi.images', 'serpapi.maps', 'serpapi.youtube', 'serpapi.search'],
                'Places and allowance' => ['serpapi.locations', 'serpapi.account'],
            ],
            'tavily' => [
                'Search and read' => ['tavily.search', 'tavily.extract'],
                'A whole site' => ['tavily.crawl', 'tavily.map'],
                'Allowance' => ['tavily.usage'],
            ],
            'stripe' => [
                'Payments' => ['trigger.stripe', 'stripe.payment_link_create', 'stripe.checkout_create', 'stripe.checkout', 'stripe.payment', 'stripe.payments', 'stripe.refund_create'],
                'Customers' => ['stripe.customer_create', 'stripe.customer_find', 'stripe.customer', 'stripe.customer_update'],
                'Invoices' => ['stripe.invoice_create', 'stripe.invoices'],
                'Products and subscriptions' => ['stripe.product_create', 'stripe.prices', 'stripe.subscriptions', 'stripe.subscription_cancel'],
                'The account' => ['stripe.balance', 'stripe.request'],
            ],
            'github' => [
                'Issues' => ['trigger.github', 'github.issue_create', 'github.issue_update', 'github.issue', 'github.issues', 'github.comment', 'github.comments', 'github.labels_add'],
                'Pull requests' => ['github.pull_create', 'github.pull', 'github.pulls', 'github.merge'],
                'Code' => ['github.file', 'github.file_save', 'github.commits', 'github.branch_create'],
                'Actions and releases' => ['github.dispatch', 'github.runs', 'github.release_create', 'github.release_latest'],
                'Anything else' => ['github.request', 'github.me'],
            ],
            'promptx' => [
                'Flows' => ['promptx.flows', 'promptx.flow_create', 'promptx.flow', 'promptx.flow_import', 'promptx.flow_publish', 'promptx.flow_status', 'promptx.flow_versions', 'promptx.flow_restore', 'promptx.flow_delete'],
                'Steps' => ['promptx.step_update', 'promptx.step_add', 'promptx.step_delete', 'promptx.step_move', 'promptx.trigger_update'],
                'Runs' => ['promptx.flow_run', 'promptx.flow_test', 'promptx.runs', 'promptx.run', 'promptx.run_retry', 'promptx.runs_cancel', 'promptx.run_resume'],
                'Project' => ['promptx.projects', 'promptx.connections', 'promptx.folders', 'promptx.folder_create', 'promptx.tables', 'promptx.table_records', 'promptx.table_add'],
            ],
            'telegram' => [
                'Messages' => ['trigger.telegram', 'telegram.send', 'telegram.send_file', 'telegram.album', 'telegram.sticker', 'telegram.location', 'telegram.contact', 'telegram.poll', 'telegram.forward', 'telegram.copy', 'telegram.action', 'telegram.file'],
                'A message sent' => ['telegram.edit', 'telegram.edit_buttons', 'telegram.delete', 'telegram.pin', 'telegram.unpin', 'telegram.react'],
                'Buttons and commands' => ['telegram.answer_button', 'telegram.answer_inline', 'telegram.commands'],
                'Payments' => ['telegram.invoice', 'telegram.answer_checkout'],
                'Chats and people' => ['telegram.chat', 'telegram.member', 'telegram.member_count', 'telegram.topic', 'telegram.restrict', 'telegram.ban', 'telegram.unban'],
            ],
            'line' => [
                'Messages' => ['trigger.line', 'line.reply', 'line.send', 'line.multicast', 'line.broadcast', 'line.loading', 'line.read', 'line.file'],
                'People and groups' => ['line.profile', 'line.group', 'line.group_count', 'line.leave'],
                'Rich menus' => ['line.richmenu_make', 'line.richmenu_default', 'line.richmenu_default_off', 'line.richmenu', 'line.richmenu_off', 'line.richmenu_many', 'line.richmenu_many_off', 'line.richmenu_of', 'line.richmenus', 'line.richmenu_alias', 'line.richmenu_alias_off', 'line.richmenu_delete'],
                'Allowance' => ['line.quota', 'line.consumption'],
            ],
            'whatsapp' => [
                'Messages' => ['trigger.whatsapp', 'whatsapp.send', 'whatsapp.send_file', 'whatsapp.template', 'whatsapp.location', 'whatsapp.react', 'whatsapp.read', 'whatsapp.file'],
            ],
            'facebook' => [
                'Posts' => ['facebook.post', 'facebook.delete', 'facebook.posts'],
                'Comments and likes' => ['facebook.comment', 'facebook.like', 'facebook.unlike', 'facebook.comments'],
                'The Page' => ['facebook.me'],
            ],
            'instagram' => [
                'Posts' => ['instagram.post', 'instagram.delete', 'instagram.posts'],
                'Comments' => ['trigger.instagram', 'instagram.comment', 'instagram.reply', 'instagram.comment_delete', 'instagram.comments'],
                'Messages' => ['trigger.instagram_message', 'instagram.send', 'instagram.send_file', 'instagram.message_commenter', 'instagram.react', 'instagram.profile'],
                'The account' => ['instagram.me'],
            ],
            'threads' => [
                'Posts' => ['trigger.threads', 'threads.post', 'threads.repost', 'threads.delete', 'threads.posts', 'threads.replies'],
                'The profile' => ['threads.me'],
            ],
            'linkedin' => [
                'Posts' => ['linkedin.post', 'linkedin.repost', 'linkedin.delete'],
                'Comments and likes' => ['linkedin.comment', 'linkedin.comment_delete', 'linkedin.like', 'linkedin.unlike'],
                'The member' => ['linkedin.me'],
            ],
        ];
    }
}
