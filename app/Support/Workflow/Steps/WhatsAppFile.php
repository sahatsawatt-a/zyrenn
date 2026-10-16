<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\Meta;
use App\Support\Outside\RemoteDownload;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp: get the file -- a picture, a video, a recording or a document
 * sent to a business number. A WhatsApp message carries only its id;
 * WhatsApp is asked where it is, which it says for five minutes at a time,
 * and it is fetched from there with the number's token. WhatsApp keeps it
 * for a week. Kept in the Drive, and given out as that file.
 */
final class WhatsAppFile implements Step
{
    public function run(StepContext $step): StepResult
    {
        return MessageFile::get($step, 'whatsapp', 'number', self::fetch(...));
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @return array{path: string, name: string}
     */
    private static function fetch(Connection $number, string $id, array $trigger): array
    {
        $token = (string) $number->secret('access_token');
        $where = MetaApi::call(WhatsAppApi::NAME, fn () => MetaApi::as($token)->get(Meta::FACEBOOK.'/'.rawurlencode($id)));
        $url = $where->json('url');

        if (! is_string($url) || $url === '') {
            throw new StepFailed('WhatsApp no longer has this file: it keeps what is sent for a week.');
        }

        return DriveSteps::fetched(function () use ($token, $url) {
            $response = Http::withToken($token)->withOptions(['stream' => true])->timeout(DriveSteps::TIMEOUT)->get($url);

            DriveSteps::busy($response, 'WhatsApp', 30);

            if (! $response->successful()) {
                throw new StepFailed("WhatsApp answered {$response->status()} for the file.");
            }

            return RemoteDownload::keep($response, $url, DriveSteps::MAX_BYTES);
        }, 'WhatsApp');
    }
}
