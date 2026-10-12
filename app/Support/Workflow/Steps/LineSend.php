<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\NodeTypes;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Push on LINE: a message sent to someone first, by LINE's push call --
 * counted against the channel's monthly allowance. It carries a retry key
 * the same on every try, so LINE never delivers it twice.
 *
 * LINE's other calls that carry a message go out from here too, each a step
 * of its own: reply (LineReply), with the token of the message answered;
 * multicast, to several people at once; broadcast, to everyone who added
 * the channel (LineCall). What a message is made of is the same for all
 * four (LineApi::messages).
 */
final class LineSend implements Step
{
    /** How many people one multicast reaches. */
    private const MOST_AT_ONCE = 500;

    /** For how many seconds after a message its reply token is tried, unless the step says. */
    public const REPLY_WITHIN = 60;

    public function run(StepContext $step): StepResult
    {
        // Published before replying became a step of its own: it answers still
        return self::message($step, $step->setting('mode') === 'reply' ? 'reply' : 'push');
    }

    /**
     * Send the step's message by one of LINE's calls: "reply", "push",
     * "multicast" or "broadcast".
     *
     * @throws StepFailed
     */
    public static function message(StepContext $step, string $how): StepResult
    {
        $channel = LineApi::channel($step);
        $messages = LineApi::messages($step);
        $http = Http::timeout(20)->withToken((string) $channel->secret('channel_access_token'));
        $key = $step->sendKey();

        if ($how === 'reply') {
            // The token the step names; one set up before it could name any, the run's own
            $token = $step->setting('token') === null
                ? ($step->run->trigger_data['reply']['token'] ?? null)
                : trim($step->text('token'));
            $heard = $step->run->trigger_data;
            $to = (string) ($heard['chat']['id'] ?? $heard['room']['link']['chat'] ?? '');
            $output = ['to' => $to, 'sent' => true];
            $why = self::unusable($step, is_string($token) ? $token : '');

            if ($why === null) {
                $response = $step->deliver('line', $to, null, fn () => self::send($http, 'reply', ['replyToken' => $token, 'messages' => $messages]));
                $said = (string) ($response->json('message') ?? '');

                if ($response->status() === 400 && str_contains(strtolower($said), 'reply token')) {
                    $why = "LINE said: {$said} A reply must come soon after the message, and only once.";
                }
            }

            if ($why !== null) {
                return self::unsent($step, $to, $why);
            }
        } else {
            [$target, $body, $output] = match ($how) {
                'multicast' => self::toMany($step),
                'broadcast' => ['everyone', [], ['to' => 'everyone', 'sent' => true]],
                default => self::toOne($step),
            };

            $response = $step->deliver('line', $target, $key, fn () => self::send(
                $http->withHeaders(['X-Line-Retry-Key' => $key]),
                $how,
                [...$body, 'messages' => $messages],
            ));

            // Sent before, under this key: it went out
            if ($response->status() === 409) {
                return new StepResult([...$output, 'repeat' => true]);
            }
        }

        if ($response->status() === 429) {
            throw new StepFailed('LINE asked to slow down, or the channel\'s monthly allowance is used up.', retryable: true, retryAfter: 30);
        }

        if (! $response->successful()) {
            $why = $response->json('message') ?? "{$response->status()}.";
            $detail = collect((array) $response->json('details'))->pluck('message')->filter()->implode(' ');

            throw new StepFailed(trim("LINE said: {$why} {$detail}"), retryable: $response->serverError());
        }

        return new StepResult($output);
    }

    /**
     * Why a reply token can't be used, without asking LINE; null when it
     * can be tried. It is too old when the step gives a time and the token
     * is the one the run began with -- a LINE message's own, or, from a
     * linked chat, that of the last thing the other end sent -- so that its
     * age is known. And LINE takes a token once: whichever step has it
     * first, and no other after.
     */
    private static function unusable(StepContext $step, string $token): ?string
    {
        if ($token === '') {
            return 'There is no reply token: only a LINE message can be answered, with the token it came with ({{ trigger.reply.token }}).';
        }

        $heard = $step->run->trigger_data['reply'] ?? null;
        $came = is_array($heard) && ($heard['token'] ?? null) === $token ? ($heard['at'] ?? $step->run->created_at) : null;
        $within = (int) $step->setting('within');

        if ($within > 0 && $came !== null && Carbon::parse($came)->diffInSeconds(now()) > $within) {
            return "The message it answers came more than {$within} seconds ago: its reply token is not tried after that.";
        }

        $key = 'workflows:line-reply:'.sha1($token);

        return Cache::add($key, $step->nodeRun->id, now()->addDay()) || Cache::get($key) === $step->nodeRun->id
            ? null
            : 'Its reply token was used already: LINE takes one once.';
    }

    /**
     * A reply that could not go: the run carries on down "not sent", where
     * a push can send it instead -- and with nothing that way, the step
     * fails, as a reply LINE refused always did.
     *
     * @throws StepFailed
     */
    private static function unsent(StepContext $step, string $to, string $why): StepResult
    {
        $follows = false;

        foreach ($step->run->version->definition['edges'] ?? [] as $edge) {
            $follows = $follows || ($edge['source'] === $step->node['id'] && $edge['sourceHandle'] === NodeTypes::UNSENT);
        }

        return $follows
            ? new StepResult(['to' => $to, 'sent' => false, 'why' => $why], NodeTypes::UNSENT)
            : throw new StepFailed("{$why} \"Push on LINE\" writes to someone at any time.");
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private static function toOne(StepContext $step): array
    {
        $to = trim($step->text('to'));

        if ($to === '') {
            throw new StepFailed('There is no one to send to.');
        }

        return [$to, ['to' => $to], ['to' => $to, 'sent' => true]];
    }

    /**
     * Several people: a list, or their ids written out with commas between.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private static function toMany(StepContext $step): array
    {
        $given = $step->template('to');
        $ids = is_array($given) ? $given : (preg_split('/[\s,]+/', (string) $given, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => is_scalar($id) ? trim((string) $id) : '', $ids), fn (string $id) => $id !== '')));

        if ($ids === [] || count($ids) > self::MOST_AT_ONCE) {
            throw new StepFailed($ids === [] ? 'There is no one to send to.' : 'LINE sends to at most '.self::MOST_AT_ONCE.' people at once, and there are '.count($ids).'.');
        }

        return [count($ids).' people', ['to' => $ids], ['to' => $ids, 'count' => count($ids), 'sent' => true]];
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
