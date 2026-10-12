<?php

namespace App\Support\Workflow;

/**
 * The kinds of step that are one call to Telegram or to LINE each, beside
 * the ones NodeTypes lists itself: every call a service offers is a step of
 * its own, in that service's group. Run by TelegramCall and LineCall.
 *
 * @phpstan-import-type Setting from NodeTypes
 * @phpstan-import-type NodeType from NodeTypes
 */
final class ServiceKinds
{
    /** What a step that may have sent something does when it can't tell. */
    private const UNSURE = [
        'name' => 'unsure',
        'label' => 'If it may not have gone out',
        'type' => 'choice',
        'required' => true,
        'default' => 'ask',
        'options' => ['ask' => 'Ask me', 'retry' => 'Send it again', 'never' => 'Don\'t send it again'],
    ];

    /**
     * @return list<NodeType>
     */
    public static function all(): array
    {
        return [...self::telegram(), ...self::line()];
    }

    // --- Telegram ---

    /**
     * Buttons under a Telegram message, and how many go side by side.
     *
     * @return list<Setting>
     */
    public static function telegramButtons(string $help = 'Each is what the button says, and what pressing it does.'): array
    {
        return [
            ['name' => 'buttons', 'label' => 'Buttons', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'help' => $help, 'does' => [
                ['kind' => 'press', 'label' => 'Comes back as a press', 'written' => 'anything else', 'placeholder' => 'Its own words', 'hint' => 'For a "Telegram message" trigger set to buttons, which reads it as trigger.text; at most 64 bytes. Left empty, the button\'s own words come back.'],
                ['kind' => 'link', 'label' => 'Opens a link', 'address' => Steps\TelegramApi::ADDRESS, 'prefix' => 'link:', 'written' => 'an address, or "link:" and a formula that gives one', 'placeholder' => 'https://…'],
                ['kind' => 'webapp', 'label' => 'Opens a mini app', 'prefix' => 'webapp:', 'written' => '"webapp:" and its address', 'placeholder' => 'https://…', 'hint' => 'An https address, opened inside Telegram.'],
            ]],
            ['name' => 'per_row', 'label' => 'Buttons side by side', 'type' => 'number', 'required' => false, 'default' => 1, 'when' => ['buttons' => true]],
        ];
    }

    /**
     * @return list<NodeType>
     */
    private static function telegram(): array
    {
        $bot = ['name' => 'connection', 'label' => 'Bot', 'type' => 'connection', 'required' => true, 'services' => ['telegram'], 'linked' => true];
        $chat = ['name' => 'chat', 'label' => 'In chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'From a linked chat, its other end is {{ trigger.room.link.chat }}.'];
        $to = [...$chat, 'label' => 'To chat'];
        $topic = ['name' => 'topic', 'label' => 'In topic', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.topic }}', 'help' => 'In a group with topics: the topic\'s id. Left empty, the general one.'];
        $message = ['name' => 'message', 'label' => 'Message', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.to }}', 'help' => 'Its id: the message that started the run is {{ trigger.reply.to }}; one a step sent, {{ node("send").message_id }}.'];
        $user = ['name' => 'user', 'label' => 'Who', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.sender.id }}', 'help' => 'A Telegram user\'s id.'];
        $format = ['name' => 'format', 'label' => 'Written in', 'type' => 'choice', 'required' => true, 'default' => 'plain', 'options' => ['plain' => 'Plain text', 'markdown' => 'Markdown', 'html' => 'HTML']];
        $until = ['name' => 'minutes', 'label' => 'For how many minutes', 'type' => 'template', 'required' => false, 'help' => 'Left empty, for good.'];

        return [
            // Sent: something new in a chat
            self::kind('telegram.album', 'Send an album on Telegram', 'images', 'Sends from 2 to 10 pictures and videos from the Drive as one message.', [
                $bot, $to, $topic,
                ['name' => 'files', 'label' => 'Files', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("find").files }}', 'help' => 'Files in the Drive: a list of them, or their ref_ids with commas between.'],
                ['name' => 'text', 'label' => 'Words under it', 'type' => 'template', 'required' => false],
            ], sends: true),
            self::kind('telegram.sticker', 'Send a sticker on Telegram', 'sticker', 'Sends a sticker to a chat.', [
                $bot, $to, $topic,
                ['name' => 'sticker', 'label' => 'Sticker', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.sticker.id }}', 'help' => 'A sticker\'s id, as a message that carried one gives it, or the address of a .webp picture.'],
            ], sends: true),
            self::kind('telegram.location', 'Send a location on Telegram', 'map-pin', 'Sends a place to a chat: a pin on the map, or with a name, a venue.', [
                $bot, $to, $topic,
                ['name' => 'lat', 'label' => 'Latitude', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.location.lat }}'],
                ['name' => 'lng', 'label' => 'Longitude', 'type' => 'template', 'required' => true, 'placeholder' => '{{ trigger.location.lng }}'],
                ['name' => 'title', 'label' => 'Name', 'type' => 'template', 'required' => false, 'help' => 'With a name it is sent as a venue.'],
                ['name' => 'address', 'label' => 'Address', 'type' => 'template', 'required' => false, 'when' => ['title' => true]],
            ], sends: true),
            self::kind('telegram.contact', 'Send a contact on Telegram', 'contact', 'Sends someone\'s name and phone number to a chat, to save or call.', [
                $bot, $to, $topic,
                ['name' => 'phone', 'label' => 'Phone number', 'type' => 'template', 'required' => true],
                ['name' => 'first_name', 'label' => 'First name', 'type' => 'template', 'required' => true],
                ['name' => 'last_name', 'label' => 'Last name', 'type' => 'template', 'required' => false],
            ], sends: true),
            self::kind('telegram.poll', 'Send a poll on Telegram', 'chart-bar', 'Asks a chat a question with answers to choose from. A "Telegram message" trigger set to poll answers hears who chose what, when the poll is not anonymous.', [
                $bot, $to, $topic,
                ['name' => 'question', 'label' => 'Question', 'type' => 'template', 'required' => true],
                ['name' => 'options', 'label' => 'Answers', 'type' => 'template', 'required' => true, 'placeholder' => "Yes\nNo\nMaybe", 'help' => 'One on each line, from 2 to 12.'],
                ['name' => 'anonymous', 'label' => 'Who chose what', 'type' => 'choice', 'required' => true, 'default' => 'yes', 'options' => ['yes' => 'Is kept secret', 'no' => 'Can be seen']],
                ['name' => 'several', 'label' => 'Choosing', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'One answer', 'yes' => 'Several answers']],
            ], sends: true),
            self::kind('telegram.forward', 'Forward a message on Telegram', 'forward', 'Forwards a message from one chat to another, marked as forwarded.', [
                $bot,
                ['name' => 'from', 'label' => 'From chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}'],
                $message,
                [...$to, 'default' => null, 'placeholder' => '-1001234567890'],
                $topic,
            ], sends: true),
            self::kind('telegram.copy', 'Copy a message on Telegram', 'copy', 'Sends a copy of a message to another chat, as the bot\'s own: no mark of where it came from.', [
                $bot,
                ['name' => 'from', 'label' => 'From chat', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}'],
                $message,
                [...$to, 'default' => null, 'placeholder' => '-1001234567890'],
                $topic,
            ], sends: true),
            self::kind('telegram.invoice', 'Send an invoice on Telegram', 'credit-card', 'Asks a chat to pay for something: in Telegram Stars, or in money through a payment provider. Telegram then asks the bot to agree ("Telegram message" set to payments; "Answer a checkout on Telegram"), and says when it is paid.', [
                $bot, $to,
                ['name' => 'title', 'label' => 'For', 'type' => 'template', 'required' => true, 'placeholder' => 'Coffee beans, 250 g'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'template', 'required' => false],
                ['name' => 'amount', 'label' => 'Amount', 'type' => 'template', 'required' => true, 'help' => 'A whole number: Stars, or for money its smallest unit -- 1050 for 10.50.'],
                ['name' => 'currency', 'label' => 'In', 'type' => 'template', 'required' => true, 'default' => 'XTR', 'help' => 'XTR is Telegram Stars. Money (USD, THB…) needs a payment provider.'],
                ['name' => 'provider', 'label' => 'Payment provider\'s token', 'type' => 'connection', 'required' => false, 'services' => ['webhook_secret'], 'help' => 'For money: the token @BotFather gives for a provider, kept as a "Webhook secret" connection. Not needed for Stars.'],
                ['name' => 'payload', 'label' => 'Your own reference', 'type' => 'template', 'required' => false, 'placeholder' => 'order-{{ trigger.id }}', 'help' => 'Comes back with the payment, as trigger.payment.payload. Never shown.'],
            ], sends: true),
            self::kind('telegram.topic', 'Make a topic on Telegram', 'message-square-dashed', 'Makes a topic in a group that has topics, and gives out its id as topic, for a later step\'s "In topic".', [
                $bot, $chat,
                ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => true],
            ], sends: true),

            // Changed: what is there, set to what the step says
            self::kind('telegram.action', 'Show typing on Telegram', 'ellipsis', 'Shows the chat that the bot is busy -- "typing…" -- for a few seconds, or until it sends something: before a step that takes a while, like asking an AI.', [
                $bot, $chat, $topic,
                ['name' => 'action', 'label' => 'Busy', 'type' => 'choice', 'required' => true, 'default' => 'typing', 'options' => [
                    'typing' => 'Typing', 'upload_photo' => 'Sending a picture', 'upload_document' => 'Sending a file', 'upload_video' => 'Sending a video',
                    'record_voice' => 'Recording', 'choose_sticker' => 'Choosing a sticker', 'find_location' => 'Finding a place',
                ]],
            ]),
            self::kind('telegram.edit', 'Edit a message on Telegram', 'pencil', 'Changes what a message the bot sent says, in place -- and its buttons: the ones named here, or none.', [
                $bot, $chat,
                [...$message, 'default' => null, 'placeholder' => '{{ node("send").message_id }}', 'help' => 'The id of a message the bot sent: {{ node("send").message_id }}; after a press, the message pressed is {{ trigger.reply.to }}.'],
                ['name' => 'text', 'label' => 'Now says', 'type' => 'template', 'required' => true],
                $format,
                ...self::telegramButtons('Left empty, the message\'s buttons are taken off. Each is what the button says, and what pressing it does.'),
            ]),
            self::kind('telegram.edit_buttons', 'Change a message\'s buttons on Telegram', 'square-mouse-pointer', 'Puts other buttons under a message the bot sent, or takes them off -- once one has been pressed, say -- leaving its words as they are.', [
                $bot, $chat,
                [...$message, 'help' => 'The id of a message the bot sent; after a press, the message pressed is {{ trigger.reply.to }}.'],
                ...self::telegramButtons('Left empty, the buttons are taken off. Each is what the button says, and what pressing it does.'),
            ]),
            self::kind('telegram.delete', 'Delete a message on Telegram', 'trash-2', 'Takes a message out of a chat: the bot\'s own, or in a group it looks after, anyone\'s. Only within two days of it.', [$bot, $chat, $message]),
            self::kind('telegram.pin', 'Pin a message on Telegram', 'pin', 'Pins a message at the top of a chat. In a group, the bot has to be allowed to.', [
                $bot, $chat, $message,
                ['name' => 'notify', 'label' => 'Tell everyone', 'type' => 'choice', 'required' => true, 'default' => 'no', 'options' => ['no' => 'Quietly', 'yes' => 'With a notification']],
            ]),
            self::kind('telegram.unpin', 'Unpin a message on Telegram', 'pin-off', 'Takes a pinned message down from the top of a chat.', [
                $bot, $chat,
                [...$message, 'required' => false, 'default' => null, 'help' => 'Its id. Left empty, the one pinned last.'],
            ]),
            self::kind('telegram.react', 'React to a message on Telegram', 'smile', 'Puts an emoji on a message, as the bot\'s reaction -- seen at once, where an answer would take longer.', [
                $bot, $chat, $message,
                ['name' => 'emoji', 'label' => 'Emoji', 'type' => 'template', 'required' => false, 'default' => '👍', 'help' => 'One of the emoji Telegram lets a reaction be. Left empty, the bot\'s reaction is taken off.'],
            ]),
            self::kind('telegram.answer_button', 'Answer a button press on Telegram', 'mouse-pointer-click', 'Tells Telegram a press was heard, so the button stops waiting -- with a few words shown to whoever pressed, if any.', [
                $bot,
                ['name' => 'press', 'label' => 'The press', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.button.id }}'],
                ['name' => 'text', 'label' => 'Say', 'type' => 'template', 'required' => false, 'placeholder' => 'Saved!'],
                ['name' => 'show', 'label' => 'Shown', 'type' => 'choice', 'required' => true, 'default' => 'toast', 'options' => ['toast' => 'Briefly, at the top', 'alert' => 'In a box to close'], 'when' => ['text' => true]],
            ]),
            self::kind('telegram.commands', 'Set the bot\'s commands on Telegram', 'slash', 'Sets the list a chat offers when "/" is typed: each command, and what it does.', [
                $bot,
                ['name' => 'commands', 'label' => 'Commands', 'type' => 'fields', 'required' => true, 'default' => [], 'help' => 'Each named without its "/", in lowercase letters, digits and _: expense, and what it does: "Note an expense". None at all clears the list.'],
            ]),
            self::kind('telegram.ban', 'Ban someone on Telegram', 'ban', 'Puts someone out of a group the bot looks after, and keeps them out.', [$bot, $chat, $user, $until]),
            self::kind('telegram.unban', 'Lift a ban on Telegram', 'user-check', 'Lets someone banned from a group come back; they are not added, only allowed.', [$bot, $chat, $user]),
            self::kind('telegram.restrict', 'Limit someone on Telegram', 'shield', 'Sets what someone may do in a group the bot looks after.', [
                $bot, $chat, $user,
                ['name' => 'allow', 'label' => 'They may', 'type' => 'choice', 'required' => true, 'default' => 'nothing', 'options' => ['nothing' => 'Only read', 'messages' => 'Write, but send nothing else', 'everything' => 'Do everything again']],
                $until,
            ]),
            self::kind('telegram.answer_inline', 'Answer an inline question on Telegram', 'at-sign', 'Answers what someone typed after the bot\'s @name in any chat, with results to choose from. Starts from a "Telegram message" trigger set to inline questions; the bot needs inline mode switched on in @BotFather.', [
                $bot,
                ['name' => 'question', 'label' => 'The question', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.inline.id }}'],
                ['name' => 'results', 'label' => 'Results', 'type' => 'fields', 'required' => true, 'default' => [], 'labels' => true, 'help' => 'Each is a result\'s title, and the message that choosing it sends. What was typed is {{ trigger.text }}.'],
            ]),
            self::kind('telegram.answer_checkout', 'Answer a checkout on Telegram', 'badge-check', 'Agrees to a payment about to be made, or refuses it. Telegram waits ten seconds for this, so put it first after the trigger.', [
                $bot,
                ['name' => 'checkout', 'label' => 'The checkout', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.checkout.id }}'],
                ['name' => 'answer', 'label' => 'Answer', 'type' => 'choice', 'required' => true, 'default' => 'ok', 'options' => ['ok' => 'Go ahead', 'refuse' => 'Refuse it']],
                ['name' => 'why', 'label' => 'Why not', 'type' => 'template', 'required' => false, 'placeholder' => 'Sorry, the last one just went.', 'when' => ['answer' => 'refuse']],
            ]),

            // Asked
            self::kind('telegram.chat', 'Telegram: about a chat', 'info', 'Asks Telegram about a chat: gives out its id, kind, title, username, description, and whether it has topics.', [$bot, $chat]),
            self::kind('telegram.member', 'Telegram: about a member', 'user-search', 'Asks Telegram how someone stands in a chat: gives out their name, their status, and whether they are in it and look after it.', [$bot, $chat, $user]),
            self::kind('telegram.member_count', 'Telegram: how many members', 'users', 'Asks Telegram how many people are in a chat, as count.', [$bot, $chat]),
        ];
    }

    // --- LINE ---

    /**
     * What a LINE message is made of, the same for every call that carries
     * one (reply, push, multicast, broadcast).
     *
     * @return list<Setting>
     */
    public static function lineMessage(): array
    {
        $buttonHelp = 'Each is what it says (20 letters), and what tapping it does.';
        $does = self::lineDoes();

        return [
            ['name' => 'kind', 'label' => 'Send', 'type' => 'choice', 'required' => true, 'default' => 'text', 'options' => [
                'text' => 'Words, a picture, or both', 'sticker' => 'A sticker', 'location' => 'A place', 'video' => 'A video', 'audio' => 'A recording',
                'buttons' => 'Buttons to choose from', 'confirm' => 'A yes-or-no question', 'flex' => 'A Flex card',
            ]],
            ['name' => 'text', 'label' => 'Message', 'type' => 'template', 'required' => false, 'when' => ['kind' => ['text', 'location', 'buttons', 'confirm']], 'help' => 'With a picture or a place, it can be left empty.'],
            ['name' => 'file', 'label' => 'Picture', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'when' => ['kind' => 'text'], 'help' => 'A JPEG or PNG in the Drive, by its ref_id, at most 10 MB. LINE fetches it from this app, which must be reachable from outside.'],
            ['name' => 'quote', 'label' => 'Quoting', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.reply.quote }}', 'when' => ['kind' => 'text'], 'help' => 'The quote token of a message, to show the words as an answer to it.'],
            ['name' => 'package', 'label' => 'Sticker package', 'type' => 'template', 'required' => false, 'placeholder' => '446', 'when' => ['kind' => 'sticker'], 'help' => 'Only the stickers LINE lists as sendable; a message\'s own are trigger.sticker.package and trigger.sticker.id.'],
            ['name' => 'sticker', 'label' => 'Sticker', 'type' => 'template', 'required' => false, 'placeholder' => '1988', 'when' => ['kind' => 'sticker']],
            ['name' => 'title', 'label' => 'Title', 'type' => 'template', 'required' => false, 'when' => ['kind' => ['location', 'buttons']]],
            ['name' => 'address', 'label' => 'Address', 'type' => 'template', 'required' => false, 'when' => ['kind' => 'location']],
            ['name' => 'lat', 'label' => 'Latitude', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.location.lat }}', 'when' => ['kind' => 'location']],
            ['name' => 'lng', 'label' => 'Longitude', 'type' => 'template', 'required' => false, 'placeholder' => '{{ trigger.location.lng }}', 'when' => ['kind' => 'location']],
            ['name' => 'media', 'label' => 'File', 'type' => 'template', 'required' => false, 'placeholder' => '{{ node("save").ref_id }}', 'when' => ['kind' => ['video', 'audio']], 'help' => 'In the Drive, by its ref_id: an MP4 video, or an MP3 or M4A recording. LINE fetches it from this app.'],
            ['name' => 'preview', 'label' => 'Picture shown first', 'type' => 'template', 'required' => false, 'drive' => 'image', 'when' => ['kind' => 'video'], 'help' => 'A JPEG or PNG in the Drive: what the video shows before it plays.'],
            ['name' => 'seconds', 'label' => 'How long, in seconds', 'type' => 'template', 'required' => false, 'when' => ['kind' => 'audio'], 'help' => 'LINE shows this as the recording\'s length.'],
            ['name' => 'choices', 'label' => 'Buttons', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'when' => ['kind' => 'buttons'], 'help' => "Up to four. {$buttonHelp}", 'does' => $does],
            ['name' => 'yes', 'label' => 'Yes says', 'type' => 'template', 'required' => false, 'default' => 'Yes', 'when' => ['kind' => 'confirm'], 'help' => 'Tapping it says so in the chat.'],
            ['name' => 'no', 'label' => 'No says', 'type' => 'template', 'required' => false, 'default' => 'No', 'when' => ['kind' => 'confirm']],
            ['name' => 'flex', 'label' => 'Card', 'type' => 'template', 'required' => false, 'when' => ['kind' => 'flex'], 'placeholder' => '{ "type": "bubble", "body": { … } }', 'help' => 'The JSON of a bubble or a carousel, as LINE\'s Flex Message Simulator gives it. It can hold {{ formulas }}.'],
            ['name' => 'alt', 'label' => 'In a notification', 'type' => 'template', 'required' => false, 'when' => ['kind' => ['flex', 'buttons', 'confirm']], 'help' => 'What a notification says, where the card or the buttons can\'t be drawn.'],
            ['name' => 'quick', 'label' => 'Quick replies', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'help' => "Shown over the keyboard until one is tapped; up to 13. {$buttonHelp}", 'does' => $does],
        ];
    }

    /**
     * @return list<NodeType>
     */
    private static function line(): array
    {
        $channel = ['name' => 'connection', 'label' => 'Channel', 'type' => 'connection', 'required' => true, 'services' => ['line'], 'linked' => true];
        $user = ['name' => 'user', 'label' => 'Who', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.sender.id }}', 'help' => 'A LINE user id (U…).'];
        $group = ['name' => 'chat', 'label' => 'Group', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.chat.id }}', 'help' => 'A group (C…) or a multi-person chat (R…).'];

        return [
            self::kind('line.multicast', 'Send to many on LINE', 'users', 'Sends one message to several people at once, by LINE\'s multicast call: up to 500, each counted against the channel\'s monthly allowance.', [
                $channel,
                ['name' => 'to', 'label' => 'To', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("find").rows.line_id }}', 'help' => 'LINE user ids (U…): a list of them, or with commas between. Not groups.'],
                ...self::lineMessage(),
            ], sends: true, unsureHelp: 'It never goes out twice: LINE is given a key for it.'),
            self::kind('line.broadcast', 'Send to everyone on LINE', 'megaphone', 'Sends one message to everyone who has added the channel as a friend, by LINE\'s broadcast call: each of them counted against the monthly allowance.', [
                $channel,
                ...self::lineMessage(),
            ], sends: true, unsureHelp: 'It never goes out twice: LINE is given a key for it.'),
            self::kind('line.loading', 'Show loading on LINE', 'ellipsis', 'Shows someone that an answer is on its way -- three moving dots -- until a message comes or the time is up. Only in a chat with one person, and only while they have it open.', [
                $channel,
                ['name' => 'chat', 'label' => 'To', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.sender.id }}', 'help' => 'A LINE user id (U…).'],
                ['name' => 'seconds', 'label' => 'For at most, in seconds', 'type' => 'number', 'required' => true, 'default' => 20],
            ]),
            self::kind('line.read', 'Mark as read on LINE', 'check-check', 'Marks a message, and all before it in its chat, as read. With Chat switched on for the channel; with it off, LINE marks everything read by itself.', [
                $channel,
                ['name' => 'token', 'label' => 'Read token', 'type' => 'template', 'required' => true, 'default' => '{{ trigger.reply.read }}', 'help' => 'What a LINE message comes with: {{ trigger.reply.read }}.'],
            ]),
            self::kind('line.leave', 'Leave a group on LINE', 'log-out', 'Takes the channel out of a group or a multi-person chat.', [$channel, $group]),
            ...self::lineRichMenus($channel, $user),
            self::kind('line.quota', 'LINE: monthly allowance', 'gauge', 'Asks LINE how many messages the channel may send first this month: limited, and limit.', [$channel]),
            self::kind('line.consumption', 'LINE: sent this month', 'chart-bar', 'Asks LINE how many messages counted against the allowance have gone out this month, as sent.', [$channel]),
            self::kind('line.group', 'LINE: about a group', 'info', 'Asks LINE for a group\'s name and picture.', [$channel, $group]),
            self::kind('line.group_count', 'LINE: how many members', 'users', 'Asks LINE how many people are in a group or a multi-person chat, as count.', [$channel, $group]),
        ];
    }

    /**
     * What tapping a LINE button may do, and how each is written as the
     * button's value (LineApi::action reads it). On a rich menu, a button
     * may turn to another menu too.
     *
     * @return list<array<string, string>>
     */
    private static function lineDoes(bool $menu = false): array
    {
        return [
            ['kind' => 'say', 'label' => 'Says a message in the chat', 'written' => 'anything else', 'placeholder' => 'Its own words', 'hint' => 'Said as the person\'s own message. Left empty, the button\'s own words are said.'],
            ['kind' => 'link', 'label' => 'Opens a link', 'address' => Steps\LineApi::ADDRESS, 'prefix' => 'link:', 'written' => 'an address, or "link:" and a formula that gives one', 'placeholder' => 'https://…', 'hint' => 'An address on the internet, tel: and a number to call, or line:// for somewhere in LINE.'],
            ['kind' => 'postback', 'label' => 'Sends data back, unseen', 'prefix' => 'postback:', 'written' => '"postback:" and the data', 'placeholder' => 'hours', 'hint' => 'Comes back as trigger.text of a "LINE message" trigger set to buttons.'],
            ...$menu ? [['kind' => 'menu', 'label' => 'Turns to another menu', 'prefix' => 'menu:', 'written' => '"menu:" and the other menu\'s short name', 'placeholder' => 'more', 'hint' => 'The other menu\'s short name. It turns to it as a tab does.']] : [],
        ];
    }

    /**
     * The rich menu -- the panel under a chat -- made, shown and named.
     *
     * @param  Setting  $channel
     * @param  Setting  $user
     * @return list<NodeType>
     */
    private static function lineRichMenus(array $channel, array $user): array
    {
        $menu = ['name' => 'menu', 'label' => 'Rich menu', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("make").id }}', 'help' => 'Its id (richmenu-…), or a short name given to it; "LINE: rich menus" lists the channel\'s.'];
        $alias = ['name' => 'alias', 'label' => 'Short name', 'type' => 'template', 'required' => true, 'placeholder' => 'main', 'help' => 'Up to 32 of a-z, 0-9, "-" and "_".'];
        $many = ['name' => 'to', 'label' => 'Who', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("find").rows.line_id }}', 'help' => 'LINE user ids (U…): a list of them, or with commas between; up to 500.'];
        $layouts = [
            'six' => 'Six: three across, two rows',
            'four' => 'Four: two across, two rows',
            'top' => 'Four: one across the top, three under it',
            'large' => 'Three: a large one, two beside it',
            'rows' => 'Two: one over the other',
            'three' => 'Three, side by side',
            'two' => 'Two, side by side',
            'narrow' => 'Two: a narrow one, then a wide one',
            'wide' => 'Two: a wide one, then a narrow one',
            'one' => 'One: the whole picture',
            'custom' => 'Areas of my own',
        ];

        return [
            self::kind('line.richmenu_make', 'Make a rich menu on LINE', 'panel-bottom-open', 'Makes a rich menu -- the panel under a chat: a picture, and places on it to tap -- and puts its picture on it. It is shown to no one until it is set for everyone or for someone. Its picture and its buttons can\'t be changed after: a menu that is to look different is made again. Gives out its id.', [
                $channel,
                ['name' => 'name', 'label' => 'Called', 'type' => 'template', 'required' => true, 'placeholder' => 'Main menu', 'help' => 'For telling the channel\'s menus apart; people never see it.'],
                ['name' => 'picture', 'label' => 'Picture', 'type' => 'template', 'required' => true, 'placeholder' => '{{ node("save").ref_id }}', 'drive' => 'image', 'help' => 'A JPEG or PNG in the Drive, by its ref_id, at most 1 MB: 800 to 2500 wide, at least 250 high, at least 1.45 times as wide as it is high. LINE\'s own are 2500 × 1686 and, half as high, 2500 × 843. The menu is made the picture\'s size.'],
                ['name' => 'layout', 'label' => 'Buttons on it', 'type' => 'choice', 'required' => true, 'default' => 'six', 'options' => $layouts, 'help' => 'Where the picture can be tapped. Draw the picture to match.'],
                ['name' => 'buttons', 'label' => 'Buttons', 'type' => 'fields', 'required' => false, 'default' => [], 'labels' => true, 'when' => ['layout' => array_values(array_diff(array_keys($layouts), ['custom']))], 'help' => 'From left to right, top to bottom. Each is what it is (read aloud for those who can\'t see the picture; 20 letters), and what tapping it does. A place left without a button does nothing.', 'does' => self::lineDoes(menu: true), 'places' => ['by' => 'layout', 'picture' => 'picture', 'layouts' => Steps\LineRichMenu::LAYOUTS]],
                ['name' => 'areas', 'label' => 'Areas', 'type' => 'template', 'required' => false, 'when' => ['layout' => 'custom'], 'placeholder' => '[{ "bounds": { "x": 0, "y": 0, "width": 1250, "height": 843 }, "action": { "type": "message", "text": "Hours" } }]', 'help' => 'A JSON list of up to 20, each with its bounds on the picture and its action, as LINE takes them. It can hold {{ formulas }}.'],
                ['name' => 'bar', 'label' => 'On the bar', 'type' => 'template', 'required' => false, 'default' => 'Menu', 'help' => 'The words on the bar under the chat that opens and closes the menu; at most 14 letters.'],
                ['name' => 'open', 'label' => 'When the chat is opened', 'type' => 'choice', 'required' => true, 'default' => 'yes', 'options' => ['yes' => 'The menu is open', 'no' => 'The menu is closed until its bar is tapped']],
            ]),
            self::kind('line.richmenu_default', 'Show a rich menu to everyone on LINE', 'panel-bottom', 'Makes a rich menu the one everyone sees who has none of their own, in place of the one shown before. It comes before a menu set in LINE Official Account Manager.', [$channel, $menu]),
            self::kind('line.richmenu_default_off', 'Stop showing a rich menu to everyone on LINE', 'panel-bottom-close', 'Takes away the rich menu shown to everyone. Those given one of their own keep it; the rest see the one set in LINE Official Account Manager, if there is one.', [$channel]),
            self::kind('line.richmenu', 'Set someone\'s rich menu on LINE', 'panel-bottom', 'Gives one person a rich menu of the channel\'s -- the panel under their chat -- in place of the usual one.', [$channel, $user, $menu]),
            self::kind('line.richmenu_off', 'Take off someone\'s rich menu on LINE', 'panel-bottom-close', 'Takes away the rich menu given to one person; they see the channel\'s usual one again.', [$channel, $user]),
            self::kind('line.richmenu_many', 'Set many people\'s rich menu on LINE', 'panel-bottom', 'Gives several people a rich menu of the channel\'s at once, in place of the usual one. LINE does it shortly after, not at once.', [$channel, $many, $menu]),
            self::kind('line.richmenu_many_off', 'Take off many people\'s rich menu on LINE', 'panel-bottom-close', 'Takes away the rich menu given to several people at once; they see the channel\'s usual one again. LINE does it shortly after, not at once.', [$channel, $many]),
            self::kind('line.richmenu_of', 'LINE: someone\'s rich menu', 'panel-bottom-dashed', 'Asks LINE which rich menu one person was given: its id as menu, or nothing when they see the usual one.', [$channel, $user]),
            self::kind('line.richmenu_alias', 'Name a rich menu on LINE', 'tag', 'Gives a rich menu a short name, by which a button on another menu turns to it ("menu:" and the name) and any step can name it. A name already in use is moved to this menu: every tab that names it then turns to the new one.', [$channel, $alias, $menu]),
            self::kind('line.richmenu_alias_off', 'Take away a rich menu\'s name on LINE', 'tag', 'Takes a short name away from the rich menu that goes by it. A tab that names it does nothing after.', [$channel, $alias]),
            self::kind('line.richmenu_delete', 'Delete a rich menu on LINE', 'trash-2', 'Deletes a rich menu of the channel\'s for good. Those shown it see the usual one, or none.', [$channel, $menu]),
            self::kind('line.richmenus', 'LINE: rich menus', 'list', 'Asks LINE for the channel\'s rich menus: gives out menus, each with its id, name, the words on its bar, its size, how many places it has to tap, the short names it goes by (aliases), and whether everyone is shown it (default). Menus drawn in LINE Official Account Manager are not among them.', [$channel]),
        ];
    }

    /**
     * One kind: in its service's group, with one way in and one out. One
     * that sends is not simply made again, and says what happens instead.
     *
     * @param  list<Setting>  $settings
     * @return NodeType
     */
    private static function kind(string $type, string $label, string $icon, string $description, array $settings, bool $sends = false, ?string $unsureHelp = null): array
    {
        return [
            'type' => $type,
            'version' => 1,
            'label' => $label,
            'group' => explode('.', $type)[0],
            'icon' => $icon,
            'description' => $description,
            'trigger' => false,
            ...$sends ? ['repeatable' => false] : [],
            'inputs' => [NodeTypes::IN],
            'outputs' => [NodeTypes::OUT],
            'config' => [
                ...array_map(fn (array $setting) => array_filter($setting, fn ($part) => $part !== null), $settings),
                ...$sends ? [[...self::UNSURE, ...$unsureHelp === null ? [] : ['help' => $unsureHelp]]] : [],
            ],
        ];
    }
}
