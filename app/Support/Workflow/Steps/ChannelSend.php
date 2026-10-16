<?php

namespace App\Support\Workflow\Steps;

use App\Models\Chat\ChatMessage;
use App\Support\Connections\Services;
use App\Support\Outside\RemoteDownload;
use App\Support\Outside\RemoteDownloadFailed;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Send on a custom channel: words, a file from the Drive and a place, as
 * JSON to the channel's "Send to" address, signed with its secret. A file
 * goes as a signed link the platform fetches it by, good for a day. Sent
 * once however often it is tried: the request carries an Idempotency-Key
 * for the platform to see a repeat by. What the platform answers comes out
 * as body, and its id, when it gives one.
 */
final class ChannelSend implements Step
{
    private const LINK_MINUTES = 24 * 60;

    public function run(StepContext $step): StepResult
    {
        $channel = References::connectionFor($step->owner(), (string) $step->setting('connection'), [Services::CHANNEL], $step->run->trigger_data)
            ?? throw new StepFailed($step->setting('connection') === References::LINKED
                ? 'The chat this run began in is not linked through a custom channel.'
                : 'Its custom channel is gone, or is not this workflow\'s.');
        $url = (string) $channel->setting('send_url');

        if ($url === '') {
            throw new StepFailed("\"{$channel->name}\" has nowhere to send to: give it a \"Send to\" address.");
        }

        $chat = trim($step->text('to'));
        $text = trim($step->text('text'));
        $file = DriveSteps::fileIfAny($step);
        $place = filled($step->setting('place')) ? ChatMessage::placeOf($step->template('place')) : null;

        if ($chat === '') {
            throw new StepFailed('There is no chat to send to.');
        }

        if ($text === '' && $file === null && $place === null) {
            throw new StepFailed('The message came to nothing: give it words, a file or a place.');
        }

        try {
            [$host, $port, $ip] = RemoteDownload::publicTarget($url);
        } catch (RemoteDownloadFailed $e) {
            throw new StepFailed($e->getMessage());
        }

        $body = (string) json_encode([
            'chat' => $chat,
            'text' => $text,
            'file' => $file === null ? null : [
                'url' => DriveSteps::publicUrl($file, self::LINK_MINUTES),
                'name' => $file->name,
                'mime' => $file->mime,
                'size' => $file->size,
            ],
            'location' => $place,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $key = $step->sendKey();

        $response = $step->deliver(Services::CHANNEL, "{$host} {$chat}", $key, function () use ($url, $body, $channel, $key, $host, $port, $ip) {
            try {
                return Http::withoutRedirecting()
                    ->withUserAgent('Zyrenn')
                    ->withHeaders([ChannelApi::HEADER => ChannelApi::signature($body, $channel), 'Idempotency-Key' => $key])
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->withOptions(['curl' => str_contains($ip, ':') ? [] : [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
                    ->withBody($body, 'application/json')
                    ->post($url);
            } catch (ConnectionException $e) {
                throw StepFailed::unreached($e, $host);
            }
        });

        if ($response->status() === 429 || $response->serverError()) {
            $after = $response->header('Retry-After');

            throw new StepFailed("{$host} answered {$response->status()}.", retryable: true, retryAfter: is_numeric($after) ? (int) $after : null);
        }

        if ($response->failed()) {
            throw new StepFailed("{$host} answered {$response->status()}: ".mb_strimwidth(trim(strip_tags($response->body())), 0, 200, '…'));
        }

        $answer = $response->json();

        return new StepResult([
            'id' => is_array($answer) && is_scalar($answer['id'] ?? null) ? (string) $answer['id'] : null,
            'chat' => $chat,
            'status' => $response->status(),
            'body' => $answer ?? $response->body(),
        ]);
    }
}
