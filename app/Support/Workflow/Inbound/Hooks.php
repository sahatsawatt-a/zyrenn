<?php

namespace App\Support\Workflow\Inbound;

use App\Models\Connection;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowListener;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Telling Telegram and LINE where to send a bot's or channel's messages, once
 * a switched-on workflow listens for them. Telegram may instead be asked
 * for them (a connection set to "poll"), which needs no public address.
 * Failing to tell them is reported, and never stops a workflow being
 * published: the connection page shows how the bot stands.
 */
final class Hooks
{
    /**
     * The public address of a hook, or null when none is set up.
     */
    public static function url(string $path): ?string
    {
        $base = rtrim((string) config('services.workflows.public_url'), '/');

        return $base === '' ? null : "{$base}/hooks/{$path}";
    }

    /**
     * Point the bots and channels a workflow listens to at their hooks.
     */
    public static function register(Workflow $workflow): void
    {
        $refs = WorkflowListener::where('workflow_id', $workflow->id)
            ->whereIn('kind', ['telegram', 'line'])
            ->pluck('key')
            ->unique();

        foreach ($workflow->owner()->connections()->whereIn('ref_id', $refs)->get() as $connection) {
            try {
                match ($connection->service) {
                    'telegram' => self::telegram($connection),
                    'line' => self::line($connection),
                    default => null,
                };
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Telegram sends a bot's messages to its hook, signed with a secret of
     * this connection's own -- or, for a bot that is asked, sends none.
     */
    public static function telegram(Connection $connection): void
    {
        $token = $connection->secret('bot_token');
        $url = self::url("telegram/{$connection->ref_id}");

        if ($token === null) {
            return;
        }

        if ($connection->setting('receive') !== 'webhook' || $url === null) {
            // Asked for its messages instead; Telegram allows one or the other
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/deleteWebhook");

            return;
        }

        $secret = $connection->secret('webhook_secret');

        if ($secret === null) {
            $secret = Str::random(48);
            $connection->secrets = [...($connection->secrets ?? []), 'webhook_secret' => $secret];
            $connection->save();
        }

        Http::timeout(10)->post("https://api.telegram.org/bot{$token}/setWebhook", [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => Envelope::TELEGRAM_UPDATES,
        ])->throw();
    }

    /**
     * LINE sends a channel's messages to its hook; it signs them with the
     * channel secret, which it already has.
     */
    public static function line(Connection $connection): void
    {
        $url = self::url("line/{$connection->ref_id}");
        $token = $connection->secret('channel_access_token');

        if ($url === null || $token === null) {
            return;
        }

        Http::timeout(10)->withToken($token)
            ->put('https://api.line.me/v2/bot/channel/webhook/endpoint', ['endpoint' => $url])
            ->throw();
    }

    /**
     * How a bot's hook stands, as Telegram says: where it sends, how many
     * messages wait, and the last error. Null when it can't be asked.
     *
     * @return array<string, mixed>|null
     */
    public static function telegramInfo(Connection $connection): ?array
    {
        try {
            $info = Http::timeout(10)->get('https://api.telegram.org/bot'.$connection->secret('bot_token').'/getWebhookInfo')->json('result');
        } catch (ConnectionException) {
            return null;
        }

        return is_array($info) ? $info : null;
    }
}
