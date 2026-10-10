<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Send on Telegram: a message from a bot to a chat. Telegram has no way to
 * say "this is a repeat", so the send is recorded, and one whose worker dies
 * partway is not simply sent again (the step's "unsure" setting says what
 * happens). A bot sends at most about 30 messages a second, and Telegram
 * says how long to wait when it is too many.
 */
final class TelegramSend implements Step
{
    private const PER_SECOND = 25;

    public function run(StepContext $step): StepResult
    {
        $bot = References::connectionFor($step->owner(), (string) $step->setting('connection'), ['telegram'], $step->run->trigger_data)
            ?? throw new StepFailed($step->setting('connection') === References::LINKED
                ? 'This run did not start from a chat linked to a Telegram bot.'
                : 'Its bot is gone, or is not this workflow\'s.');
        $chat = trim($step->text('chat'));
        $text = trim($step->text('text'));

        if ($chat === '' || $text === '') {
            throw new StepFailed($chat === '' ? 'There is no chat to send to.' : 'The message came to nothing.');
        }

        if (! RateLimiter::attempt("telegram:{$bot->id}", self::PER_SECOND, fn () => true, 1)) {
            throw new StepFailed('The bot is sending as fast as Telegram allows.', retryable: true, retryAfter: 1);
        }

        $payload = array_filter([
            'chat_id' => $chat,
            'text' => mb_substr($text, 0, 4096),
            'parse_mode' => ['markdown' => 'MarkdownV2', 'html' => 'HTML'][$step->setting('format')] ?? null,
        ]);

        $response = $step->deliver('telegram', $chat, null, function () use ($bot, $payload) {
            try {
                return Http::timeout(20)->post('https://api.telegram.org/bot'.$bot->secret('bot_token').'/sendMessage', $payload);
            } catch (ConnectionException $e) {
                throw StepFailed::unreached($e, 'Telegram');
            }
        });

        if ($response->status() === 429) {
            throw new StepFailed('Telegram asked to slow down.', retryable: true, retryAfter: (int) ($response->json('parameters.retry_after') ?? 5));
        }

        if (! $response->json('ok')) {
            throw new StepFailed('Telegram said: '.($response->json('description') ?? "{$response->status()}."), retryable: $response->serverError());
        }

        return new StepResult([
            'message_id' => $response->json('result.message_id'),
            'chat' => $chat,
        ]);
    }
}
