<?php

namespace App\Support\Workflow\Steps;

use App\Models\Drive\DriveFile;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Send on Telegram: a message from a bot to a chat ("Send a file on
 * Telegram" sends a file the same way, by Telegram's own calls for one --
 * a picture or a video shown as one, anything else as a document, the
 * words as its caption). Telegram has no way to
 * say "this is a repeat", so the send is recorded, and one whose worker dies
 * partway is not simply sent again (the step's "unsure" setting says what
 * happens). A bot sends at most about 30 messages a second, and Telegram
 * says how long to wait when it is too many.
 */
final class TelegramSend implements Step
{
    private const PER_SECOND = 25;

    /** What a bot may send: any file up to 50 MB, a picture shown as one up to 10 MB. */
    private const MAX_FILE_BYTES = 50 * 1024 * 1024;

    private const MAX_PHOTO_BYTES = 10 * 1024 * 1024;

    private const PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function run(StepContext $step): StepResult
    {
        return self::sendWith($step, null);
    }

    /**
     * Send the step's words to its chat -- with $file, as that file's caption.
     *
     * @throws StepFailed
     */
    public static function sendWith(StepContext $step, ?DriveFile $file): StepResult
    {
        $bot = References::connectionFor($step->owner(), (string) $step->setting('connection'), ['telegram'], $step->run->trigger_data)
            ?? throw new StepFailed($step->setting('connection') === References::LINKED
                ? 'This run did not start from a chat linked to a Telegram bot.'
                : 'Its bot is gone, or is not this workflow\'s.');
        $chat = trim($step->text('chat'));
        $text = trim($step->text('text'));

        if ($chat === '' || ($text === '' && $file === null)) {
            throw new StepFailed($chat === '' ? 'There is no chat to send to.' : 'The message came to nothing.');
        }

        if ($file !== null && $file->size > self::MAX_FILE_BYTES) {
            throw new StepFailed('A bot sends files of at most '.intdiv(self::MAX_FILE_BYTES, 1024 * 1024)." MB, and \"{$file->name}\" is larger.");
        }

        if (! RateLimiter::attempt("telegram:{$bot->id}", self::PER_SECOND, fn () => true, 1)) {
            throw new StepFailed('The bot is sending as fast as Telegram allows.', retryable: true, retryAfter: 1);
        }

        // How Telegram shows it: a picture, a video, or a document to open
        [$how, $part] = match (true) {
            $file === null => ['sendMessage', null],
            in_array($file->mime, self::PHOTO_MIMES, true) && $file->size <= self::MAX_PHOTO_BYTES => ['sendPhoto', 'photo'],
            $file->mime === 'video/mp4' => ['sendVideo', 'video'],
            default => ['sendDocument', 'document'],
        };

        $payload = array_filter([
            'chat_id' => $chat,
            ...$file === null ? ['text' => mb_substr($text, 0, 4096)] : ['caption' => mb_substr($text, 0, 1024)],
            'parse_mode' => ['markdown' => 'MarkdownV2', 'html' => 'HTML'][$step->setting('format')] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
        $sent = $file === null ? null : ['as' => (string) $part, 'path' => DriveSteps::path($file), 'name' => $file->name];

        $response = $step->deliver('telegram', $chat, null, function () use ($bot, $payload, $how, $sent) {
            $bytes = $sent === null ? false : fopen($sent['path'], 'r');
            $http = $sent === null || $bytes === false
                ? Http::timeout(20)
                : Http::timeout(120)->attach($sent['as'], $bytes, $sent['name']);

            try {
                return $http->post('https://api.telegram.org/bot'.$bot->secret('bot_token')."/{$how}", $payload);
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
            ...$file === null ? [] : ['file' => $file->ref_id],
        ]);
    }
}
