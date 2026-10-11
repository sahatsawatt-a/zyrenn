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
 *   model     a model the step's AI connection offers (any other may be typed)
 *   timezone  a time zone, e.g. Asia/Bangkok
 *
 * A "fields" setting with 'names' => 'columns' names each value by one of
 * the step's table's columns. A setting with 'when' => [other => value]
 * matters only while that other setting is so (or, given true, set at all),
 * and the editor shows it only then. A room setting with 'linked' also
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
 * @phpstan-type Setting array{name: string, label: string, type: string, required: bool, default?: mixed, help?: string, placeholder?: string, options?: array<string, string>, services?: list<string>, names?: string, when?: array<string, string|bool>, linked?: bool, ways?: bool, anywhere?: bool}
 * @phpstan-type NodeType array{type: string, version: int, label: string, group: string, icon: string, description: string, trigger: bool, inputs: list<string>, outputs: list<string>, config: list<Setting>, repeatable?: bool, oldest?: int, empty?: array<string, null>}
 */
final class NodeTypes
{
    /** The one handle of a step with a single way in or out. */
    public const IN = 'in';

    public const OUT = 'out';

    /** A Switch's way when none of its others is taken. */
    public const OTHERWISE = 'otherwise';

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
        $types = [
            [
                'type' => 'trigger.manual',
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
                'type' => 'logic.if',
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
                'version' => 1,
                'label' => 'Chat message',
                'group' => 'chat',
                'icon' => 'message-square',
                'description' => 'Starts a run when something is written in a chat room.',
                'trigger' => true,
                'inputs' => [],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'room', 'label' => 'Room', 'type' => 'room', 'required' => true, 'linked' => true, 'help' => 'One chat, or every chat linked to a Telegram or LINE chat. Later steps read the message as trigger.text, its author as trigger.message.author, and a linked chat\'s other end as trigger.room.link.chat.'],
                    [
                        'name' => 'include',
                        'label' => 'Start for',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'people',
                        'options' => ['people' => 'What people write', 'all' => 'Workflows\' messages too'],
                    ],
                    self::PER_CHAT,
                ],
            ],
            [
                'type' => 'trigger.schedule',
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
                ],
            ],
            [
                'type' => 'trigger.telegram',
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

            // --- Chat ---
            [
                'type' => 'chat.room',
                'version' => 1,
                'label' => 'Find or make a room',
                'group' => 'chat',
                'icon' => 'messages-square',
                'description' => 'The room for an outside conversation, made the first time: one room for each Telegram chat, say. Gives out room, its ref.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'key', 'label' => 'Stands for', 'type' => 'template', 'required' => false, 'placeholder' => 'telegram:{{ trigger.chat.id }}', 'help' => 'Left empty, a new room every time.'],
                    ['name' => 'title', 'label' => 'Title', 'type' => 'template', 'required' => true, 'default' => 'From a workflow', 'placeholder' => '{{ trigger.sender.name }} on Telegram'],
                    ['name' => 'link', 'label' => 'Link it to', 'type' => 'connection', 'required' => false, 'services' => ['telegram', 'line'], 'help' => 'A bot or channel: what is written in the room can then go back through it, to the chat below.'],
                    ['name' => 'link_chat', 'label' => 'Its chat there', 'type' => 'template', 'required' => false, 'default' => '{{ trigger.chat.id }}', 'when' => ['link' => true]],
                ],
            ],
            [
                'type' => 'chat.send',
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
                    ['name' => 'message', 'label' => 'Message', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.text }}', 'help' => 'Needed unless a file goes with it.'],
                    ['name' => 'file', 'label' => 'With file', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A file in the Drive, by its ref_id, shown under the message: a picture or a video as one, anything else as a link.'],
                    ['name' => 'sender', 'label' => 'Shown as', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.sender.name }} (Telegram)', 'help' => 'Left empty, the workflow\'s name.'],
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

            // --- Tables ---
            [
                'type' => 'table.find',
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
                'version' => 1,
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
                ],
            ],
            [
                'type' => 'drive.find',
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
                'type' => 'drive.read',
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

            // --- AI ---
            [
                'type' => 'ai.chat',
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

            // --- Outside ---
            [
                'type' => 'http.request',
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
                'version' => 1,
                'label' => 'Telegram: get the file',
                'group' => 'telegram',
                'icon' => 'file-down',
                'description' => 'Fetches a picture, a document, a video or a recording sent to the bot, and keeps it in the Drive. A Telegram message carries only the file\'s id. Gives out the file: its ref_id, name, kind, size, url and, for a picture, the markdown that shows it.',
                'trigger' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Bot', 'type' => 'connection', 'required' => true, 'services' => ['telegram'], 'help' => 'The one the message was sent to: it is asked for the file.'],
                    ['name' => 'file', 'label' => 'Which file', 'type' => 'template', 'required' => false, 'placeholder' => '{{ item(trigger.files, 2) }}', 'help' => 'Left empty, the first the message carried. A message lists them as trigger.files; one without any fails this step, so put an If on len(trigger.files) > 0 before it.'],
                    ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => false, 'placeholder' => 'receipt-{{ trigger.id }}.jpg', 'help' => 'Left empty, what it was sent as; a photo, which has no name, is named for when it came.'],
                    ['name' => 'folder', 'label' => 'Keep it in', 'type' => 'folder', 'required' => false, 'help' => 'A Drive folder. Telegram hands a bot files of at most 20 MB.'],
                ],
            ],
            [
                'type' => 'telegram.send',
                'version' => 1,
                'label' => 'Send on Telegram',
                'group' => 'telegram',
                'icon' => 'send',
                'description' => 'Sends a message from a bot to a chat.',
                'trigger' => false,
                'repeatable' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Bot', 'type' => 'connection', 'required' => true, 'services' => ['telegram'], 'linked' => true],
                    ['name' => 'chat', 'label' => 'To chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'From a linked chat, its other end is {{ trigger.room.link.chat }}.'],
                    ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => true],
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
                'version' => 1,
                'label' => 'Send a file on Telegram',
                'group' => 'telegram',
                'icon' => 'file-up',
                'description' => 'Sends a file from the Drive, from a bot to a chat: a picture or a video is shown as one, anything else goes as a document. At most 50 MB.',
                'trigger' => false,
                'repeatable' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Bot', 'type' => 'connection', 'required' => true, 'services' => ['telegram'], 'linked' => true],
                    ['name' => 'chat', 'label' => 'To chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'From a linked chat, its other end is {{ trigger.room.link.chat }}.'],
                    ['name' => 'file', 'label' => 'File', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A file in the Drive, by its ref_id.'],
                    ['name' => 'text', 'label' => 'Words under it', 'type' => 'template', 'required' => false],
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
                'type' => 'line.profile',
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
                'version' => 1,
                'label' => 'Reply on LINE',
                'group' => 'line',
                'icon' => 'reply',
                'description' => 'Answers a LINE message, by LINE\'s reply call: free, but only soon after the message and only once. Words, a picture from the Drive, or both.',
                'trigger' => false,
                'repeatable' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['line'], 'linked' => true],
                    ['name' => 'token', 'label' => 'Reply token', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.token }}', 'help' => 'What the LINE message came with: the message that started the run gives it as {{ trigger.reply.token }}.'],
                    ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => false, 'help' => 'Needed unless a picture goes with it.'],
                    ['name' => 'file', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A JPEG or PNG in the Drive, by its ref_id, at most 10 MB. LINE fetches it from this app, which must be reachable from outside.'],
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
                'version' => 1,
                'label' => 'Push on LINE',
                'group' => 'line',
                'icon' => 'message-circle',
                'description' => 'Sends a message to someone on LINE first, by LINE\'s push call: at any time, but counted against the channel\'s monthly allowance. Words, a picture from the Drive, or both.',
                'trigger' => false,
                'repeatable' => false,
                'inputs' => [self::IN],
                'outputs' => [self::OUT],
                'config' => [
                    ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['line'], 'linked' => true],
                    ['name' => 'to', 'label' => 'To', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'A user, group or room id. From a linked chat, its other end is {{ trigger.room.link.chat }}.'],
                    ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => false, 'help' => 'Needed unless a picture goes with it.'],
                    ['name' => 'file', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'help' => 'A JPEG or PNG in the Drive, by its ref_id, at most 10 MB. LINE fetches it from this app, which must be reachable from outside.'],
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

        return array_column($types, null, 'type');
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
            'ai' => 'AI',
            'web' => 'Web',
            'telegram' => 'Telegram',
            'line' => 'LINE',
        ];
    }
}
