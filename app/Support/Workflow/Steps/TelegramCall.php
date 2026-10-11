<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Support\Workflow\References;

/**
 * Telegram's other calls, each a kind of step of its own (NodeTypes names
 * them; this runs them): the Bot API method a kind stands for, what it is
 * sent, and what of Telegram's answer the step gives out.
 *
 * The ones that leave something new in a chat -- a location, a poll, an
 * album, a forwarded message, an invoice -- are recorded and never simply
 * made twice (TelegramApi::send). The rest ask, or set something to what
 * it would be anyway, and can be made again (TelegramApi::call).
 */
final class TelegramCall implements Step
{
    public function run(StepContext $step): StepResult
    {
        $bot = TelegramApi::bot($step);

        return new StepResult(match ($step->node['type']) {
            'telegram.album' => self::album($step, $bot),
            'telegram.sticker' => self::sent($step, $bot, 'sendSticker', ['sticker' => TelegramApi::needs($step, 'sticker', 'sticker to send')]),
            'telegram.location' => self::location($step, $bot),
            'telegram.contact' => self::sent($step, $bot, 'sendContact', [
                'phone_number' => TelegramApi::needs($step, 'phone', 'phone number'),
                'first_name' => TelegramApi::needs($step, 'first_name', 'name'),
                'last_name' => trim($step->text('last_name')),
            ]),
            'telegram.poll' => self::poll($step, $bot),
            'telegram.forward' => self::sent($step, $bot, 'forwardMessage', self::carried($step)),
            'telegram.copy' => self::sent($step, $bot, 'copyMessage', self::carried($step)),
            'telegram.invoice' => self::invoice($step, $bot),
            'telegram.topic' => self::topic($step, $bot),

            'telegram.action' => self::done($bot, 'sendChatAction', [
                'chat_id' => self::chat($step),
                'message_thread_id' => trim($step->text('topic')),
                'action' => (string) ($step->setting('action') ?: 'typing'),
            ]),
            'telegram.edit' => self::edited($step, $bot, 'editMessageText', [
                'text' => mb_substr(TelegramApi::needs($step, 'text', 'message to change it to'), 0, 4096),
                'parse_mode' => TelegramApi::format($step),
            ]),
            'telegram.edit_buttons' => self::edited($step, $bot, 'editMessageReplyMarkup', []),
            'telegram.delete' => self::done($bot, 'deleteMessage', self::message($step)),
            'telegram.pin' => self::done($bot, 'pinChatMessage', [...self::message($step), 'disable_notification' => $step->setting('notify') !== 'yes']),
            'telegram.unpin' => self::done($bot, 'unpinChatMessage', ['chat_id' => self::chat($step), 'message_id' => trim($step->text('message'))]),
            'telegram.react' => self::done($bot, 'setMessageReaction', [
                ...self::message($step),
                // No emoji takes the bot's reaction away: an empty list, which must still be sent
                'reaction' => ($emoji = trim($step->text('emoji'))) === '' ? [] : [['type' => 'emoji', 'emoji' => $emoji]],
            ]),
            'telegram.answer_button' => self::done($bot, 'answerCallbackQuery', [
                'callback_query_id' => TelegramApi::needs($step, 'press', 'button press to answer: {{ trigger.button.id }}'),
                'text' => mb_substr(trim($step->text('text')), 0, 200),
                'show_alert' => $step->setting('show') === 'alert',
            ]),
            'telegram.commands' => self::commands($step, $bot),
            'telegram.ban' => self::done($bot, 'banChatMember', [...self::member($step), 'until_date' => self::until($step)]),
            'telegram.unban' => self::done($bot, 'unbanChatMember', [...self::member($step), 'only_if_banned' => true]),
            'telegram.restrict' => self::done($bot, 'restrictChatMember', [
                ...self::member($step),
                'permissions' => self::permissions((string) ($step->setting('allow') ?: 'nothing')),
                'until_date' => self::until($step),
            ]),
            'telegram.answer_inline' => self::answerInline($step, $bot),
            'telegram.answer_checkout' => self::done($bot, 'answerPreCheckoutQuery', [
                'pre_checkout_query_id' => TelegramApi::needs($step, 'checkout', 'checkout to answer: {{ trigger.checkout.id }}'),
                'ok' => $step->setting('answer') !== 'refuse',
                'error_message' => $step->setting('answer') === 'refuse' ? (trim($step->text('why')) ?: 'This can\'t be paid for just now.') : null,
            ]),

            'telegram.chat' => self::aboutChat($step, $bot),
            'telegram.member' => self::aboutMember($step, $bot),
            'telegram.member_count' => ['chat' => self::chat($step), 'count' => (int) TelegramApi::call($bot, 'getChatMemberCount', ['chat_id' => self::chat($step)])],

            default => throw new StepFailed("This app doesn't know the Telegram step \"{$step->node['type']}\"."),
        });
    }

    // --- sent: something new in a chat ---

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function sent(StepContext $step, Connection $bot, string $method, array $payload): array
    {
        $chat = self::chat($step);
        $result = TelegramApi::send($step, $bot, $method, $chat, [
            'chat_id' => $chat,
            'message_thread_id' => trim($step->text('topic')),
            ...$payload,
        ]);

        return ['message_id' => $result['message_id'] ?? null, 'chat' => $chat];
    }

    /**
     * A place: a pin on the map, or with a name, a venue.
     *
     * @return array<string, mixed>
     */
    private static function location(StepContext $step, Connection $bot): array
    {
        $lat = TelegramApi::needs($step, 'lat', 'latitude');
        $lng = TelegramApi::needs($step, 'lng', 'longitude');

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            throw new StepFailed('A place is two numbers: its latitude and its longitude.');
        }

        $title = trim($step->text('title'));
        $place = ['latitude' => (float) $lat, 'longitude' => (float) $lng];

        return $title === ''
            ? self::sent($step, $bot, 'sendLocation', $place)
            : self::sent($step, $bot, 'sendVenue', [...$place, 'title' => mb_substr($title, 0, 255), 'address' => mb_substr(trim($step->text('address')) ?: $title, 0, 255)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function poll(StepContext $step, Connection $bot): array
    {
        $options = self::lines($step->text('options'));

        if (count($options) < 2 || count($options) > 12) {
            throw new StepFailed('A poll has from 2 to 12 answers, one on each line.');
        }

        return self::sent($step, $bot, 'sendPoll', [
            'question' => mb_substr(TelegramApi::needs($step, 'question', 'question to ask'), 0, 300),
            'options' => array_map(fn (string $option) => ['text' => mb_substr($option, 0, 100)], $options),
            'is_anonymous' => $step->setting('anonymous') !== 'no',
            'allows_multiple_answers' => $step->setting('several') === 'yes',
        ]);
    }

    /**
     * Several pictures and videos from the Drive as one message.
     *
     * @return array<string, mixed>
     */
    private static function album(StepContext $step, Connection $bot): array
    {
        $chat = self::chat($step);
        $files = DriveSteps::files($step, 'files');

        if (count($files) < 2 || count($files) > 10) {
            throw new StepFailed('An album holds from 2 to 10 pictures and videos; "Send a file on Telegram" sends one.');
        }

        $caption = mb_substr(trim($step->text('text')), 0, 1024);
        $media = [];
        $parts = [];

        foreach ($files as $at => $file) {
            $kind = match (true) {
                str_starts_with((string) $file->mime, 'image/') => 'photo',
                str_starts_with((string) $file->mime, 'video/') => 'video',
                default => throw new StepFailed("An album holds pictures and videos, and \"{$file->name}\" is neither."),
            };
            $media[] = ['type' => $kind, 'media' => "attach://file{$at}", ...$at === 0 && $caption !== '' ? ['caption' => $caption] : []];
            $parts["file{$at}"] = [DriveSteps::path($file), $file->name];
        }

        $result = TelegramApi::send($step, $bot, 'sendMediaGroup', $chat, [
            'chat_id' => $chat,
            'message_thread_id' => trim($step->text('topic')),
            'media' => $media,
        ], $parts);

        return [
            'message_ids' => array_column(is_array($result) ? $result : [], 'message_id'),
            'chat' => $chat,
            'files' => array_map(fn (DriveFile $file) => $file->ref_id, $files),
        ];
    }

    /**
     * A message taken from one chat to another: where from, which, where to.
     *
     * @return array<string, mixed>
     */
    private static function carried(StepContext $step): array
    {
        return [
            'from_chat_id' => TelegramApi::needs($step, 'from', 'chat to take it from'),
            'message_id' => TelegramApi::needs($step, 'message', 'message to take'),
        ];
    }

    /**
     * Something to pay for: in Telegram Stars, or -- through a payment
     * provider, whose token is kept as a secret connection -- in money.
     *
     * @return array<string, mixed>
     */
    private static function invoice(StepContext $step, Connection $bot): array
    {
        $currency = strtoupper(trim($step->text('currency')) ?: 'XTR');
        $amount = TelegramApi::needs($step, 'amount', 'amount to ask for');

        if (! ctype_digit($amount) || (int) $amount < 1) {
            throw new StepFailed('The amount is a whole number: Stars, or for money its smallest unit -- 1050 for 10.50.');
        }

        $provider = null;

        if (filled($step->setting('provider'))) {
            $provider = References::connection($step->owner(), (string) $step->setting('provider'), ['webhook_secret'])?->secret('secret')
                ?? throw new StepFailed('Its payment provider\'s token is gone, or is not this workflow\'s.');
        } elseif ($currency !== 'XTR') {
            throw new StepFailed("Asking for {$currency} needs a payment provider's token; without one, a bot is paid in Stars (XTR).");
        }

        $title = mb_substr(TelegramApi::needs($step, 'title', 'name for what is paid for'), 0, 32);

        return self::sent($step, $bot, 'sendInvoice', [
            'title' => $title,
            'description' => mb_substr(trim($step->text('description')) ?: $title, 0, 255),
            'payload' => mb_strcut(trim($step->text('payload')) ?: $step->run->ref_id, 0, 128),
            'provider_token' => $provider,
            'currency' => $currency,
            'prices' => [['label' => $title, 'amount' => (int) $amount]],
        ]);
    }

    /**
     * A new topic in a group that has them; later steps send "In topic" by its id.
     *
     * @return array<string, mixed>
     */
    private static function topic(StepContext $step, Connection $bot): array
    {
        $chat = self::chat($step);
        $name = mb_substr(TelegramApi::needs($step, 'name', 'name for the topic'), 0, 128);
        $result = TelegramApi::send($step, $bot, 'createForumTopic', $chat, ['chat_id' => $chat, 'name' => $name]);

        return ['topic' => $result['message_thread_id'] ?? null, 'name' => $result['name'] ?? $name, 'chat' => $chat];
    }

    // --- changed: what is there, set to what the step says ---

    /**
     * @param  array<string, mixed>  $payload
     * @return array{done: true}
     */
    private static function done(Connection $bot, string $method, array $payload): array
    {
        TelegramApi::call($bot, $method, $payload);

        return ['done' => true];
    }

    /**
     * A message changed in place; without buttons named, the ones it had go.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function edited(StepContext $step, Connection $bot, string $method, array $payload): array
    {
        $message = self::message($step);

        TelegramApi::call($bot, $method, [
            ...$message,
            ...$payload,
            // Sent even when empty: that is what takes the buttons off
            'reply_markup' => TelegramApi::buttons($step) ?? ['inline_keyboard' => []],
        ]);

        return ['message_id' => $message['message_id'], 'chat' => $message['chat_id']];
    }

    /**
     * The commands the bot offers when "/" is typed: each name a command,
     * its value what it does.
     *
     * @return array<string, mixed>
     */
    private static function commands(StepContext $step, Connection $bot): array
    {
        $commands = [];

        foreach ((array) $step->setting('commands') as $field) {
            $command = strtolower(ltrim(trim((string) ($field['name'] ?? '')), '/'));

            if ($command !== '') {
                $commands[] = ['command' => mb_substr($command, 0, 32), 'description' => mb_substr(trim($step->text("commands.{$command}", (string) ($field['value'] ?? ''))) ?: $command, 0, 256)];
            }
        }

        TelegramApi::call($bot, 'setMyCommands', ['commands' => $commands]);

        return ['done' => true, 'commands' => array_column($commands, 'command')];
    }

    /**
     * What an inline question is answered with: each name a result's
     * title, its value the message choosing it sends.
     *
     * @return array<string, mixed>
     */
    private static function answerInline(StepContext $step, Connection $bot): array
    {
        $results = [];

        foreach ((array) $step->setting('results') as $at => $field) {
            $title = trim((string) ($field['name'] ?? ''));
            $text = trim($step->text("results.{$title}", (string) ($field['value'] ?? '')));

            if ($title !== '') {
                $results[] = ['type' => 'article', 'id' => (string) $at, 'title' => mb_substr($title, 0, 100), 'input_message_content' => ['message_text' => mb_substr($text !== '' ? $text : $title, 0, 4096)]];
            }
        }

        TelegramApi::call($bot, 'answerInlineQuery', [
            'inline_query_id' => TelegramApi::needs($step, 'question', 'inline question to answer: {{ trigger.inline.id }}'),
            'results' => array_slice($results, 0, 50),
            'cache_time' => 0,
        ]);

        return ['done' => true, 'results' => count($results)];
    }

    // --- asked ---

    /**
     * @return array<string, mixed>
     */
    private static function aboutChat(StepContext $step, Connection $bot): array
    {
        $chat = (array) TelegramApi::call($bot, 'getChat', ['chat_id' => self::chat($step)]);

        return [
            'id' => (string) ($chat['id'] ?? ''),
            'kind' => ($chat['type'] ?? 'private') === 'private' ? 'private' : (string) $chat['type'],
            'title' => $chat['title'] ?? (trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: null),
            'username' => $chat['username'] ?? null,
            'description' => $chat['description'] ?? $chat['bio'] ?? null,
            'topics' => (bool) ($chat['is_forum'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function aboutMember(StepContext $step, Connection $bot): array
    {
        $member = (array) TelegramApi::call($bot, 'getChatMember', self::member($step));
        $user = $member['user'] ?? [];
        $status = (string) ($member['status'] ?? 'left');

        return [
            'id' => (string) ($user['id'] ?? ''),
            'name' => trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: ($user['username'] ?? null),
            'username' => $user['username'] ?? null,
            // creator, administrator, member, restricted, left or kicked
            'status' => $status,
            'in' => in_array($status, ['creator', 'administrator', 'member'], true) || ($status === 'restricted' && ($member['is_member'] ?? false)),
            'admin' => in_array($status, ['creator', 'administrator'], true),
        ];
    }

    // --- what settings come to ---

    private static function chat(StepContext $step): string
    {
        return TelegramApi::needs($step, 'chat', 'chat');
    }

    /**
     * @return array{chat_id: string, message_id: string}
     */
    private static function message(StepContext $step): array
    {
        return ['chat_id' => self::chat($step), 'message_id' => TelegramApi::needs($step, 'message', 'message: its id, like {{ trigger.reply.to }}')];
    }

    /**
     * @return array{chat_id: string, user_id: string}
     */
    private static function member(StepContext $step): array
    {
        return ['chat_id' => self::chat($step), 'user_id' => TelegramApi::needs($step, 'user', 'person: their id, like {{ trigger.sender.id }}')];
    }

    /**
     * When a ban or a limit ends, as Telegram takes it; null for good.
     */
    private static function until(StepContext $step): ?int
    {
        $minutes = (int) trim($step->text('minutes'));

        return $minutes > 0 ? now()->addMinutes($minutes)->getTimestamp() : null;
    }

    /**
     * @return array<string, bool> what someone may still do, as Telegram's permissions
     */
    private static function permissions(string $allow): array
    {
        $words = $allow !== 'nothing';
        $all = $allow === 'everything';

        return [
            'can_send_messages' => $words,
            'can_send_audios' => $all, 'can_send_documents' => $all, 'can_send_photos' => $all,
            'can_send_videos' => $all, 'can_send_video_notes' => $all, 'can_send_voice_notes' => $all,
            'can_send_polls' => $all, 'can_send_other_messages' => $all, 'can_add_web_page_previews' => $all,
        ];
    }

    /**
     * @return list<string> a text's lines, without the empty ones
     */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), fn (string $line) => $line !== ''));
    }
}
