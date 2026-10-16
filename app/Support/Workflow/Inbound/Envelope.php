<?php

namespace App\Support\Workflow\Inbound;

use App\Models\Chat\ChatMessage;
use App\Support\Workflow\Steps\StripeCall;
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

    /** What a Stripe trigger may wait for, and the event types each is. */
    public const STRIPE_KINDS = [
        'checkout' => ['checkout.session.completed', 'checkout.session.async_payment_succeeded'],
        'payment' => ['payment_intent.succeeded'],
        'failed' => ['payment_intent.payment_failed', 'checkout.session.async_payment_failed', 'invoice.payment_failed'],
        'invoice' => ['invoice.paid'],
        'refund' => ['charge.refunded'],
        'subscription' => ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'],
        'dispute' => ['charge.dispute.created'],
    ];

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
        'instagram' => [
            'comment' => ['comment'],
            'mention' => ['mention'],
        ],
        'instagram_message' => [
            'message' => ['message', 'button'],
            'button' => ['button'],
            'reaction' => ['reaction'],
            'unsend' => ['unsend'],
        ],
        'threads' => [
            'reply' => ['reply'],
            'publish' => ['publish'],
            'delete' => ['delete'],
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
     * Something that happened on an Instagram professional account, as
     * Meta's webhook says it: a comment under one of its posts ("comments"),
     * or the account named in someone else's caption or comment
     * ("mentions"). Null for a field no trigger takes.
     *
     * Its "chat" is the post, so what comes quickly under one post can be
     * kept in order. Beside the common shape: post { id, kind } and comment
     * { id, parent } -- parent for an answer under another comment.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|null
     */
    public static function instagram(string $field, array $value, mixed $time = null): ?array
    {
        if (! in_array($field, ['comments', 'mentions'], true)) {
            return null;
        }

        $mention = $field === 'mentions';
        $post = (string) ($mention ? ($value['media_id'] ?? '') : ($value['media']['id'] ?? ''));
        $comment = (string) ($mention ? ($value['comment_id'] ?? '') : ($value['id'] ?? ''));
        $parent = isset($value['parent_id']) ? (string) $value['parent_id'] : null;
        $text = (string) ($value['text'] ?? '');

        return [
            'service' => 'instagram',
            'type' => $mention ? 'mention' : 'comment',
            // Where it was written: a comment, an answer under one, or a caption
            'kind' => match (true) {
                $comment === '' => 'caption',
                $parent !== null => 'reply',
                default => 'comment',
            },
            'id' => $comment !== '' ? $comment : $post,
            'at' => is_numeric($time) ? Carbon::createFromTimestamp((int) $time)->format('Y-m-d\TH:i:s.vP') : null,
            'chat' => ['id' => $post, 'kind' => 'post', 'title' => null],
            'sender' => ['id' => isset($value['from']['id']) ? (string) $value['from']['id'] : null, 'name' => $value['from']['username'] ?? null],
            'text' => $text,
            'display' => $text,
            'files' => [],
            'reply' => ['to' => $comment !== '' ? $comment : null, 'quote' => $parent],
            'post' => ['id' => $post, 'kind' => $value['media']['media_product_type'] ?? null],
            'comment' => ['id' => $comment !== '' ? $comment : null, 'parent' => $parent],
            'raw' => $value,
        ];
    }

    /**
     * Something said to an Instagram professional account in its messages,
     * as Meta's webhook says it: a message, a quick answer or a button
     * tapped, a heart put on a message or taken off, a message taken back.
     * Null for what no trigger takes -- that a message was seen, and what
     * the account sent itself, which Instagram tells of too.
     *
     * Its "chat" is the person, by the id Instagram gives this account for
     * them: there are no groups. A message carries no name ("Instagram: who
     * wrote" asks), and a picture or recording comes as the address
     * Instagram keeps it at, where another service gives an id. Beside the
     * common shape: button { id, title } for a tap; reaction { message,
     * emoji }; story { id, url } when it answers or names a story.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    public static function instagramMessage(array $event): ?array
    {
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $reaction = is_array($event['reaction'] ?? null) ? $event['reaction'] : null;
        $from = (string) ($event['sender']['id'] ?? '');
        // A quick answer under a message of one's own, or a button of a card
        $pressed = match (true) {
            is_array($message['quick_reply'] ?? null) => ['id' => (string) ($message['quick_reply']['payload'] ?? ''), 'title' => (string) ($message['text'] ?? '')],
            is_array($event['postback'] ?? null) => ['id' => (string) ($event['postback']['payload'] ?? ''), 'title' => (string) ($event['postback']['title'] ?? '')],
            default => null,
        };
        $id = (string) ($message['mid'] ?? $event['postback']['mid'] ?? $reaction['mid'] ?? '');

        if ($from === '' || ($message['is_echo'] ?? false) === true || ($message === [] && $pressed === null && $reaction === null)) {
            return null;
        }

        $sent = array_values(array_filter((array) ($message['attachments'] ?? []), is_array(...)));
        $first = strtolower((string) ($sent[0]['type'] ?? ''));
        // The story it answers, or the one of theirs that names the account
        $story = match (true) {
            is_array($message['reply_to']['story'] ?? null) => $message['reply_to']['story'],
            $first === 'story_mention' && is_array($sent[0]['payload'] ?? null) => $sent[0]['payload'],
            default => null,
        };
        $text = (string) ($pressed['id'] ?? $message['text'] ?? $reaction['emoji'] ?? '');

        return [
            'service' => 'instagram',
            'type' => match (true) {
                $pressed !== null => 'button',
                $reaction !== null => 'reaction',
                ($message['is_deleted'] ?? false) === true => 'unsend',
                default => 'message',
            },
            // What was sent: text, image, video, audio, file, share (a post passed on), reel, story_mention; a tap is a button
            'kind' => match (true) {
                $pressed !== null => 'button',
                $reaction !== null => 'reaction',
                $first !== '' => $first === 'ig_reel' ? 'reel' : $first,
                isset($message['text']) => 'text',
                default => 'other',
            },
            'id' => $id,
            'at' => is_numeric($event['timestamp'] ?? null) ? Carbon::createFromTimestampMs($event['timestamp'])->format('Y-m-d\TH:i:s.vP') : null,
            'chat' => ['id' => $from, 'kind' => 'private', 'title' => null],
            'sender' => ['id' => $from, 'name' => null],
            'text' => $text,
            'display' => $pressed !== null ? $pressed['title'] : $text,
            // Where Instagram keeps what was sent, to be fetched from there
            'files' => array_values(array_filter(array_map(
                fn (array $one) => in_array(strtolower((string) ($one['type'] ?? '')), ['image', 'video', 'audio', 'file'], true) ? ($one['payload']['url'] ?? null) : null,
                $sent,
            ))),
            'reply' => ['to' => $id !== '' ? $id : null, 'quote' => $message['reply_to']['mid'] ?? null],
            ...array_filter([
                'button' => $pressed,
                'reaction' => $reaction === null ? null : ['message' => $reaction['mid'] ?? null, 'emoji' => ($reaction['action'] ?? 'react') === 'unreact' ? null : ($reaction['emoji'] ?? $reaction['reaction'] ?? null)],
                'story' => $story === null ? null : ['id' => isset($story['id']) ? (string) $story['id'] : null, 'url' => $story['url'] ?? null],
            ], fn ($part) => $part !== null),
            'raw' => $event,
        ];
    }

    /**
     * One message from a platform of the user's own (a "Custom channel"),
     * in the app's own shape: id, chat { id, title } (or the chat's id
     * alone), sender { id, name, picture }, text, files (addresses, or
     * { url, name }), location { lat, lng, title, address }. Null for one
     * with no chat, or with nothing said. Beside the common shape: platform,
     * the name the connection gives it.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public static function channel(array $message, string $platform): ?array
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : ['id' => $message['chat'] ?? null];
        $chatId = is_scalar($chat['id'] ?? null) ? trim((string) $chat['id']) : '';
        $sender = is_array($message['sender'] ?? null) ? $message['sender'] : [];
        $text = is_scalar($message['text'] ?? null) ? (string) $message['text'] : '';
        $files = array_values(array_filter(array_map(
            fn ($file) => match (true) {
                is_string($file) => $file,
                is_array($file) && is_string($file['url'] ?? null) => $file['url'],
                default => null,
            },
            is_array($message['files'] ?? null) ? $message['files'] : [],
        ), fn (?string $url) => $url !== null && preg_match('#^https?://#i', $url) === 1));
        $location = ChatMessage::placeOf($message['location'] ?? null);

        if ($chatId === '' || ($text === '' && $files === [] && $location === null)) {
            return null;
        }

        $id = is_scalar($message['id'] ?? null) ? (string) $message['id'] : '';
        $word = fn (mixed $value) => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;

        return [
            'service' => 'channel',
            'platform' => $platform,
            'type' => 'message',
            'kind' => match (true) {
                $location !== null => 'location',
                $files !== [] => 'file',
                default => 'text',
            },
            'id' => $id,
            'at' => is_string($message['at'] ?? null) ? $message['at'] : now()->format('Y-m-d\TH:i:s.vP'),
            'chat' => ['id' => $chatId, 'kind' => 'private', 'title' => $word($chat['title'] ?? null)],
            'sender' => [
                'id' => $word($sender['id'] ?? null) ?? $chatId,
                'name' => $word($sender['name'] ?? null),
                'picture' => is_string($sender['picture'] ?? null) && str_starts_with($sender['picture'], 'https://') ? $sender['picture'] : null,
            ],
            'text' => $text,
            'display' => $text,
            'files' => $files,
            'location' => $location,
            'reply' => ['to' => $id !== '' ? $id : null, 'quote' => null],
            'raw' => $message,
        ];
    }

    /**
     * Something that happened on a Threads profile, as Meta's webhook says
     * it: a reply under one of its posts ("replies"), a post of its own
     * published ("publish") or deleted ("delete"). Null for a field no
     * trigger takes.
     *
     * Its "chat" is the post the thread began with. Beside the common shape:
     * post { id, owner } for that post, and url, where what was written is.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function threads(array $payload): ?array
    {
        $type = ['replies' => 'reply', 'publish' => 'publish', 'delete' => 'delete'][(string) ($payload['values']['field'] ?? '')] ?? null;
        $value = $payload['values']['value'] ?? null;

        if ($type === null || ! is_array($value)) {
            return null;
        }

        $id = (string) ($value['id'] ?? '');
        $at = $value['timestamp'] ?? $payload['time'] ?? null;
        $text = (string) ($value['text'] ?? '');

        return [
            'service' => 'threads',
            'type' => $type,
            // What was posted: text_post, image, video, carousel_album ...
            'kind' => strtolower((string) ($value['media_type'] ?? '')) ?: 'other',
            'id' => $id,
            'at' => match (true) {
                is_numeric($at) => Carbon::createFromTimestamp((int) $at)->format('Y-m-d\TH:i:s.vP'),
                is_string($at) && strtotime($at) !== false => Carbon::parse($at)->format('Y-m-d\TH:i:s.vP'),
                default => null,
            },
            'chat' => ['id' => (string) ($value['root_post']['id'] ?? $id), 'kind' => 'post', 'title' => null],
            'sender' => ['id' => isset($value['owner']['owner_id']) ? (string) $value['owner']['owner_id'] : null, 'name' => $value['username'] ?? null],
            'text' => $text,
            'display' => $text,
            'files' => [],
            'reply' => ['to' => $id !== '' ? $id : null, 'quote' => isset($value['replied_to']['id']) ? (string) $value['replied_to']['id'] : null],
            'post' => ['id' => (string) ($value['root_post']['id'] ?? $id), 'owner' => $value['root_post']['username'] ?? null],
            'url' => $value['permalink'] ?? null,
            'raw' => $value,
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
     * Something that happened in a GitHub repository, as its webhook tells
     * it: the event (issues, pull_request, push, ...) and its action
     * (opened, closed, ...), the repository and who did it, a line saying
     * what it was -- and, by the event, the issue, pull request, comment,
     * push, release or Actions run it was about. The whole of what GitHub
     * sent is in body, unless it is too big to keep.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function github(string $event, array $payload, string $delivery): array
    {
        $at = fn (string $path) => data_get($payload, $path);
        $repo = (string) ($at('repository.full_name') ?? '');
        $action = is_string($at('action')) ? $at('action') : null;
        $issue = is_array($at('issue')) ? $at('issue') : null;
        $pull = is_array($at('pull_request')) ? $at('pull_request') : null;
        $labels = fn (mixed $of) => array_values(array_filter(array_map(fn ($label) => is_array($label) ? ($label['name'] ?? null) : null, (array) $of), is_string(...)));

        $about = array_filter([
            'issue' => $issue === null ? null : [
                'number' => $issue['number'] ?? null,
                'title' => $issue['title'] ?? null,
                'body' => $issue['body'] ?? '',
                'state' => $issue['state'] ?? null,
                'labels' => $labels($issue['labels'] ?? []),
                'author' => $issue['user']['login'] ?? null,
                'pull' => isset($issue['pull_request']),
                'url' => $issue['html_url'] ?? null,
            ],
            'pull' => $pull === null ? null : [
                'number' => $pull['number'] ?? null,
                'title' => $pull['title'] ?? null,
                'body' => $pull['body'] ?? '',
                'state' => $pull['state'] ?? null,
                'draft' => (bool) ($pull['draft'] ?? false),
                'merged' => (bool) ($pull['merged'] ?? false),
                'head' => $pull['head']['ref'] ?? null,
                'base' => $pull['base']['ref'] ?? null,
                'author' => $pull['user']['login'] ?? null,
                'url' => $pull['html_url'] ?? null,
            ],
            'comment' => is_array($at('comment')) ? [
                'id' => $at('comment.id'),
                'body' => $at('comment.body') ?? '',
                'author' => $at('comment.user.login'),
                'url' => $at('comment.html_url'),
            ] : null,
            'push' => $event === 'push' ? [
                'ref' => $at('ref'),
                'branch' => is_string($at('ref')) && str_starts_with($at('ref'), 'refs/heads/') ? substr($at('ref'), 11) : null,
                'tag' => is_string($at('ref')) && str_starts_with($at('ref'), 'refs/tags/') ? substr($at('ref'), 10) : null,
                'before' => $at('before'),
                'after' => $at('after'),
                'commits' => array_map(fn ($commit) => [
                    'sha' => $commit['id'] ?? null,
                    'message' => $commit['message'] ?? null,
                    'author' => $commit['author']['username'] ?? $commit['author']['name'] ?? null,
                    'url' => $commit['url'] ?? null,
                ], array_values(array_filter((array) $at('commits'), is_array(...)))),
                'compare' => $at('compare'),
            ] : null,
            'release' => is_array($at('release')) ? [
                'tag' => $at('release.tag_name'),
                'name' => $at('release.name'),
                'body' => $at('release.body') ?? '',
                'prerelease' => (bool) $at('release.prerelease'),
                'url' => $at('release.html_url'),
            ] : null,
            'run' => is_array($at('workflow_run')) ? [
                'id' => $at('workflow_run.id'),
                'name' => $at('workflow_run.name'),
                'status' => $at('workflow_run.status'),
                'conclusion' => $at('workflow_run.conclusion'),
                'branch' => $at('workflow_run.head_branch'),
                'url' => $at('workflow_run.html_url'),
            ] : null,
        ], fn ($part) => $part !== null);

        $who = (string) ($at('sender.login') ?? 'Someone');
        $subject = match (true) {
            isset($about['comment'], $issue) => "a comment on #{$issue['number']} {$issue['title']}",
            isset($about['pull']) => "pull request #{$about['pull']['number']} {$about['pull']['title']}",
            isset($about['issue']) => "issue #{$about['issue']['number']} {$about['issue']['title']}",
            isset($about['release']) => "release {$about['release']['tag']}",
            isset($about['run']) => "the run of {$about['run']['name']}",
            default => null,
        };
        $text = match (true) {
            isset($about['push']) => "{$who} pushed ".count($about['push']['commits']).' commit'.(count($about['push']['commits']) === 1 ? '' : 's').' to '.($about['push']['branch'] ?? $about['push']['tag'] ?? $at('ref')),
            $subject !== null => trim("{$who} ".($action ?? $event)." {$subject}"),
            default => trim("{$who} {$event} ".($action ?? '')),
        }.($repo === '' ? '' : " in {$repo}");

        // What GitHub sent, whole, while it is small enough to keep with the run
        $whole = json_encode($payload) ?: '';

        return [
            'service' => 'github',
            'type' => $event,
            'id' => $delivery,
            'action' => $action,
            'repo' => $repo,
            'sender' => ['login' => $at('sender.login'), 'url' => $at('sender.html_url')],
            'text' => $text,
            'url' => $about['comment']['url'] ?? $about['pull']['url'] ?? $about['issue']['url'] ?? $about['release']['url'] ?? $about['run']['url'] ?? $about['push']['compare'] ?? $at('repository.html_url'),
            ...$about,
            'body' => strlen($whole) <= 64 * 1024 ? $payload : null,
        ];
    }

    /**
     * Something that happened on a Stripe account, as its webhook tells it:
     * the event's type (checkout.session.completed, ...) and the thing it is
     * about -- a checkout, a payment, an invoice, a subscription -- with how
     * much, who paid, and a line saying what happened. The whole of it is in
     * object, unless it is too big to keep.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function stripe(array $event): array
    {
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $at = fn (string $path) => data_get($object, $path);
        $kind = (string) ($at('object') ?? '');
        $type = (string) $event['type'];
        $live = (bool) ($event['livemode'] ?? false);
        $money = StripeCall::amount($at('amount_total') ?? $at('amount_paid') ?? $at('amount_received') ?? $at('amount_refunded') ?? $at('amount') ?? $at('amount_due'), $at('currency'));
        $email = $at('customer_details.email') ?? $at('customer_email') ?? $at('receipt_email') ?? $at('billing_details.email');
        $name = $at('customer_details.name') ?? $at('customer_name') ?? $at('billing_details.name');
        $customer = is_string($at('customer')) ? $at('customer') : $at('customer.id');
        $how = $money['amount'] === null ? '' : ' of '.number_format((float) $money['amount'], $money['amount'] == (int) $money['amount'] ? 0 : 2).' '.strtoupper((string) $money['currency']);
        $who = is_string($email) ? " from {$email}" : (is_string($name) ? " from {$name}" : '');
        $what = match (true) {
            str_starts_with($type, 'checkout.session.') && str_ends_with($type, 'failed') => "A checkout payment{$how} failed{$who}",
            str_starts_with($type, 'checkout.session.') => "A checkout{$how} was paid{$who}",
            $type === 'payment_intent.succeeded' => "A payment{$how} came in{$who}",
            $type === 'payment_intent.payment_failed' => "A payment{$how} failed{$who}: ".($at('last_payment_error.message') ?? 'no reason given'),
            $type === 'invoice.paid' => 'Invoice '.($at('number') ?? '')."{$how} was paid{$who}",
            $type === 'invoice.payment_failed' => 'Paying invoice '.($at('number') ?? '')."{$how} failed{$who}",
            $type === 'charge.refunded' => "A payment was refunded{$how}{$who}",
            str_starts_with($type, 'customer.subscription.') => 'A subscription was '.substr($type, 22).' ('.($at('status') ?? '').')',
            $type === 'charge.dispute.created' => "A payment{$how} is disputed: ".($at('reason') ?? ''),
            default => "Stripe: {$type}",
        };
        $page = match ($kind) {
            'checkout.session' => is_string($at('payment_intent')) ? "payments/{$at('payment_intent')}" : null,
            'payment_intent' => "payments/{$at('id')}",
            'charge' => is_string($at('payment_intent')) ? "payments/{$at('payment_intent')}" : null,
            'invoice' => "invoices/{$at('id')}",
            'subscription' => "subscriptions/{$at('id')}",
            'customer' => "customers/{$at('id')}",
            'dispute' => "disputes/{$at('id')}",
            default => null,
        };
        $whole = json_encode($object) ?: '';

        return [
            'service' => 'stripe',
            'type' => $type,
            'id' => $event['id'],
            'live' => $live,
            'kind' => $kind,
            'object_id' => $at('id'),
            'status' => $at('status'),
            ...$money,
            'customer' => $customer,
            'email' => $email,
            'name' => $name,
            'reference' => $at('client_reference_id'),
            'metadata' => (object) (is_array($at('metadata')) ? $at('metadata') : []),
            'payment' => is_string($at('payment_intent')) ? $at('payment_intent') : ($kind === 'payment_intent' ? $at('id') : null),
            'text' => $what.($live ? '' : ' (test)'),
            'url' => $page === null ? null : 'https://dashboard.stripe.com/'.($live ? '' : 'test/').$page,
            'object' => strlen($whole) <= 64 * 1024 ? $object : null,
        ];
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
