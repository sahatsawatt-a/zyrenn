<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * LINE: who wrote -- a LINE event carries only ids, where Telegram's carries
 * names. Asks LINE for the person (as a member of the group or room the
 * message came in, else their own profile) and, in a group, the group's
 * name. What LINE answers is kept an hour, so a chat's every message doesn't
 * ask again. Its own step, so when LINE won't say, the run shows why.
 */
final class LineProfile implements Step
{
    private const KEPT_FOR = 3600;

    public function run(StepContext $step): StepResult
    {
        $channel = References::connectionFor($step->owner(), (string) $step->setting('connection'), ['line'], $step->run->trigger_data)
            ?? throw new StepFailed($step->setting('connection') === References::LINKED
                ? 'This run did not start from a chat linked to a LINE channel.'
                : 'Its channel is gone, or is not this workflow\'s.');
        $user = trim($step->text('user'));
        $chat = trim($step->text('chat'));

        if ($user === '') {
            throw new StepFailed('Who to ask about came to nothing: a LINE user id, like {{ trigger.sender.id }}.');
        }

        $profile = self::ask($channel, match (true) {
            str_starts_with($chat, 'C') => "group/{$chat}/member/{$user}",
            str_starts_with($chat, 'R') => "room/{$chat}/member/{$user}",
            default => "profile/{$user}",
        });
        $group = str_starts_with($chat, 'C') ? self::ask($channel, "group/{$chat}/summary") : null;

        return new StepResult([
            'id' => $user,
            'name' => $profile['displayName'] ?? null,
            'picture' => $profile['pictureUrl'] ?? null,
            'status' => $profile['statusMessage'] ?? null,
            'language' => $profile['language'] ?? null,
            'group' => $group['groupName'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function ask(Connection $channel, string $path): array
    {
        $key = "workflows:line:{$channel->id}:{$path}";

        if (is_array($kept = Cache::get($key))) {
            return $kept;
        }

        try {
            $response = Http::timeout(10)
                ->withToken((string) $channel->secret('channel_access_token'))
                ->get("https://api.line.me/v2/bot/{$path}");
        } catch (ConnectionException $e) {
            throw StepFailed::unreached($e, 'LINE');
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new StepFailed("LINE is busy ({$response->status()}).", retryable: true, retryAfter: 30);
        }

        if (! $response->successful()) {
            $why = $response->json('message') ?? "{$response->status()}.";

            throw new StepFailed($response->status() === 404
                ? "LINE doesn't know this one, or won't say: {$why} A person must have added the channel as a friend, or be in the group with it."
                : "LINE said: {$why}");
        }

        $found = is_array($response->json()) ? $response->json() : [];
        Cache::put($key, $found, self::KEPT_FOR);

        return $found;
    }
}
