<?php

namespace App\Support\Workflow\Inbound;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What came in, in one shape whichever service sent it, so a workflow reads
 * trigger.text and trigger.chat.id the same way from Telegram and from LINE:
 *
 *     { service, type, kind, id, chat: { id, kind, title }, sender: { id, name },
 *       text, display, files, reply: { token, to }, raw }
 *
 * "type" is what happened (a message, a button, someone joining); "kind"
 * is what a message was -- text, image, video, audio, file, sticker,
 * location, contact -- always there, so a Switch can go by it.
 *
 * and, only when it has them, the parts each service's own kinds of thing
 * bring: a button pressed, a place, a sticker, who joined.
 *
 * A file comes as its id only; a step fetches it ("Save to Drive"), never
 * the hook.
 */
final class Envelope
{
    /** Where LINE keeps a sticker's picture: …{sticker}/android/sticker.png, or sticker_animation.png for one that moves */
    public const LINE_STICKER = 'https://stickershop.line-scdn.net/stickershop/v1/sticker/';

    /** Where LINE keeps the pictures of its own emoji: …{product}/android/{emoji}.png */
    public const LINE_EMOJI = 'https://stickershop.line-scdn.net/sticonshop/v1/sticon/';

    /** Everything a bot is told of; reactions have to be asked for by name. */
    public const TELEGRAM_UPDATES = ['message', 'edited_message', 'channel_post', 'callback_query', 'inline_query', 'poll_answer', 'my_chat_member', 'message_reaction', 'pre_checkout_query'];

    /**
     * What a trigger's "When" takes, by the envelope's type: its choice => the types.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const KINDS = [
        'telegram' => [
            'message' => ['message', 'edited', 'button'],
            'button' => ['button'],
            'channel' => ['channel_post'],
            'member' => ['joined', 'left'],
            'reaction' => ['reaction'],
            'poll' => ['poll_answer'],
            'inline' => ['inline_query'],
            'payment' => ['checkout', 'payment'],
        ],
        'line' => [
            'message' => ['message', 'postback'],
            // A rich menu turned to another is a tap too, but nothing anyone said
            'button' => ['postback', 'menu'],
            'follow' => ['follow'],
            'unfollow' => ['unfollow'],
            'join' => ['join'],
            'leave' => ['leave'],
            'member' => ['memberJoined', 'memberLeft'],
            'unsend' => ['unsend'],
        ],
        'whatsapp' => [
            'message' => ['message', 'button'],
            'button' => ['button'],
            'reaction' => ['reaction'],
        ],
    ];

    /**
     * Whether a trigger set to start on $on takes an envelope of this type.
     * A trigger set up before it could choose starts on messages, as it did.
     */
    public static function wanted(string $service, ?string $on, string $type): bool
    {
        $on = $on ?: 'message';

        return $on === 'any' || in_array($type, self::KINDS[$service][$on] ?? [], true);
    }

    /**
     * A Telegram update, or null for a kind no trigger takes.
     *
     * Beside the common shape: button { id, data } for a press; location
     * { lat, lng, title, address }; contact { name, phone }; sticker
     * { id, emoji, animated, picture }; member { id, name, bot } for who joined or left;
     * reaction { message, emoji }; poll { id, chosen }; inline { id };
     * checkout { id, currency, amount, payload } before a payment and
     * payment { currency, amount, payload, charge } after; topic, in a group
     * that has them.
     *
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    public static function telegram(array $update): ?array
    {
        $button = $update['callback_query'] ?? null;
        $message = $update['message'] ?? $update['edited_message'] ?? $update['channel_post'] ?? ($button['message'] ?? null);

        if (! is_array($message)) {
            return self::telegramOther($update);
        }

        $from = $button['from'] ?? $message['from'] ?? [];
        $chat = $message['chat'] ?? [];
        // A photo comes in several sizes, the largest last; an animation as a document too
        $files = array_values(array_unique(array_filter([
            ...array_map(fn (array $size) => $size['file_id'] ?? null, array_slice($message['photo'] ?? [], -1)),
            $message['document']['file_id'] ?? null,
            $message['voice']['file_id'] ?? null,
            $message['audio']['file_id'] ?? null,
            $message['video']['file_id'] ?? null,
            $message['video_note']['file_id'] ?? null,
        ])));
        $place = $message['venue']['location'] ?? $message['location'] ?? null;
        // Someone coming or going is told as a message with nothing said
        $came = $message['new_chat_members'][0] ?? null;
        $went = $message['left_chat_member'] ?? null;
        $paid = $message['successful_payment'] ?? null;

        return [
            'service' => 'telegram',
            'type' => match (true) {
                $button !== null => 'button',
                isset($update['edited_message']) => 'edited',
                isset($update['channel_post']) => 'channel_post',
                $came !== null => 'joined',
                $went !== null => 'left',
                $paid !== null => 'payment',
                default => 'message',
            },
            // What was sent, whatever the service calls it
            'kind' => match (true) {
                $button !== null => 'button',
                isset($message['photo']) => 'image',
                isset($message['video']) || isset($message['video_note']) => 'video',
                isset($message['voice']) || isset($message['audio']) => 'audio',
                isset($message['sticker']) => 'sticker',
                isset($message['document']) => 'file',
                is_array($place) => 'location',
                isset($message['contact']) => 'contact',
                isset($message['text']) => 'text',
                default => 'other',
            },
            'id' => (string) ($update['update_id'] ?? ''),
            'chat' => self::telegramChat($chat),
            'sender' => self::telegramPerson($from),
            'text' => (string) ($button['data'] ?? $message['text'] ?? $message['caption'] ?? ''),
            // As LINE's envelope has it, so a bridge is written the same for both
            'display' => (string) ($button['data'] ?? $message['text'] ?? $message['caption'] ?? ''),
            'files' => $button !== null ? [] : $files,
            'reply' => ['token' => null, 'to' => $message['message_id'] ?? null],
            ...array_filter([
                'topic' => $message['message_thread_id'] ?? null,
                'button' => $button === null ? null : ['id' => (string) ($button['id'] ?? ''), 'data' => (string) ($button['data'] ?? '')],
                'location' => ! is_array($place) ? null : [
                    'lat' => $place['latitude'] ?? null,
                    'lng' => $place['longitude'] ?? null,
                    'title' => $message['venue']['title'] ?? null,
                    'address' => $message['venue']['address'] ?? null,
                ],
                'contact' => ! isset($message['contact']) ? null : [
                    'name' => trim(($message['contact']['first_name'] ?? '').' '.($message['contact']['last_name'] ?? '')),
                    'phone' => $message['contact']['phone_number'] ?? null,
                ],
                'sticker' => ! isset($message['sticker']) ? null : [
                    'id' => $message['sticker']['file_id'] ?? null,
                    'emoji' => $message['sticker']['emoji'] ?? null,
                    'animated' => (bool) (($message['sticker']['is_animated'] ?? false) || ($message['sticker']['is_video'] ?? false)),
                    // The file to show it by: a moving one that is a video plays as it is; one drawn
                    // from instructions (.tgs) nothing here can play, so its still picture stands for it
                    'picture' => ($message['sticker']['is_animated'] ?? false)
                        ? ($message['sticker']['thumbnail']['file_id'] ?? $message['sticker']['file_id'] ?? null)
                        : ($message['sticker']['file_id'] ?? null),
                ],
                'member' => ($who = $came ?? $went) === null ? null : [...self::telegramPerson($who), 'bot' => (bool) ($who['is_bot'] ?? false)],
                'payment' => $paid === null ? null : [
                    'currency' => $paid['currency'] ?? null,
                    'amount' => $paid['total_amount'] ?? null,
                    'payload' => $paid['invoice_payload'] ?? null,
                    'charge' => $paid['telegram_payment_charge_id'] ?? null,
                ],
            ], fn ($part) => $part !== null),
            'raw' => $update,
        ];
    }

    /**
     * An update that is not a message: the bot added to or put out of a
     * chat, a reaction, a poll's answer, an inline question, a checkout.
     *
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    private static function telegramOther(array $update): ?array
    {
        [$type, $from, $chat, $text, $more] = match (true) {
            isset($update['my_chat_member']) => [
                in_array($update['my_chat_member']['new_chat_member']['status'] ?? '', ['left', 'kicked'], true) ? 'left' : 'joined',
                $update['my_chat_member']['from'] ?? [],
                $update['my_chat_member']['chat'] ?? [],
                '',
                ['member' => [...self::telegramPerson($update['my_chat_member']['new_chat_member']['user'] ?? []), 'bot' => true, 'self' => true]],
            ],
            isset($update['message_reaction']) => [
                'reaction',
                $update['message_reaction']['user'] ?? [],
                $update['message_reaction']['chat'] ?? [],
                implode('', array_column($update['message_reaction']['new_reaction'] ?? [], 'emoji')),
                ['reaction' => [
                    'message' => $update['message_reaction']['message_id'] ?? null,
                    'emoji' => array_column($update['message_reaction']['new_reaction'] ?? [], 'emoji'),
                ]],
            ],
            isset($update['poll_answer']) => [
                'poll_answer',
                $update['poll_answer']['user'] ?? [],
                [],
                '',
                ['poll' => ['id' => (string) ($update['poll_answer']['poll_id'] ?? ''), 'chosen' => array_values($update['poll_answer']['option_ids'] ?? [])]],
            ],
            isset($update['inline_query']) => [
                'inline_query',
                $update['inline_query']['from'] ?? [],
                [],
                (string) ($update['inline_query']['query'] ?? ''),
                ['inline' => ['id' => (string) ($update['inline_query']['id'] ?? '')]],
            ],
            isset($update['pre_checkout_query']) => [
                'checkout',
                $update['pre_checkout_query']['from'] ?? [],
                [],
                (string) ($update['pre_checkout_query']['invoice_payload'] ?? ''),
                ['checkout' => [
                    'id' => (string) ($update['pre_checkout_query']['id'] ?? ''),
                    'currency' => $update['pre_checkout_query']['currency'] ?? null,
                    'amount' => $update['pre_checkout_query']['total_amount'] ?? null,
                    'payload' => $update['pre_checkout_query']['invoice_payload'] ?? null,
                ]],
            ],
            default => [null, [], [], '', []],
        };

        if ($type === null) {
            return null;
        }

        $sender = self::telegramPerson($from);

        return [
            'service' => 'telegram',
            'type' => $type,
            'kind' => 'other',
            'id' => (string) ($update['update_id'] ?? ''),
            // Outside any chat, it is between the bot and whoever did it
            'chat' => $chat !== [] ? self::telegramChat($chat) : ['id' => $sender['id'], 'kind' => 'private', 'title' => null],
            'sender' => $sender,
            'text' => $text,
            'display' => $text,
            'files' => [],
            'reply' => ['token' => null, 'to' => null],
            ...$more,
            'raw' => $update,
        ];
    }

    /**
     * @param  array<string, mixed>  $chat
     * @return array{id: string, kind: string, title: string|null}
     */
    private static function telegramChat(array $chat): array
    {
        return [
            'id' => (string) ($chat['id'] ?? ''),
            'kind' => ($chat['type'] ?? 'private') === 'private' ? 'private' : 'group',
            'title' => $chat['title'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @return array{id: string, name: string|null, username: string|null}
     */
    private static function telegramPerson(array $from): array
    {
        return [
            'id' => (string) ($from['id'] ?? ''),
            'name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: ($from['username'] ?? null),
            'username' => $from['username'] ?? null,
        ];
    }

    /**
     * One LINE webhook event.
     *
     * Beside the common shape: at, when LINE says it happened -- which is
     * not when it came, for what LINE sends again; reply.quote and
     * reply.read, the tokens to quote the message or mark it read by;
     * button { data } for a postback;
     * menu { alias, turned } for a rich menu turned to another -- of type
     * "menu", which a trigger set to messages does not take;
     * location { lat, lng, title, address }; sticker { id, package,
     * animated, url };
     * members, the ids of who joined or left a group; unsent, the id of a
     * message taken back.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function line(array $event): array
    {
        $source = $event['source'] ?? [];
        $message = $event['message'] ?? [];
        $kind = $message['type'] ?? 'text';
        $members = $event['joined']['members'] ?? $event['left']['members'] ?? null;
        // A rich menu turned to another, as a tab does: LINE says how it went
        $turned = is_array($event['postback']['params'] ?? null) && isset($event['postback']['params']['status']) ? $event['postback']['params'] : null;

        return [
            'service' => 'line',
            'type' => $turned !== null ? 'menu' : (string) ($event['type'] ?? 'message'),
            // What was sent: text, image, video, audio, file, sticker or location; a tap is a button
            'kind' => match (true) {
                isset($event['postback']) => 'button',
                isset($event['message']) => (string) $kind,
                default => 'other',
            },
            'id' => (string) ($event['webhookEventId'] ?? ''),
            'at' => is_numeric($event['timestamp'] ?? null) ? Carbon::createFromTimestampMs($event['timestamp'])->format('Y-m-d\TH:i:s.vP') : null,
            'chat' => [
                'id' => (string) ($source['groupId'] ?? $source['roomId'] ?? $source['userId'] ?? ''),
                'kind' => ($source['type'] ?? 'user') === 'user' ? 'private' : 'group',
                'title' => null,
            ],
            'sender' => ['id' => (string) ($source['userId'] ?? ''), 'name' => null],
            'text' => (string) ($message['text'] ?? $event['postback']['data'] ?? ''),
            // The same words for showing: LINE's own emoji as their pictures, where text has only their names
            'display' => self::lineShown((string) ($message['text'] ?? $event['postback']['data'] ?? ''), (array) ($message['emojis'] ?? [])),
            // What LINE holds to be fetched: a picture, a video, a recording, a file
            'files' => in_array($kind, ['image', 'video', 'audio', 'file'], true) && isset($message['id']) ? [(string) $message['id']] : [],
            'reply' => [
                'token' => $event['replyToken'] ?? null,
                'to' => $message['id'] ?? null,
                'quote' => $message['quoteToken'] ?? null,
                'read' => $message['markAsReadToken'] ?? null,
            ],
            ...array_filter([
                'button' => ! isset($event['postback']) ? null : ['data' => (string) ($event['postback']['data'] ?? ''), 'params' => $event['postback']['params'] ?? null],
                'menu' => $turned === null ? null : ['alias' => $turned['newRichMenuAliasId'] ?? null, 'turned' => $turned['status'] === 'SUCCESS'],
                'location' => $kind !== 'location' ? null : [
                    'lat' => $message['latitude'] ?? null,
                    'lng' => $message['longitude'] ?? null,
                    'title' => $message['title'] ?? null,
                    'address' => $message['address'] ?? null,
                ],
                'sticker' => $kind !== 'sticker' ? null : self::lineSticker($message),
                'members' => ! is_array($members) ? null : array_values(array_filter(array_column($members, 'userId'))),
                'unsent' => $event['unsend']['messageId'] ?? null,
            ], fn ($part) => $part !== null),
            'raw' => $event,
        ];
    }

    /**
     * One message sent to a WhatsApp business number, as Meta's webhook says
     * it. Beside the common shape: button { id, title } for a press;
     * location { lat, lng, title, address }; reaction { message, emoji }.
     * A group is not told of: the Cloud API speaks with one person at a time.
     *
     * @param  array<string, mixed>  $value  what the webhook carried it in: the number it came to, and who is who
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function whatsapp(array $value, array $message): array
    {
        $kind = (string) ($message['type'] ?? 'text');
        $from = (string) ($message['from'] ?? '');
        $sent = in_array($kind, ['image', 'video', 'audio', 'document', 'sticker'], true) && is_array($message[$kind] ?? null) ? $message[$kind] : [];
        // A button under a message of one's own, a row of a list, or a template's quick reply
        $pressed = $message['interactive']['button_reply'] ?? $message['interactive']['list_reply'] ?? null;
        $pressed ??= is_array($message['button'] ?? null) ? ['id' => $message['button']['payload'] ?? null, 'title' => $message['button']['text'] ?? null] : null;
        $who = collect((array) ($value['contacts'] ?? []))->firstWhere('wa_id', $from);
        $text = (string) ($message['text']['body'] ?? $sent['caption'] ?? $pressed['id'] ?? $message['reaction']['emoji'] ?? '');

        return [
            'service' => 'whatsapp',
            'type' => match (true) {
                $pressed !== null => 'button',
                $kind === 'reaction' => 'reaction',
                default => 'message',
            },
            // What was sent: text, image, video, audio, file, sticker, location or contacts; a press is a button
            'kind' => $pressed !== null ? 'button' : ($kind === 'document' ? 'file' : $kind),
            'id' => (string) ($message['id'] ?? ''),
            'at' => is_numeric($message['timestamp'] ?? null) ? Carbon::createFromTimestamp((int) $message['timestamp'])->format('Y-m-d\TH:i:s.vP') : null,
            'chat' => ['id' => $from, 'kind' => 'private', 'title' => null],
            'sender' => ['id' => $from, 'name' => is_array($who) ? ($who['profile']['name'] ?? null) : null],
            'text' => $text,
            'display' => $text,
            // What WhatsApp holds to be fetched, by its id
            'files' => isset($sent['id']) ? [(string) $sent['id']] : [],
            'reply' => ['to' => $message['id'] ?? null, 'quote' => $message['context']['id'] ?? null],
            ...array_filter([
                'button' => $pressed === null ? null : ['id' => (string) ($pressed['id'] ?? ''), 'title' => (string) ($pressed['title'] ?? '')],
                'location' => $kind !== 'location' ? null : [
                    'lat' => $message['location']['latitude'] ?? null,
                    'lng' => $message['location']['longitude'] ?? null,
                    'title' => $message['location']['name'] ?? null,
                    'address' => $message['location']['address'] ?? null,
                ],
                'reaction' => $kind !== 'reaction' ? null : ['message' => $message['reaction']['message_id'] ?? null, 'emoji' => $message['reaction']['emoji'] ?? null],
            ], fn ($part) => $part !== null),
            'raw' => ['message' => $message, 'metadata' => $value['metadata'] ?? null],
        ];
    }

    /**
     * A LINE sticker: which it is, whether it moves, and the address of its
     * picture -- for one that moves, the moving picture (an animated PNG,
     * which a browser plays as it is).
     *
     * @param  array<string, mixed>  $message
     * @return array{id: string, package: string, animated: bool, url: string}
     */
    private static function lineSticker(array $message): array
    {
        $id = preg_replace('/\\D/', '', (string) ($message['stickerId'] ?? '')) ?? '';
        $animated = in_array($message['stickerResourceType'] ?? 'STATIC', ['ANIMATION', 'ANIMATION_SOUND'], true);

        return [
            'id' => $id,
            'package' => (string) ($message['packageId'] ?? ''),
            'animated' => $animated,
            'url' => self::LINE_STICKER."{$id}/android/".($animated ? 'sticker_animation.png' : 'sticker.png'),
        ];
    }

    /**
     * A LINE message's words with each of LINE's own emoji written as a
     * picture -- ![(its name)](its address) -- for a chat here to draw. LINE
     * says where each is by counting as JavaScript does, in UTF-16 units.
     *
     * @param  array<int, mixed>  $emojis
     */
    private static function lineShown(string $text, array $emojis): string
    {
        $emojis = array_values(array_filter($emojis, fn ($emoji) => is_array($emoji)
            && preg_match('/^[A-Za-z0-9]+$/', (string) ($emoji['productId'] ?? '')) === 1
            && preg_match('/^[A-Za-z0-9]+$/', (string) ($emoji['emojiId'] ?? '')) === 1));

        if ($emojis === []) {
            return $text;
        }

        usort($emojis, fn (array $a, array $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        $units = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $part = fn (int $from, ?int $length = null) => mb_convert_encoding(substr($units, $from * 2, $length === null ? null : $length * 2), 'UTF-8', 'UTF-16LE');
        $shown = '';
        $at = 0;

        foreach ($emojis as $emoji) {
            $index = (int) ($emoji['index'] ?? -1);
            $length = (int) ($emoji['length'] ?? 0);

            if ($index < $at || $length < 1) {
                continue;
            }

            $shown .= $part($at, $index - $at)
                .'!['.str_replace(['[', ']'], '', $part($index, $length)).']('.self::LINE_EMOJI."{$emoji['productId']}/android/{$emoji['emojiId']}.png)";
            $at = $index + $length;
        }

        return $shown.$part($at);
    }

    /**
     * A request to a webhook: its body read as JSON or a form when it is
     * one, else as text; and its headers, but never cookies or credentials.
     *
     * @return array<string, mixed>
     */
    public static function webhook(Request $request): array
    {
        $raw = $request->getContent();
        $json = json_decode($raw, true);

        $headers = collect($request->headers->all())
            ->except(['cookie', 'authorization', 'proxy-authorization'])
            ->map(fn (array $values) => count($values) === 1 ? $values[0] : $values)
            ->all();

        return [
            'service' => 'webhook',
            'type' => 'request',
            'method' => $request->method(),
            'body' => is_array($json) ? $json : ($request->request->all() ?: $raw),
            'query' => $request->query->all(),
            'headers' => $headers,
        ];
    }
}
