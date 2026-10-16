<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\Telegram;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * What every Telegram step shares: the bot, the chat, and a call to one of
 * the Bot API's methods with Telegram's answer read the same way each time.
 *
 * Telegram has no way to say "this is a repeat", so a call that leaves
 * something new in a chat is recorded (send), and one whose worker dies
 * partway is not simply made again; a call that only asks, or sets
 * something to what it would be anyway, is simply made (call). A bot sends
 * at most about 30 messages a second, and Telegram says how long to wait
 * when it is too many.
 */
final class TelegramApi
{
    private const PER_SECOND = 25;

    /** How what a button does starts, when it is an address to open. */
    public const ADDRESS = '^(https?|tg)://';

    /**
     * The step's bot: one of the owner's, or the one the run's chat is linked through.
     *
     * @throws StepFailed
     */
    public static function bot(StepContext $step): Connection
    {
        return References::connectionFor($step->owner(), (string) $step->setting('connection'), ['telegram'], $step->run->trigger_data)
            ?? throw new StepFailed($step->setting('connection') === References::LINKED
                ? 'This run did not start from a chat linked to a Telegram bot.'
                : 'Its bot is gone, or is not this workflow\'s.');
    }

    /**
     * A setting that has to come to something: a chat, a message's id.
     *
     * @throws StepFailed
     */
    public static function needs(StepContext $step, string $setting, string $what): string
    {
        $value = trim($step->text($setting));

        return $value !== '' ? $value : throw new StepFailed("There is no {$what}.");
    }

    /**
     * A call that asks, or changes what is there to what it would be anyway.
     *
     * @param  array<string, mixed>  $payload
     * @return mixed Telegram's "result"
     *
     * @throws StepFailed
     */
    public static function call(Connection $bot, string $method, array $payload = []): mixed
    {
        try {
            return self::result(Http::timeout(20)->post(self::url($bot, $method), self::clean($payload)));
        } catch (ConnectionException) {
            throw new StepFailed('Could not reach Telegram.', retryable: true);
        }
    }

    /**
     * A call that leaves something new in a chat, recorded so it is never
     * simply made twice. $files are sent with it: part name => [path, name].
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, array{0: string, 1: string}>  $files
     * @return mixed Telegram's "result"
     *
     * @throws StepFailed
     */
    public static function send(StepContext $step, Connection $bot, string $method, string $target, array $payload, array $files = []): mixed
    {
        if (! RateLimiter::attempt("telegram:{$bot->id}", self::PER_SECOND, fn () => true, 1)) {
            throw new StepFailed('The bot is sending as fast as Telegram allows.', retryable: true, retryAfter: 1);
        }

        $payload = self::clean($payload);

        return self::result($step->deliver('telegram', $target, null, function () use ($bot, $method, $payload, $files) {
            $http = Http::timeout($files === [] ? 20 : 120);

            foreach ($files as $part => [$path, $name]) {
                $bytes = fopen($path, 'r');

                if ($bytes !== false) {
                    $http = $http->attach($part, $bytes, $name);
                }
            }

            // Beside a file everything goes as a form's parts, so what is a list or a record is written out
            $body = $files === [] ? $payload : array_map(fn ($value) => is_array($value) ? (string) json_encode($value) : $value, $payload);

            try {
                return $http->post(self::url($bot, $method), $body);
            } catch (ConnectionException $e) {
                throw StepFailed::unreached($e, 'Telegram');
            }
        }));
    }

    /**
     * Buttons under a message, from a step's named values: each name is
     * what the button says, its value what pressing it does -- an address
     * opens ("link:" before it says it is one, where a formula gives it),
     * "webapp:" and an address opens it as a mini app, anything else
     * comes back to the bot as the press (trigger.text), the name itself
     * when there is none. Null when the step names no buttons.
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}|null
     *
     * @throws StepFailed
     */
    public static function buttons(StepContext $step, string $setting = 'buttons'): ?array
    {
        $buttons = [];

        foreach ((array) $step->setting($setting) as $field) {
            $label = trim((string) ($field['name'] ?? ''));
            $does = trim($step->text("{$setting}.{$label}", (string) ($field['value'] ?? '')));

            if ($label === '') {
                continue;
            }

            $buttons[] = match (true) {
                preg_match('#'.self::ADDRESS.'#i', $does) === 1 => ['text' => $label, 'url' => $does],
                str_starts_with($does, 'link:') => ['text' => $label, 'url' => trim(substr($does, 5))],
                str_starts_with($does, 'webapp:') => ['text' => $label, 'web_app' => ['url' => trim(substr($does, 7))]],
                strlen($does) > 64 => throw new StepFailed("What the button \"{$label}\" sends back is longer than the 64 bytes Telegram keeps."),
                default => ['text' => $label, 'callback_data' => $does !== '' ? $does : mb_strcut($label, 0, 64)],
            };
        }

        if ($buttons === []) {
            return null;
        }

        return ['inline_keyboard' => array_chunk($buttons, max(1, min(8, (int) ($step->setting('per_row') ?: 1))))];
    }

    /**
     * How a step's words are written, as Telegram calls it.
     */
    public static function format(StepContext $step): ?string
    {
        return ['markdown' => 'MarkdownV2', 'html' => 'HTML'][$step->setting('format')] ?? null;
    }

    /**
     * @return mixed Telegram's "result"
     *
     * @throws StepFailed
     */
    private static function result(Response $response): mixed
    {
        if ($response->status() === 429) {
            throw new StepFailed('Telegram asked to slow down.', retryable: true, retryAfter: (int) ($response->json('parameters.retry_after') ?? 5));
        }

        if (! $response->json('ok')) {
            throw new StepFailed('Telegram said: '.($response->json('description') ?? "{$response->status()}."), retryable: $response->serverError());
        }

        return $response->json('result');
    }

    private static function url(Connection $bot, string $method): string
    {
        return Telegram::method($bot, $method);
    }

    /**
     * Without what was left empty: Telegram reads a missing part as "the usual".
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function clean(array $payload): array
    {
        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }
}
