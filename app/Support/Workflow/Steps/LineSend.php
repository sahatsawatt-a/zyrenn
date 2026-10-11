<?php

namespace App\Support\Workflow\Steps;

use App\Models\Drive\DriveFile;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Push on LINE: a message sent to someone first, by LINE's push call --
 * counted against the channel's monthly allowance. It carries a retry key
 * the same on every try, so LINE never delivers it twice. ("Reply on LINE"
 * is the other call: LineReply.)
 *
 * A message is words, a picture from the Drive, or both. LINE is not sent
 * the picture: it is given an address and fetches it, so the app has to be
 * reachable from outside, and the address is signed and lasts an hour.
 */
final class LineSend implements Step
{
    private const MAX_PICTURE_BYTES = 10 * 1024 * 1024;

    public function run(StepContext $step): StepResult
    {
        // Published before replying became a step of its own: it answers still
        return self::message($step, $step->setting('mode') === 'reply' ? 'reply' : 'push');
    }

    /**
     * Send the step's message by one of LINE's two calls: "reply", with the
     * token of the message being answered, or "push", to someone.
     *
     * @throws StepFailed
     */
    public static function message(StepContext $step, string $how): StepResult
    {
        $channel = References::connectionFor($step->owner(), (string) $step->setting('connection'), ['line'], $step->run->trigger_data)
            ?? throw new StepFailed($step->setting('connection') === References::LINKED
                ? 'This run did not start from a chat linked to a LINE channel.'
                : 'Its channel is gone, or is not this workflow\'s.');
        $text = trim($step->text('text'));
        $file = filled($step->setting('file')) ? DriveSteps::file($step) : null;

        if ($text === '' && $file === null) {
            throw new StepFailed('The message came to nothing.');
        }

        $messages = [
            ...$file === null ? [] : [self::picture($file)],
            ...$text === '' ? [] : [['type' => 'text', 'text' => mb_substr($text, 0, 5000)]],
        ];
        $http = Http::timeout(20)->withToken((string) $channel->secret('channel_access_token'));

        if ($how === 'push') {
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
            // The token the step names; one set up before it could name any, the run's own
            $token = $step->setting('token') === null
                ? ($step->run->trigger_data['reply']['token'] ?? null)
                : trim($step->text('token'));

            if (! is_string($token) || $token === '') {
                throw new StepFailed('There is no reply token: only a LINE message can be answered, with the token it came with ({{ trigger.reply.token }}). To write to someone first, use "Push on LINE".');
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
                str_contains(strtolower((string) $why), 'reply token') ? "LINE said: {$why} A reply must come soon after the message, and only once; \"Push on LINE\" writes to someone at any time." : "LINE said: {$why}",
                retryable: $response->serverError(),
            );
        }

        return new StepResult(['to' => $to, 'sent' => true]);
    }

    /**
     * A picture as LINE takes one: an address for it, and one for the small
     * version shown in the chat -- the same, there being no smaller one.
     *
     * @return array<string, string>
     */
    private static function picture(DriveFile $file): array
    {
        if (! in_array($file->mime, ['image/jpeg', 'image/png'], true)) {
            throw new StepFailed("LINE takes only a JPEG or PNG picture this way, and \"{$file->name}\" is neither.");
        }

        if ($file->size > self::MAX_PICTURE_BYTES) {
            throw new StepFailed('LINE takes pictures of at most '.intdiv(self::MAX_PICTURE_BYTES, 1024 * 1024)." MB, and \"{$file->name}\" is larger.");
        }

        DriveSteps::path($file);
        $url = DriveSteps::publicUrl($file);

        return ['type' => 'image', 'originalContentUrl' => $url, 'previewImageUrl' => $url];
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
