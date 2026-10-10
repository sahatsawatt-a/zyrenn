<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Send on LINE: an answer to the message that started the run, with its
 * reply token -- free, but only soon after and only once -- or a message
 * sent to someone first, which counts against the channel's monthly
 * allowance. A sent one carries a retry key the same on every try, so LINE
 * never delivers it twice.
 */
final class LineSend implements Step
{
    public function run(StepContext $step): StepResult
    {
        $channel = References::connectionFor($step->owner(), (string) $step->setting('connection'), ['line'], $step->run->trigger_data)
            ?? throw new StepFailed($step->setting('connection') === References::LINKED
                ? 'This run did not start from a chat linked to a LINE channel.'
                : 'Its channel is gone, or is not this workflow\'s.');
        $text = trim($step->text('text'));

        if ($text === '') {
            throw new StepFailed('The message came to nothing.');
        }

        $messages = [['type' => 'text', 'text' => mb_substr($text, 0, 5000)]];
        $http = Http::timeout(20)->withToken((string) $channel->secret('channel_access_token'));

        if ($step->setting('mode') === 'push') {
            $to = trim($step->text('to'));

            if ($to === '') {
                throw new StepFailed('There is no one to send to.');
            }

            $key = $step->sendKey();
            $response = $step->deliver('line', $to, $key, fn () => self::send(
                $http->withHeaders(['X-Line-Retry-Key' => $key]),
                'push',
                ['to' => $to, 'messages' => $messages],
            ));

            // Sent before, under this key: it went out
            if ($response->status() === 409) {
                return new StepResult(['to' => $to, 'sent' => true, 'repeat' => true]);
            }
        } else {
            $token = $step->run->trigger_data['reply']['token'] ?? null;

            if (! is_string($token) || $token === '') {
                throw new StepFailed('Only a run started by a LINE message can answer it. Send to someone instead.');
            }

            $to = (string) ($step->run->trigger_data['chat']['id'] ?? '');
            $response = $step->deliver('line', $to, null, fn () => self::send($http, 'reply', ['replyToken' => $token, 'messages' => $messages]));
        }

        if ($response->status() === 429) {
            throw new StepFailed('LINE asked to slow down, or the channel\'s monthly allowance is used up.', retryable: true, retryAfter: 30);
        }

        if (! $response->successful()) {
            $why = $response->json('message') ?? "{$response->status()}.";

            throw new StepFailed(
                str_contains(strtolower((string) $why), 'reply token') ? "LINE said: {$why} An answer must come soon after the message; send to someone instead." : "LINE said: {$why}",
                retryable: $response->serverError(),
            );
        }

        return new StepResult(['to' => $to, 'sent' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function send(PendingRequest $http, string $how, array $payload): Response
    {
        try {
            return $http->post("https://api.line.me/v2/bot/message/{$how}", $payload);
        } catch (ConnectionException $e) {
            throw StepFailed::unreached($e, 'LINE');
        }
    }
}
