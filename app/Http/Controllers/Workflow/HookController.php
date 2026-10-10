<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Workflow\WorkflowListener;
use App\Support\Workflow\Inbound\Envelope;
use App\Support\Workflow\Ingest;
use App\Support\Workflow\Listeners;
use App\Support\Workflow\References;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Where outside services send what starts workflows. Each request is
 * checked to come from who it says, kept, and answered at once; the runs it
 * starts are for the workers. A sender is answered "ok" even when nothing
 * listens -- one answered with an error sends it again, and holds back what
 * comes after.
 */
class HookController extends Controller
{
    /**
     * A Telegram bot's update, signed with the secret given when its hook was set.
     */
    public function telegram(Request $request, string $connection): JsonResponse
    {
        $bot = Connection::where('ref_id', $connection)->where('service', 'telegram')->first();
        $secret = $bot?->secret('webhook_secret');

        if ($bot === null || $secret === null || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['ok' => false], 403);
        }

        $update = $request->json()->all();
        $envelope = Envelope::telegram($update);

        if ($envelope !== null && isset($update['update_id'])) {
            self::telegramArrived($bot, $envelope);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * An update for a bot, from its hook or from asking for it: kept, and
     * runs started for the workflows listening -- those whose filters take it.
     *
     * @param  array<string, mixed>  $envelope
     */
    public static function telegramArrived(Connection $bot, array $envelope): int
    {
        return Ingest::fire(
            "telegram:{$bot->ref_id}",
            $envelope['id'],
            $envelope,
            Listeners::for('telegram', $bot->ref_id),
            function (WorkflowListener $listener) use ($envelope) {
                $chats = $listener->settings['chats'] ?? 'any';
                $command = trim((string) ($listener->settings['command'] ?? ''));

                return ($chats === 'any' || $chats === $envelope['chat']['kind'])
                    && ($command === '' || preg_match('/^'.preg_quote($command, '/').'(@\w+)?(\s|$)/i', $envelope['text']) === 1);
            },
        );
    }

    /**
     * A LINE channel's events, signed with its channel secret.
     */
    public function line(Request $request, string $connection): JsonResponse
    {
        $channel = Connection::where('ref_id', $connection)->where('service', 'line')->first();
        $secret = $channel?->secret('channel_secret');
        $signed = base64_encode(hash_hmac('sha256', $request->getContent(), (string) $secret, true));

        if ($channel === null || $secret === null || ! hash_equals($signed, (string) $request->header('X-Line-Signature'))) {
            return response()->json(['ok' => false], 403);
        }

        // LINE's own "Verify" sends no events at all
        foreach ((array) $request->json('events', []) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $envelope = Envelope::line($event);

            Ingest::fire(
                "line:{$channel->ref_id}",
                $envelope['id'] ?: hash('sha256', json_encode($event) ?: ''),
                $envelope,
                Listeners::for('line', $channel->ref_id),
                fn (WorkflowListener $listener) => in_array($listener->settings['chats'] ?? 'any', ['any', $envelope['chat']['kind']], true),
            );
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A request to a workflow's own webhook address. Answers the setup
     * checks some senders make first, and checks a signature when told to.
     */
    public function webhook(Request $request, string $token): JsonResponse|Response
    {
        $listener = Listeners::for('webhook', $token)->first();

        if ($listener === null) {
            return response()->json(['ok' => false, 'message' => 'No workflow listens here.'], 404);
        }

        $settings = $listener->settings ?? [];

        // Setting it up: the sender checks the address answers as it should
        if (($settings['handshake'] ?? 'none') === 'whatsapp' && $request->isMethod('GET') && $request->query('hub_mode') === 'subscribe') {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        if (($settings['handshake'] ?? 'none') === 'slack' && $request->json('type') === 'url_verification') {
            return response()->json(['challenge' => $request->json('challenge')]);
        }

        if (($settings['verify'] ?? 'none') === 'hmac' && ! $this->signed($request, $listener)) {
            return response()->json(['ok' => false, 'message' => 'The signature does not match.'], 401);
        }

        $envelope = Envelope::webhook($request);
        $own = filled($settings['dedupe'] ?? null) && is_array($envelope['body'])
            ? data_get($envelope['body'], (string) $settings['dedupe'])
            : null;

        Ingest::fire(
            "webhook:{$token}",
            is_scalar($own) && $own !== '' ? (string) $own : (string) Str::uuid(),
            $envelope,
            collect([$listener]),
        );

        return response()->json(['ok' => true], 202);
    }

    /**
     * Whether the request carries the signature its sender makes with the
     * secret both share: an HMAC-SHA256 of the body as it came.
     */
    private function signed(Request $request, WorkflowListener $listener): bool
    {
        $settings = $listener->settings ?? [];
        $secret = References::connection($listener->workflow->owner(), (string) ($settings['secret'] ?? ''), ['webhook_secret'])?->secret('secret');

        if ($secret === null) {
            return false;
        }

        $mac = hash_hmac('sha256', $request->getContent(), $secret, true);
        $expected = ($settings['encoding'] ?? 'hex') === 'base64' ? base64_encode($mac) : bin2hex($mac);
        $given = (string) $request->header((string) ($settings['header'] ?? 'X-Hub-Signature-256'));
        $prefix = (string) ($settings['prefix'] ?? '');

        if ($prefix !== '' && str_starts_with($given, $prefix)) {
            $given = substr($given, strlen($prefix));
        }

        return hash_equals($expected, $given);
    }
}
