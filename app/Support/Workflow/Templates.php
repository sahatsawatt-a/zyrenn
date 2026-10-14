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
        $whatsapp = $chosen['whatsapp'] ?? '';
        $instagram = $chosen['instagram'] ?? '';
        $channel = $chosen['channel'] ?? '';
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
            'whatsapp-room' => [
                'title' => 'WhatsApp chats in a room, both ways',
                'description' => 'Each person who writes to your business number gets a chat of their own here: what they send is written in it, and what you write in it goes back on WhatsApp -- pictures, files and places too, kept in the Drive on the way, and their message is marked read once someone has seen it here. WhatsApp takes an answer only within 24 hours of their last message.',
                'needs' => ['whatsapp' => 'Number'],
                'definition' => self::desk('whatsapp', $whatsapp),
            ],
            'instagram-room' => [
                'title' => 'Instagram messages in a room, both ways',
                'description' => 'Each person who messages your Instagram account gets a chat of their own here, under their name and picture: what they send is written in it, and what you write in it goes back on Instagram, pictures too. Instagram takes an answer only within 24 hours of their last message.',
                'needs' => ['instagram' => 'Account'],
                'definition' => self::desk('instagram', $instagram),
            ],
            'channel-room' => [
                'title' => 'Custom channel chats in a room, both ways',
                'description' => 'Each chat a platform of your own sends to its hook address gets a chat of its own here, under the sender\'s name and picture: what they send is written in it, files and places too, and what you write in it is posted back to the platform\'s "Send to" address.',
                'needs' => ['channel' => 'Custom channel'],
                'definition' => self::custom($channel),
            ],
            'ai-desk' => [
                'title' => 'AI answers customers, people take requests',
                'description' => 'In a project: what a customer writes in any of its chats is put to an agent -- make one from "Customer desk" on the Agents page, with a knowledge base. A question it can answer from what it knows is answered, and goes back through the chat\'s own bridge. A bug or a feature they ask for is thanked for, tagged, and noted for someone to make a ticket of; anything else is left to a person with a note. AI keeps out of a chat someone has taken, or where "AI" is off. Choose the agent after.',
                'needs' => [],
                'definition' => self::aiDesk(),
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
            [0, 4, 'written', 'trigger.chat', ['room' => References::LINKED.":{$service}", 'include' => 'answers']],
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

        return self::laid($placed, $joins);
    }

    /**
     * A WhatsApp number's or an Instagram account's chats, each with a room
     * of its own here, both ways. Either speaks with one person at a time,
     * and tells an app less than LINE or Telegram do, so there is less to
     * carry: words, a file, and -- on WhatsApp -- a sticker and a place.
     *
     * Coming in, a file is kept in a Drive folder named for the service:
     * WhatsApp's by its own "get the file" step, Instagram's from the
     * address Instagram gives. Who it is with is kept with the room: on
     * Instagram their name and picture are asked for; WhatsApp sends a name
     * with each message, and their number is the chat.
     *
     * Going out, what is written in the room goes to them, a file as a file
     * and a place as a place -- on Instagram, which has none, as a link to
     * a map. A WhatsApp message is marked read there once someone has seen
     * it here.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function desk(string $service, string $connection): array
    {
        $whatsapp = $service === 'whatsapp';
        $named = $whatsapp ? 'WhatsApp' : 'Instagram';
        $who = $whatsapp
            ? '{{ coalesce(trigger.sender.name, trigger.chat.id) }}'
            : '{{ coalesce(node("who").name, node("who").username, "Someone") }}';
        $room = ['room' => '{{ node("room").room }}', 'sender' => "{$who} ({$named})"];
        $out = ['connection' => References::LINKED, 'to' => '{{ trigger.room.link.chat }}'];
        // Where the question of its kind stands: Instagram asks who wrote first
        $at = $whatsapp ? 2 : 3;

        /** @var list<array{0: int, 1: int, 2: string, 3: string, 4: array<string, mixed>, 5?: string}> $steps */
        $steps = [
            // In
            [0, 0, 'heard', $whatsapp ? 'trigger.whatsapp' : 'trigger.instagram_message', ['connection' => $connection]],
            ...$whatsapp ? [] : [[1, 0, 'who', 'instagram.profile', ['connection' => $connection, 'user' => '{{ trigger.sender.id }}'], 'continue']],
            [$at - 1, 0, 'room', 'chat.room', [
                'key' => "{$service}:{{ trigger.chat.id }}",
                'title' => "{$who} on {$named}",
                'link' => $connection,
                'link_chat' => '{{ trigger.chat.id }}',
                ...$whatsapp ? [
                    'details' => [
                        ['name' => 'name', 'value' => '{{ coalesce(trigger.sender.name, "") }}'],
                        ['name' => 'phone', 'value' => '{{ trigger.chat.id }}'],
                    ],
                ] : [
                    'picture' => '{{ coalesce(node("who").picture, "") }}',
                    'details' => [
                        ['name' => 'name', 'value' => '{{ coalesce(node("who").name, "") }}'],
                        ['name' => 'username', 'value' => '{{ coalesce(node("who").username, "") }}'],
                        ['name' => 'instagram_id', 'value' => '{{ trigger.sender.id }}'],
                    ],
                ],
            ]],
            [$at, 0, 'kind', 'logic.switch', ['ways' => [
                ...$whatsapp ? [['name' => 'sticker', 'value' => 'trigger.kind = "sticker"']] : [],
                ['name' => 'file', 'value' => 'len(trigger.files) > 0'],
                ...$whatsapp ? [['name' => 'place', 'value' => 'trigger.kind = "location"']] : [],
            ]]],
            $whatsapp
                ? [$at + 1, -2, 'save', 'whatsapp.file', ['connection' => $connection, 'folder' => $named]]
                : [$at + 1, -2, 'save', 'drive.save', ['from' => 'url', 'url' => '{{ item(trigger.files, 1) }}', 'folder' => $named]],
            [$at + 2, -2, 'show', 'chat.send', [...$room, 'message' => '{{ trigger.display }}', 'file' => '{{ node("save").ref_id }}']],
            ...$whatsapp ? [
                [$at + 1, -1, 'sticker', 'whatsapp.file', ['connection' => $connection, 'folder' => 'WhatsApp/Stickers'], 'continue'],
                [$at + 2, -1, 'showsticker', 'chat.send', [...$room, 'message' => '{{ if(node("sticker").ref_id, "", "Sticker") }}', 'file' => '{{ node("sticker").ref_id }}', 'shown' => 'sticker']],
                [$at + 1, 0, 'saywhere', 'chat.send', [...$room, 'message' => '{{ trigger.display }}', 'place' => '{{ trigger.location }}']],
            ] : [],
            [$at + 1, 1, 'say', 'chat.send', [...$room, 'message' => '{{ trigger.display }}']],

            // Out
            [0, 4, 'written', 'trigger.chat', ['room' => References::LINKED.":{$service}", 'include' => 'answers']],
            [1, 4, 'out', 'logic.switch', ['ways' => [
                ['name' => 'file', 'value' => 'len(trigger.files) > 0'],
                ['name' => 'place', 'value' => 'trigger.kind = "location"'],
            ]]],
            [2, 3, 'backfile', "{$service}.send_file", [...$out, 'file' => '{{ item(trigger.files, 1) }}', ...$whatsapp ? ['text' => '{{ trigger.text }}'] : []]],
            [2, 5, 'back', "{$service}.send", [...$out, 'text' => '{{ trigger.text }}']],
            $whatsapp
                ? [2, 6, 'backplace', 'whatsapp.location', [...$out, 'lat' => '{{ trigger.location.lat }}', 'lng' => '{{ trigger.location.lng }}', 'name' => '{{ coalesce(trigger.location.title, "") }}', 'address' => '{{ coalesce(trigger.location.address, "") }}']]
                // Instagram sends no place: a link that opens it on a map goes instead, with what was written
                : [2, 6, 'backplace', 'instagram.send', [...$out, 'text' => "{{ trigger.text }}\n📍 {{ coalesce(trigger.location.title, \"A place\") }}\nhttps://www.google.com/maps?q={{ trigger.location.lat }},{{ trigger.location.lng }}"]],
            // Words written with a WhatsApp place, or with an Instagram file, follow it: neither carries any
            [3, $whatsapp ? 6 : 3, 'worded', 'logic.if', ['condition' => 'trigger.text']],
            [4, $whatsapp ? 6 : 3, 'backwords', "{$service}.send", [...$out, 'text' => '{{ trigger.text }}']],

            ...$whatsapp ? [
                // Read here, read there: the two ticks turn blue once someone has seen it in the room
                [0, 8, 'read', 'trigger.chat', ['room' => References::LINKED.':whatsapp', 'on' => 'read']],
                [1, 8, 'marked', 'logic.if', ['condition' => 'trigger.reply.to']],
                [2, 8, 'seen', 'whatsapp.read', ['connection' => References::LINKED, 'message' => '{{ trigger.reply.to }}'], 'continue'],
            ] : [],
        ];

        $joins = [
            ...$whatsapp ? [['heard', 'out', 'room']] : [['heard', 'out', 'who'], ['who', 'out', 'room']],
            ['room', 'out', 'kind'],
            ['kind', 'file', 'save'], ['save', 'out', 'show'],
            ...$whatsapp ? [['kind', 'sticker', 'sticker'], ['sticker', 'out', 'showsticker'], ['kind', 'place', 'saywhere']] : [],
            ['kind', NodeTypes::OTHERWISE, 'say'],
            ['written', 'out', 'out'],
            ['out', 'file', 'backfile'],
            ['out', 'place', 'backplace'],
            ['out', NodeTypes::OTHERWISE, 'back'],
            [$whatsapp ? 'backplace' : 'backfile', 'out', 'worded'], ['worded', 'true', 'backwords'],
            ...$whatsapp ? [['read', 'out', 'marked'], ['marked', 'true', 'seen']] : [],
        ];

        return self::laid($steps, $joins);
    }

    /**
     * A platform of the user's own and a room for each of its chats, both
     * ways. Coming in, a file is kept in the Drive from the address the
     * platform gives, and a place is shown on a map; going out, words, a
     * file and a place go in the one call the channel takes.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function custom(string $connection): array
    {
        $room = ['room' => '{{ node("room").room }}', 'sender' => '{{ coalesce(trigger.sender.name, "Someone") }} ({{ trigger.platform }})'];

        return self::laid([
            // In
            [0, 0, 'heard', 'trigger.channel', ['connection' => $connection]],
            [1, 0, 'room', 'chat.room', [
                // Two channels may each have a chat "1": the channel is part of what it stands for
                'key' => "channel:{$connection}:{{ trigger.chat.id }}",
                'title' => '{{ coalesce(trigger.sender.name, trigger.chat.title, trigger.chat.id) }} on {{ trigger.platform }}',
                'link' => $connection,
                'link_chat' => '{{ trigger.chat.id }}',
                'picture' => '{{ coalesce(trigger.sender.picture, "") }}',
                'details' => [
                    ['name' => 'name', 'value' => '{{ coalesce(trigger.sender.name, "") }}'],
                    ['name' => 'channel_id', 'value' => '{{ trigger.sender.id }}'],
                ],
            ]],
            [2, 0, 'kind', 'logic.switch', ['ways' => [
                ['name' => 'file', 'value' => 'len(trigger.files) > 0'],
                ['name' => 'place', 'value' => 'trigger.kind = "location"'],
            ]]],
            [3, -1, 'save', 'drive.save', ['from' => 'url', 'url' => '{{ item(trigger.files, 1) }}', 'folder' => 'Channels']],
            [4, -1, 'show', 'chat.send', [...$room, 'message' => '{{ trigger.display }}', 'file' => '{{ node("save").ref_id }}']],
            [3, 0, 'saywhere', 'chat.send', [...$room, 'message' => '{{ trigger.display }}', 'place' => '{{ trigger.location }}']],
            [3, 1, 'say', 'chat.send', [...$room, 'message' => '{{ trigger.display }}']],

            // Out: one call carries all of it
            [0, 3, 'written', 'trigger.chat', ['room' => References::LINKED.':channel', 'include' => 'answers']],
            [1, 3, 'back', 'channel.send', [
                'connection' => References::LINKED,
                'to' => '{{ trigger.room.link.chat }}',
                'text' => '{{ trigger.text }}',
                'file' => '{{ item(trigger.files, 1) }}',
                'place' => '{{ trigger.location }}',
            ]],
        ], [
            ['heard', 'out', 'room'],
            ['room', 'out', 'kind'],
            ['kind', 'file', 'save'], ['save', 'out', 'show'],
            ['kind', 'place', 'saywhere'],
            ['kind', NodeTypes::OTHERWISE, 'say'],
            ['written', 'out', 'back'],
        ]);
    }

    /**
     * A project's AI desk: what customers write, answered from a knowledge
     * base -- or, for a request or what it can't answer, left to a person.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function aiDesk(): array
    {
        $room = '{{ trigger.room.ref }}';
        $asked = 'node("ask").json';

        return self::laid([
            [0, 0, 'heard', 'trigger.chat', ['room' => References::CUSTOMERS, 'include' => 'outside']],
            // Not where someone has taken the chat, or AI is switched off
            [1, 0, 'on', 'logic.if', ['condition' => 'trigger.room.ai']],
            // The agent reads its knowledge and the chat so far for itself
            [2, 0, 'ask', 'ai.agent', ['agent' => '', 'message' => '{{ trigger.text }}', 'room' => $room, 'answer' => 'json']],
            [3, 0, 'way', 'logic.switch', ['ways' => [
                ['name' => 'request', 'value' => $asked.'.kind = "bug" or '.$asked.'.kind = "feature"'],
                ['name' => 'answer', 'value' => "{$asked}.answered and {$asked}.reply"],
            ]]],
            // Answered: written in the chat, and sent on by its bridge
            [4, -1, 'say', 'chat.send', ['room' => $room, 'message' => "{{ {$asked}.reply }}", 'sources' => '{{ node("ask").sources }}', 'note' => 'message']],
            // A request: thanked for, tagged, and noted for someone to make a ticket of
            [4, 0, 'thank', 'chat.send', ['room' => $room, 'message' => "{{ {$asked}.reply }}", 'sources' => '{{ node("ask").sources }}', 'note' => 'message']],
            [5, 0, 'flag', 'chat.update', ['room' => $room, 'status' => 'open', 'assign' => 'keep', 'tag' => "{{ {$asked}.kind }}"]],
            [6, 0, 'tell', 'chat.send', ['room' => $room, 'message' => "Looks like a {{ {$asked}.kind }} request: {{ {$asked}.summary }}\nMake a ticket of their message if it is one.", 'note' => 'note']],
            // Anything else: a person answers
            [4, 1, 'leave', 'chat.send', ['room' => $room, 'message' => "AI left this for a person: {{ coalesce({$asked}.summary, trigger.text) }}", 'note' => 'note']],
        ], [
            ['heard', 'out', 'on'],
            ['on', 'true', 'ask'],
            ['ask', 'out', 'way'],
            ['way', 'answer', 'say'],
            ['way', 'request', 'thank'], ['thank', 'out', 'flag'], ['flag', 'out', 'tell'],
            ['way', NodeTypes::OTHERWISE, 'leave'],
        ]);
    }

    /**
     * Steps placed on a grid, and the ways between them, as a definition.
     *
     * @param  list<array{0: int, 1: int, 2: string, 3: string, 4: array<string, mixed>, 5?: string}>  $steps  column, row, key, kind, settings, and what a failure does when not stopping
     * @param  list<array{0: string, 1: string, 2: string}>  $joins  from, by which way out, to
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function laid(array $steps, array $joins): array
    {
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
