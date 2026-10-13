<?php

namespace App\Support\Workflow\Inbound;

use App\Models\Connection;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowListener;
use App\Support\Connections\InstagramSignIn;
use App\Support\Connections\Meta;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Telling Telegram and LINE where to send a bot's or channel's messages, once
 * a switched-on workflow listens for them. Telegram may instead be asked
 * for them (a connection set to "poll"), which needs no public address.
 * Meta's are told the address by hand, in the Meta app; an Instagram account
 * has to be subscribed to that app besides, which is done here.
 * Failing to tell them is reported, and never stops a workflow being
 * published: the connection page shows how the bot stands.
 */
final class Hooks
{
    /** What Instagram is asked to tell of, by what listens: a listener's kind => Meta's names for it. */
    private const INSTAGRAM_FIELDS = [
        'instagram' => ['comments', 'mentions'],
        'instagram_message' => ['messages', 'messaging_postbacks', 'message_reactions'],
    ];

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
            ->whereIn('kind', ['telegram', 'line', ...array_keys(self::INSTAGRAM_FIELDS)])
            ->pluck('key')
            ->unique();

        foreach ($workflow->owner()->connections()->whereIn('ref_id', $refs)->get() as $connection) {
            try {
                match ($connection->service) {
                    'telegram' => self::telegram($connection),
                    'line' => self::line($connection),
                    'instagram' => self::instagram($connection),
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
     * Instagram sends an account's comments, mentions and messages to the
     * Meta app's webhook once the account is subscribed to the app -- to
     * what its switched-on workflows listen for and no more, since messages
     * are a permission of their own that an older sign-in was not given.
     * Signed in by Facebook it is the Page that would be subscribed, which
     * is not done: such a connection starts nothing.
     */
    public static function instagram(Connection $connection): void
    {
        $token = $connection->secret('access_token');
        $fields = WorkflowListener::where('key', $connection->ref_id)
            ->whereIn('kind', array_keys(self::INSTAGRAM_FIELDS))
            ->pluck('kind')
            ->unique()
            ->sort()
            ->flatMap(fn (string $kind) => self::INSTAGRAM_FIELDS[$kind]);

        if ($token === null || $fields->isEmpty() || InstagramSignIn::byFacebook($connection)) {
            return;
        }

        Http::timeout(10)->withToken($token)
            ->post(Meta::INSTAGRAM.'/me/subscribed_apps', ['subscribed_fields' => $fields->implode(',')])
            ->throw();

        // What is sent names the account by either of its two ids: the other is kept, to know it by
        if ($connection->setting('scoped_id') === null) {
            $id = Http::timeout(10)->withToken($token)->get(Meta::INSTAGRAM.'/me', ['fields' => 'id'])->json('id');

            if (is_scalar($id) && (string) $id !== '') {
                $connection->settings = [...($connection->settings ?? []), 'scoped_id' => (string) $id];
                $connection->save();
            }
        }
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
