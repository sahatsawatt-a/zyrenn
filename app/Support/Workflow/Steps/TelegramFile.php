<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\Telegram;
use App\Support\Outside\RemoteDownload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Telegram: get the file -- a picture, a document, a video or a recording
 * sent to a bot. Telegram is asked where the file is (getFile), then for the
 * file itself; it hands a bot at most 20 MB. Kept in the Drive, and given
 * out as that file.
 */
final class TelegramFile implements Step
{
    public function run(StepContext $step): StepResult
    {
        return MessageFile::get($step, 'telegram', 'bot', self::fetch(...));
    }

    /**
     * @return array{path: string, name: string}
     */
    private static function fetch(Connection $bot, string $id): array
    {
        try {
            $where = Telegram::get($bot, 'getFile', ['file_id' => $id], timeout: 15);
        } catch (ConnectionException) {
            throw new StepFailed('Could not reach Telegram.', retryable: true);
        }

        DriveSteps::busy($where, 'Telegram', (int) ($where->json('parameters.retry_after') ?? 5));

        if (! $where->json('ok') || ! is_string($path = $where->json('result.file_path'))) {
            $why = (string) ($where->json('description') ?? "{$where->status()}.");

            throw new StepFailed(str_contains(strtolower($why), 'too big')
                ? 'Telegram hands a bot files of at most 20 MB, and this one is larger.'
                : "Telegram said: {$why}");
        }

        return DriveSteps::fetched(function () use ($bot, $path) {
            $response = Http::withOptions(['stream' => true])->timeout(DriveSteps::TIMEOUT)->get(Telegram::file($bot, $path));
            DriveSteps::busy($response, 'Telegram', 5);

            if (! $response->successful()) {
                throw new StepFailed("Telegram answered {$response->status()} for the file.");
            }

            // Named by the path alone: the address itself holds the bot's key
            return RemoteDownload::keep($response, $path, DriveSteps::MAX_BYTES);
        }, 'Telegram');
    }
}
