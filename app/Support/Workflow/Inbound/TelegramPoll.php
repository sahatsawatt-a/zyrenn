<?php

namespace App\Support\Workflow\Inbound;

use App\Http\Controllers\Workflow\HookController;
use App\Models\Connection;
use App\Support\Workflow\Listeners;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asking Telegram for a bot's messages, for bots set to "poll": no public
 * address needed, and no signature to check -- the bot's own token asks.
 * The offset Telegram is given back says what has been taken, so each
 * message comes once and in order, even across restarts.
 */
final class TelegramPoll
{
    /**
     * Ask once for each bot something listens to.
     *
     * @return int how many messages came
     */
    public static function once(): int
    {
        $refs = Listeners::forKind('telegram')->pluck('key')->unique();
        $came = 0;

        foreach (Connection::where('service', 'telegram')->whereIn('ref_id', $refs)->get() as $bot) {
            if ($bot->setting('receive') === 'webhook') {
                continue;
            }

            try {
                $came += self::ask($bot);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $came;
    }

    private static function ask(Connection $bot): int
    {
        $offset = (int) ($bot->setting('offset') ?? 0);
        $response = Http::timeout(15)->get('https://api.telegram.org/bot'.$bot->secret('bot_token').'/getUpdates', [
            'offset' => $offset ?: null,
            'timeout' => 0,
            'allowed_updates' => json_encode(['message', 'edited_message', 'callback_query']),
        ]);

        $updates = $response->json('result');

        if (! $response->successful() || ! is_array($updates)) {
            return 0;
        }

        foreach ($updates as $update) {
            $envelope = Envelope::telegram($update);

            if ($envelope !== null) {
                HookController::telegramArrived($bot, $envelope);
            }

            $offset = max($offset, (int) $update['update_id'] + 1);
        }

        if ($updates !== []) {
            $bot->settings = [...($bot->settings ?? []), 'offset' => (string) $offset];
            $bot->save();
        }

        return count($updates);
    }
}
