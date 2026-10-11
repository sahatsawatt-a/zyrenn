<?php

namespace App\Support\Workflow\Steps;

use App\Models\Drive\DriveFile;

/**
 * Send on Telegram: a message from a bot to a chat, with buttons under it
 * when the step names any ("Send a file on Telegram" sends a file the same
 * way, by Telegram's own calls for one -- a picture or a video shown as one,
 * a recording played, anything else as a document, the words as its
 * caption). Recorded and never simply repeated (TelegramApi::send).
 */
final class TelegramSend implements Step
{
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
        $bot = TelegramApi::bot($step);
        $chat = trim($step->text('chat'));
        $text = trim($step->text('text'));

        if ($chat === '' || ($text === '' && $file === null)) {
            throw new StepFailed($chat === '' ? 'There is no chat to send to.' : 'The message came to nothing.');
        }

        if ($file !== null && $file->size > self::MAX_FILE_BYTES) {
            throw new StepFailed('A bot sends files of at most '.intdiv(self::MAX_FILE_BYTES, 1024 * 1024)." MB, and \"{$file->name}\" is larger.");
        }

        // How Telegram shows it: a picture, a video, a recording to play, or a document to open
        [$how, $part] = match (true) {
            $file === null => ['sendMessage', null],
            in_array($file->mime, self::PHOTO_MIMES, true) && $file->size <= self::MAX_PHOTO_BYTES => ['sendPhoto', 'photo'],
            $file->mime === 'video/mp4' => ['sendVideo', 'video'],
            in_array($file->mime, ['audio/ogg', 'audio/opus'], true) => ['sendVoice', 'voice'],
            in_array($file->mime, ['audio/mpeg', 'audio/mp4', 'audio/x-m4a'], true) => ['sendAudio', 'audio'],
            default => ['sendDocument', 'document'],
        };

        $result = TelegramApi::send($step, $bot, $how, $chat, [
            'chat_id' => $chat,
            'message_thread_id' => trim($step->text('topic')),
            ...$file === null ? ['text' => mb_substr($text, 0, 4096)] : ['caption' => mb_substr($text, 0, 1024)],
            'parse_mode' => TelegramApi::format($step),
            'reply_markup' => TelegramApi::buttons($step),
        ], $file === null ? [] : [(string) $part => [DriveSteps::path($file), $file->name]]);

        return new StepResult([
            'message_id' => $result['message_id'] ?? null,
            'chat' => $chat,
            ...$file === null ? [] : ['file' => $file->ref_id],
        ]);
    }
}
