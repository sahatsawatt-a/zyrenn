<?php

namespace App\Support\Workflow;

/**
 * Workflows to start from, already joined up: each needs only the
 * connections it names. Chosen from the workflow list; what is made is an
 * ordinary draft, to change at will, published like any other.
 *
 * @phpstan-type Starter array{title: string, description: string, needs: array<string, string>, definition: array<string, mixed>}
 */
final class Templates
{
    /**
     * Every template by its key, its connections filled in from $chosen
     * (service => connection ref_id) when given.
     *
     * @param  array<string, string>  $chosen
     * @return array<string, Starter>
     */
    public static function all(array $chosen = []): array
    {
        $telegram = $chosen['telegram'] ?? '';
        $line = $chosen['line'] ?? '';
        $ai = $chosen['ai'] ?? '';

        return [
            'telegram-room' => [
                'title' => 'Telegram chats in a room, both ways',
                'description' => 'Each Telegram chat with your bot gets a chat of its own here: what is sent to the bot is written in it, and what you write in it goes back to Telegram -- pictures, files, stickers and places too, kept in the Drive on the way.',
                'needs' => ['telegram' => 'Bot'],
                'definition' => self::bridge('telegram', $telegram),
            ],
            'line-room' => [
                'title' => 'LINE chats in a room, both ways',
                'description' => 'Each LINE chat with your channel gets a chat of its own here: what is sent to it is written in it, and what you write in it goes back to LINE (counted against the channel\'s monthly allowance) -- pictures, files, stickers and places too, kept in the Drive on the way.',
                'needs' => ['line' => 'Channel'],
                'definition' => self::bridge('line', $line),
            ],
            'telegram-ai' => [
                'title' => 'AI answers on Telegram',
                'description' => 'Whatever is sent to your bot is put to an AI model, and its answer sent back.',
                'needs' => ['telegram' => 'Bot', 'ai' => 'AI connection'],
                'definition' => self::chain([
                    ['heard', 'trigger.telegram', ['connection' => $telegram]],
                    ['ask', 'ai.chat', ['connection' => $ai, 'system' => 'You answer messages sent to a Telegram bot. Be brief and friendly.', 'prompt' => '{{ trigger.text }}']],
                    ['answer', 'telegram.send', ['connection' => $telegram, 'chat' => '{{ trigger.chat.id }}', 'text' => '{{ node("ask").text }}']],
                ]),
            ],
            'line-ai' => [
                'title' => 'AI answers on LINE',
                'description' => 'Whatever is sent to your LINE channel is put to an AI model, and its answer sent back.',
                'needs' => ['line' => 'Channel', 'ai' => 'AI connection'],
                'definition' => self::chain([
                    ['heard', 'trigger.line', ['connection' => $line]],
                    ['ask', 'ai.chat', ['connection' => $ai, 'system' => 'You answer messages sent to a LINE account. Be brief and friendly.', 'prompt' => '{{ trigger.text }}']],
                    ['answer', 'line.reply', ['connection' => $line, 'text' => '{{ node("ask").text }}']],
                ]),
            ],
            'telegram-drive' => [
                'title' => 'Files sent on Telegram, kept in the Drive',
                'description' => 'A picture or a file sent to your bot is saved in the Drive, in a folder "Telegram", and the bot says so.',
                'needs' => ['telegram' => 'Bot'],
                'definition' => self::chain([
                    ['heard', 'trigger.telegram', ['connection' => $telegram]],
                    ['has', 'logic.if', ['condition' => 'len(trigger.files) > 0']],
                    ['save', 'telegram.file', ['connection' => $telegram, 'folder' => 'Telegram']],
                    ['answer', 'telegram.send', ['connection' => $telegram, 'chat' => '{{ trigger.chat.id }}', 'text' => 'Saved {{ node("save").name }} in the Drive.']],
                ], ['has' => 'true']),
            ],
            'line-drive' => [
                'title' => 'Files sent on LINE, kept in the Drive',
                'description' => 'A picture or a file sent to your LINE channel is saved in the Drive, in a folder "LINE", and the channel says so.',
                'needs' => ['line' => 'Channel'],
                'definition' => self::chain([
                    ['heard', 'trigger.line', ['connection' => $line]],
                    ['has', 'logic.if', ['condition' => 'len(trigger.files) > 0']],
                    ['save', 'line.file', ['connection' => $line, 'folder' => 'LINE']],
                    ['answer', 'line.reply', ['connection' => $line, 'text' => 'Saved {{ node("save").name }} in the Drive.']],
                ], ['has' => 'true']),
            ],
            'line-menu' => [
                'title' => 'A rich menu for your LINE channel',
                'description' => 'Run by hand, it makes the panel under the chat from a picture in your Drive -- three buttons side by side -- and shows it to everyone. Choose the picture and say what each button does first; to change the menu later, change them and run it again.',
                'needs' => ['line' => 'Channel'],
                'definition' => self::chain([
                    ['start', 'trigger.manual', []],
                    ['make', 'line.richmenu_make', ['connection' => $line, 'name' => 'Main menu', 'layout' => 'three', 'bar' => 'Menu', 'buttons' => [
                        ['name' => 'Opening hours', 'value' => 'What are your opening hours?'],
                        ['name' => 'Website', 'value' => 'https://example.com'],
                        ['name' => 'Talk to us', 'value' => 'I would like to talk to someone'],
                    ]]],
                    // Its short name moves to each new menu, so a tab or a step that says "main" means the latest
                    ['name', 'line.richmenu_alias', ['connection' => $line, 'alias' => 'main', 'menu' => '{{ node("make").id }}']],
                    ['show', 'line.richmenu_default', ['connection' => $line, 'menu' => '{{ node("make").id }}']],
                ]),
            ],
            'daily-telegram' => [
                'title' => 'Daily summary to Telegram',
                'description' => 'Every weekday morning, a table\'s rows are summed up by an AI model and sent to a Telegram chat. Choose the table and the chat after.',
                'needs' => ['telegram' => 'Bot', 'ai' => 'AI connection'],
                'definition' => self::chain([
                    ['morning', 'trigger.schedule', ['cron' => '0 8 * * 1-5']],
                    ['rows', 'table.find', ['limit' => 100]],
                    ['ask', 'ai.chat', ['connection' => $ai, 'system' => 'Sum up these rows for a busy reader in a few lines.', 'prompt' => '{{ node("rows").rows }}']],
                    ['send', 'telegram.send', ['connection' => $telegram, 'chat' => '', 'text' => '{{ node("ask").text }}']],
                ]),
            ],
        ];
    }

    /**
     * An outside chat and a room of its own here, both ways, with whatever
     * the service carries.
     *
     * Coming in, a message goes by its kind: one that brought a picture or a
     * file has it fetched by the service's own "get the file" step, into a
     * Drive folder named for the service, and shown in the room with what
     * was written beside it; a sticker is shown as its picture, or named
     * when that can't be had; a place is shown on a small map; a contact
     * shared on Telegram is written as its name and number; anything else
     * is said as it was.
     *
     * Who it is with is kept with the room: their name and id, and their
     * picture -- LINE gives its address; Telegram's is fetched into the
     * Drive, once for each photo.
     *
     * Going out, what is written in the room goes to the outside chat, and
     * a file sent there goes with it: by Telegram's own call for a file; on
     * LINE as a picture when it is one LINE takes, else as a link that
     * opens it for a day. A place shared there goes out as a place. On LINE
     * each is tried first as a reply to the last thing the other end wrote,
     * which LINE does not count, and pushed when that can't go: after a
     * minute, or once the token is used. On LINE, too: a message is marked read there once
     * someone has seen it here, and someone who adds the channel has a room
     * before they write.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function bridge(string $service, string $connection): array
    {
        $line = $service === 'line';
        $named = $line ? 'LINE' : 'Telegram';
        $sender = $line ? '{{ coalesce(node("who").name, "Someone") }} (LINE)' : '{{ trigger.sender.name }} (Telegram)';
        $room = ['room' => '{{ node("room").room }}', 'sender' => $sender];
        $out = $line
            ? ['connection' => References::LINKED, 'to' => '{{ trigger.room.link.chat }}']
            : ['connection' => References::LINKED, 'chat' => '{{ trigger.room.link.chat }}'];
        $send = "{$service}.send";
        // Where the question of its kind stands: LINE asks who wrote first; Telegram asks about the
        // chat, then fetches its photo
        $at = $line ? 3 : 4;

        /** @var list<array{0: int, 1: int, 2: string, 3: string, 4: array<string, mixed>, 5?: string}> $steps */
        $steps = [
            // In: column, row, key, kind, settings, and what a failure does when not stopping
            [0, 0, 'heard', "trigger.{$service}", ['connection' => $connection]],
            ...$line ? [[1, 0, 'who', 'line.profile', ['connection' => $connection, 'user' => '{{ trigger.sender.id }}', 'chat' => '{{ trigger.chat.id }}'], 'continue']] : [
                [1, 0, 'about', 'telegram.chat', ['connection' => $connection, 'chat' => '{{ trigger.chat.id }}'], 'continue'],
                // Kept once for each photo: the same one is not fetched again with every message. A chat
                // that shows a bot no photo has none to fetch, and the room keeps the face it had
                [2, 0, 'face', 'telegram.file', ['connection' => $connection, 'file' => '{{ node("about").photo }}', 'name' => 'face-{{ node("about").photo_id }}.jpg', 'folder' => 'Telegram/Faces', 'same' => 'reuse'], 'continue'],
            ],
            [$at - 1, 0, 'room', 'chat.room', [
                'key' => "{$service}:{{ trigger.chat.id }}",
                'title' => $line
                    ? '{{ coalesce(node("who").group, node("who").name, "Someone") }} on LINE'
                    : '{{ coalesce(trigger.chat.title, trigger.sender.name, "Someone") }} on Telegram',
                'link' => $connection,
                'link_chat' => '{{ trigger.chat.id }}',
                // Who it is with, kept with the room: their face, and what the service says of them
                ...$line ? [
                    // The face of the one person a chat is with; a group has many, and keeps none
                    'picture' => '{{ if(trigger.chat.kind = "private", coalesce(node("who").picture, ""), "") }}',
                    'details' => [
                        ['name' => 'name', 'value' => '{{ coalesce(node("who").name, "") }}'],
                        ['name' => 'line_id', 'value' => '{{ trigger.sender.id }}'],
                        ['name' => 'language', 'value' => '{{ coalesce(node("who").language, "") }}'],
                        ['name' => 'status', 'value' => '{{ coalesce(node("who").status, "") }}'],
                    ],
                ] : [
                    // The chat's own photo: whoever a one-to-one chat is with, or the group's
                    'picture' => '{{ coalesce(node("face").ref_id, "") }}',
                    'details' => [
                        ['name' => 'name', 'value' => '{{ coalesce(trigger.sender.name, "") }}'],
                        ['name' => 'telegram_id', 'value' => '{{ trigger.sender.id }}'],
                        ['name' => 'username', 'value' => '{{ coalesce(trigger.sender.username, "") }}'],
                    ],
                ],
            ]],
            [$at, 0, 'kind', 'logic.switch', ['ways' => [
                ['name' => 'file', 'value' => 'len(trigger.files) > 0'],
                ['name' => 'sticker', 'value' => 'trigger.kind = "sticker"'],
                ['name' => 'place', 'value' => 'trigger.kind = "location"'],
                // LINE tells a channel nothing of a profile shared with it; Telegram sends the contact
                ...$line ? [] : [['name' => 'contact', 'value' => 'trigger.kind = "contact"']],
            ]]],
            ...$line ? [] : [[$at + 1, 2, 'saywho', 'chat.send', [...$room, 'message' => "👤 {{ trigger.contact.name }}\n{{ coalesce(trigger.contact.phone, \"\") }}"]]],
            [$at + 1, -2, 'save', "{$service}.file", ['connection' => $connection, 'folder' => $named]],
            [$at + 2, -2, 'show', 'chat.send', [...$room, 'message' => '{{ trigger.display }}', 'file' => '{{ node("save").ref_id }}']],
            // A sticker's picture, moving when the sticker does: LINE's are at an address of their own;
            // Telegram's is a file like any other
            $line
                ? [$at + 1, -1, 'sticker', 'drive.save', ['from' => 'url', 'url' => '{{ trigger.sticker.url }}', 'name' => 'sticker-{{ trigger.sticker.id }}{{ if(trigger.sticker.animated, "-animated", "") }}.png', 'folder' => 'LINE/Stickers', 'same' => 'reuse'], 'continue']
                : [$at + 1, -1, 'sticker', 'telegram.file', ['connection' => $connection, 'file' => '{{ trigger.sticker.picture }}', 'folder' => 'Telegram/Stickers'], 'continue'],
            // Named only when its picture could not be had
            [$at + 2, -1, 'showsticker', 'chat.send', [...$room, 'message' => $line ? '{{ if(node("sticker").ref_id, "", "Sticker") }}' : '{{ if(node("sticker").ref_id, "", coalesce(trigger.sticker.emoji, "Sticker")) }}', 'file' => '{{ node("sticker").ref_id }}', 'shown' => 'sticker']],
            [$at + 1, 0, 'saywhere', 'chat.send', [...$room, 'message' => '{{ trigger.display }}', 'place' => '{{ trigger.location }}']],
            [$at + 1, 1, 'say', 'chat.send', [...$room, 'message' => '{{ trigger.display }}']],

            // Out
            [0, 4, 'written', 'trigger.chat', ['room' => References::LINKED.":{$service}"]],
            [1, 4, 'out', 'logic.switch', ['ways' => [
                ['name' => 'file', 'value' => 'len(trigger.files) > 0'],
                ['name' => 'place', 'value' => 'trigger.kind = "location"'],
            ]]],
            // A place shared in the room goes out as one, by the service's own call for it
            $line
                ? [2, 6, 'backplace', $send, [...$out, 'kind' => 'location', 'title' => '{{ coalesce(trigger.location.title, "A place") }}', 'address' => '{{ coalesce(trigger.location.address, "") }}', 'lat' => '{{ trigger.location.lat }}', 'lng' => '{{ trigger.location.lng }}', 'text' => '{{ trigger.text }}']]
                : [2, 6, 'backplace', 'telegram.location', [...$out, 'lat' => '{{ trigger.location.lat }}', 'lng' => '{{ trigger.location.lng }}', 'title' => '{{ coalesce(trigger.location.title, "") }}', 'address' => '{{ coalesce(trigger.location.address, "") }}']],
            // Telegram's place carries no words, so any written with it follow it
            ...$line ? [] : [
                [3, 6, 'worded', 'logic.if', ['condition' => 'trigger.text']],
                [4, 6, 'backwords', $send, [...$out, 'text' => '{{ trigger.text }}']],
            ],
            [2, 5, 'back', $send, [...$out, 'text' => '{{ trigger.text }}']],
            ...$line ? [
                [2, 3, 'file', 'drive.read', ['file' => '{{ item(trigger.files, 1) }}']],
                // LINE is sent a picture only as a JPEG or a PNG; anything else goes as a link to it
                [3, 3, 'picture', 'logic.if', ['condition' => 'node("file").mime = "image/jpeg" or node("file").mime = "image/png"']],
                [4, 2, 'backpicture', $send, [...$out, 'text' => '{{ trigger.text }}', 'file' => '{{ node("file").ref_id }}']],
                [4, 4, 'link', 'drive.link', ['file' => '{{ node("file").ref_id }}', 'hours' => 24]],
                [5, 4, 'backlink', $send, [...$out, 'text' => "{{ trigger.text }}\n📎 {{ node(\"file\").name }}\n{{ node(\"link\").url }}"]],

                // Read here, read there: LINE shows "Read" once someone has seen it in the room
                [0, 7, 'read', 'trigger.chat', ['room' => References::LINKED.':line', 'on' => 'read']],
                [1, 7, 'marked', 'logic.if', ['condition' => 'trigger.reply.read']],
                [2, 7, 'seen', 'line.read', ['connection' => References::LINKED, 'token' => '{{ trigger.reply.read }}'], 'continue'],

                // Someone adds the channel: their room is there before they write
                [0, 9, 'friend', 'trigger.line', ['connection' => $connection, 'on' => 'follow']],
                [1, 9, 'whonew', 'line.profile', ['connection' => $connection, 'user' => '{{ trigger.sender.id }}', 'chat' => '{{ trigger.chat.id }}'], 'continue'],
                [2, 9, 'roomnew', 'chat.room', [
                    'key' => 'line:{{ trigger.chat.id }}',
                    'title' => '{{ coalesce(node("whonew").name, "Someone") }} on LINE',
                    'link' => $connection,
                    'link_chat' => '{{ trigger.chat.id }}',
                    'picture' => '{{ coalesce(node("whonew").picture, "") }}',
                    'details' => [
                        ['name' => 'name', 'value' => '{{ coalesce(node("whonew").name, "") }}'],
                        ['name' => 'line_id', 'value' => '{{ trigger.sender.id }}'],
                        ['name' => 'language', 'value' => '{{ coalesce(node("whonew").language, "") }}'],
                        ['name' => 'status', 'value' => '{{ coalesce(node("whonew").status, "") }}'],
                    ],
                ]],
                [3, 9, 'saynew', 'chat.send', ['room' => '{{ node("roomnew").room }}', 'message' => 'Added the channel as a friend.', 'sender' => '{{ coalesce(node("whonew").name, "Someone") }} (LINE)']],
            ] : [
                [2, 3, 'backfile', 'telegram.send_file', [...$out, 'text' => '{{ trigger.text }}', 'file' => '{{ item(trigger.files, 1) }}']],
            ],
        ];

        $joins = [
            ...$line
                ? [['heard', 'out', 'who'], ['who', 'out', 'room']]
                : [['heard', 'out', 'about'], ['about', 'out', 'face'], ['face', 'out', 'room']],
            ['room', 'out', 'kind'],
            ['kind', 'file', 'save'], ['save', 'out', 'show'],
            ['kind', 'sticker', 'sticker'], ['sticker', 'out', 'showsticker'],
            ['kind', 'place', 'saywhere'],
            ...$line ? [] : [['kind', 'contact', 'saywho']],
            ['kind', NodeTypes::OTHERWISE, 'say'],
            ['written', 'out', 'out'],
            ['out', NodeTypes::OTHERWISE, 'back'],
            ['out', 'place', 'backplace'],
            ...$line ? [] : [['backplace', 'out', 'worded'], ['worded', 'true', 'backwords']],
            ...$line
                ? [
                    ['out', 'file', 'file'], ['file', 'out', 'picture'], ['picture', 'true', 'backpicture'], ['picture', 'false', 'link'], ['link', 'out', 'backlink'],
                    ['read', 'out', 'marked'], ['marked', 'true', 'seen'],
                    ['friend', 'out', 'whonew'], ['whonew', 'out', 'roomnew'], ['roomnew', 'out', 'saynew'],
                ]
                : [['out', 'file', 'backfile']],
        ];

        // On LINE each thing sent out is first tried as a reply to the last thing they wrote, which
        // LINE does not count: a step before the push, that leads to it only when the reply can't go
        $replies = $line ? ['back' => 'answer', 'backplace' => 'answerplace', 'backpicture' => 'answerpicture', 'backlink' => 'answerlink'] : [];
        $placed = [];

        foreach ($steps as $step) {
            $reply = $replies[$step[2]] ?? null;

            if ($reply === null) {
                $placed[] = $step;

                continue;
            }

            // The reply stands where the push stood, and the push one place on
            $placed[] = [$step[0], $step[1], $reply, 'line.reply', [...array_diff_key($step[4], ['to' => true]), 'token' => '{{ trigger.reply.token }}', 'within' => Steps\LineSend::REPLY_WITHIN]];
            $placed[] = [$step[0] + 1, $step[1], $step[2], $step[3], $step[4]];
            $joins = [...array_map(fn (array $join) => $join[2] === $step[2] ? [$join[0], $join[1], $reply] : $join, $joins), [$reply, NodeTypes::UNSENT, $step[2]]];
        }

        $steps = $placed;

        return [
            'nodes' => array_map(fn (array $step) => [
                'id' => $step[2], 'key' => $step[2], 'type' => $step[3],
                'position' => ['x' => $step[0] * 320, 'y' => $step[1] * 130],
                'config' => $step[4],
                ...isset($step[5]) ? ['onError' => $step[5]] : [],
            ], $steps),
            'edges' => array_map(fn (array $join) => ['id' => implode('.', $join), 'source' => $join[0], 'sourceHandle' => $join[1], 'target' => $join[2]], $joins),
        ];
    }

    /**
     * Steps one after another, left to right. A step named in $ways carries
     * on by that way out rather than its only one: ['has' => 'true'].
     *
     * @param  list<array{string, string, array<string, mixed>}>  $steps
     * @param  array<string, string>  $ways
     * @return array<string, mixed>
     */
    private static function chain(array $steps, array $ways = []): array
    {
        return self::chains([$steps], $ways);
    }

    /**
     * Several rows of steps, one under another, each one after another. A
     * step's fourth part, when given, is what it does when it fails.
     *
     * @param  list<list<array{0: string, 1: string, 2: array<string, mixed>, 3?: string}>>  $rows
     * @param  array<string, string>  $ways  the way out a step carries on by, when not its only one
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function chains(array $rows, array $ways = []): array
    {
        $nodes = [];
        $edges = [];

        foreach ($rows as $row => $steps) {
            foreach ($steps as $at => $step) {
                [$key, $type, $config] = $step;
                $nodes[] = [
                    'id' => $key, 'key' => $key, 'type' => $type,
                    'position' => ['x' => $at * 320, 'y' => $row * 220],
                    'config' => array_filter($config, fn ($value) => $value !== ''),
                    ...isset($step[3]) ? ['onError' => $step[3]] : [],
                ];

                if ($at > 0) {
                    $from = $steps[$at - 1][0];
                    $way = $ways[$from] ?? 'out';
                    $edges[] = ['id' => "{$from}.{$way}.{$key}", 'source' => $from, 'sourceHandle' => $way, 'target' => $key];
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
