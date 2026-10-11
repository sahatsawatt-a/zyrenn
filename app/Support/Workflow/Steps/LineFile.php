<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\RemoteDownload;
use Illuminate\Support\Facades\Http;

/**
 * LINE: get the file -- a picture, a video, a recording or a file sent to a
 * channel. A LINE message carries only its id; LINE is asked for what it
 * holds under that id (its "content"), with the channel's key. LINE keeps it
 * only for a while, so this belongs in the run the message started. Kept in
 * the Drive, and given out as that file.
 */
final class LineFile implements Step
{
    public function run(StepContext $step): StepResult
    {
        return MessageFile::get($step, 'line', 'channel', self::fetch(...));
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @return array{path: string, name: string}
     */
    private static function fetch(Connection $channel, string $id, array $trigger): array
    {
        $message = $trigger['raw']['message'] ?? [];

        // A picture or a video LINE never held: its sender's own address for it
        if (($message['id'] ?? null) === $id && ($message['contentProvider']['type'] ?? 'line') === 'external') {
            return DriveSteps::fetched(fn () => RemoteDownload::fetch((string) ($message['contentProvider']['originalContentUrl'] ?? ''), DriveSteps::MAX_BYTES, DriveSteps::TIMEOUT));
        }

        return DriveSteps::fetched(function () use ($channel, $id) {
            $url = 'https://api-data.line.me/v2/bot/message/'.rawurlencode($id).'/content';
            $response = Http::withToken((string) $channel->secret('channel_access_token'))
                ->withOptions(['stream' => true])
                ->timeout(DriveSteps::TIMEOUT)
                ->get($url);

            DriveSteps::busy($response, 'LINE', 30);

            // A video or a recording LINE is still getting ready
            if ($response->status() === 202) {
                throw new StepFailed('LINE is still getting the file ready.', retryable: true, retryAfter: 5);
            }

            if (! $response->successful()) {
                throw new StepFailed(in_array($response->status(), [404, 410], true)
                    ? 'LINE no longer has this file: it keeps what is sent only for a while.'
                    : "LINE answered {$response->status()} for the file.");
            }

            return RemoteDownload::keep($response, $url, DriveSteps::MAX_BYTES);
        }, 'LINE');
    }
}
