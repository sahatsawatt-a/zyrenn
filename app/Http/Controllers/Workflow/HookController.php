<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Workflow\WorkflowListener;
use App\Models\Workflow\WorkflowRun;
use App\Support\Connections\Meta;
use App\Support\Connections\Services;
use App\Support\Workflow\Inbound\Envelope;
use App\Support\Workflow\Ingest;
use App\Support\Workflow\Listeners;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\NodeTypes;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use App\Support\Workflow\Steps\ChannelApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Sleep;
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

                return Envelope::wanted('telegram', $listener->settings['on'] ?? null, $envelope['type'])
                    && ($chats === 'any' || $chats === $envelope['chat']['kind'])
                    // A command is something written: a press or a join has none to match
                    && ($command === '' || ! in_array($envelope['type'], ['message', 'edited', 'channel_post'], true)
                        || preg_match('/^'.preg_quote($command, '/').'(@\w+)?(\s|$)/i', $envelope['text']) === 1);
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
                fn (WorkflowListener $listener) => Envelope::wanted('line', $listener->settings['on'] ?? null, $envelope['type'])
                    && in_array($listener->settings['chats'] ?? 'any', ['any', $envelope['chat']['kind']], true),
            );
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A WhatsApp business number's messages, by Meta's webhook: asked once,
     * when it is set up, to prove the address is the app's, and after that
     * signed with the Meta app's secret. One address takes everything the
     * Meta app is told of, so only what came to this connection's own
     * number is taken; that a message was delivered or read starts nothing.
     */
    public function whatsapp(Request $request, string $connection): JsonResponse|Response
    {
        $number = Connection::where('ref_id', $connection)->where('service', 'whatsapp')->first();

        if ($request->isMethod('GET')) {
            return self::asked($request, $number);
        }

        if ($number === null || ! self::fromMeta($request, $number->secret('app_secret'))) {
            return response()->json(['ok' => false], 403);
        }

        foreach ((array) $request->json('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                if (($change['field'] ?? null) !== 'messages' || (string) ($value['metadata']['phone_number_id'] ?? '') !== (string) $number->setting('phone_number_id')) {
                    continue;
                }

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $envelope = Envelope::whatsapp($value, $message);

                    Ingest::fire(
                        "whatsapp:{$number->ref_id}",
                        $envelope['id'] ?: hash('sha256', json_encode($message) ?: ''),
                        $envelope,
                        Listeners::for('whatsapp', $number->ref_id),
                        fn (WorkflowListener $listener) => Envelope::wanted('whatsapp', $listener->settings['on'] ?? null, $envelope['type']),
                    );
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * What happens on an Instagram professional account, by Meta's webhook:
     * a comment under one of its posts, the account named by someone else,
     * or something said to it in its messages. Asked and signed as
     * WhatsApp's is, with the secret the connection signs in with. One
     * address takes everything the Meta app is told of, so only this
     * account's is taken -- and nothing the account wrote itself, or the
     * answer a workflow gave would start it again.
     */
    public function instagram(Request $request, string $connection): JsonResponse|Response
    {
        $account = Connection::where('ref_id', $connection)->where('service', 'instagram')->first();

        if ($request->isMethod('GET')) {
            return self::asked($request, $account);
        }

        if ($account === null || ! self::fromMeta($request, $account->secret('client_secret'))) {
            return response()->json(['ok' => false], 403);
        }

        // Meta names the account by either of its two ids
        $ids = array_filter([(string) $account->setting('member'), (string) $account->setting('scoped_id')]);

        foreach ((array) $request->json('entry', []) as $entry) {
            if (! is_array($entry) || ! in_array((string) ($entry['id'] ?? ''), $ids, true)) {
                continue;
            }

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $envelope = is_array($change) && is_array($change['value'] ?? null)
                    ? Envelope::instagram((string) ($change['field'] ?? ''), $change['value'], $entry['time'] ?? null)
                    : null;

                if ($envelope === null || self::own($account, $envelope)) {
                    continue;
                }

                Ingest::fire(
                    "instagram:{$account->ref_id}",
                    "{$envelope['type']}:{$envelope['id']}",
                    $envelope,
                    Listeners::for('instagram', $account->ref_id),
                    fn (WorkflowListener $listener) => Envelope::wanted('instagram', $listener->settings['on'] ?? null, $envelope['type']),
                );
            }

            // Messages come in a list of their own -- or, from the Meta app's own test, as a change like the rest
            $said = [...(array) ($entry['messaging'] ?? []), ...array_column(array_filter((array) ($entry['changes'] ?? []), is_array(...)), 'value')];

            foreach ($said as $event) {
                $envelope = is_array($event) && isset($event['sender']) ? Envelope::instagramMessage($event) : null;

                if ($envelope === null || in_array($envelope['sender']['id'], $ids, true)) {
                    continue;
                }

                Ingest::fire(
                    "instagram:{$account->ref_id}",
                    // A heart put on, taken off and put on again is three things, told apart by when
                    "{$envelope['type']}:{$envelope['id']}".($envelope['type'] === 'reaction' ? ':'.($event['timestamp'] ?? '') : ''),
                    $envelope,
                    Listeners::for('instagram_message', $account->ref_id),
                    fn (WorkflowListener $listener) => Envelope::wanted('instagram_message', $listener->settings['on'] ?? null, $envelope['type']),
                );
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * What happens on a Threads profile, by Meta's webhook: a reply under
     * one of its posts, or a post of its own published or deleted. Asked
     * and signed as Instagram's is; a reply the profile wrote itself starts
     * nothing.
     */
    public function threads(Request $request, string $connection): JsonResponse|Response
    {
        $profile = Connection::where('ref_id', $connection)->where('service', 'threads')->first();

        if ($request->isMethod('GET')) {
            return self::asked($request, $profile);
        }

        if ($profile === null || ! self::fromMeta($request, $profile->secret('client_secret'))) {
            return response()->json(['ok' => false], 403);
        }

        $payload = $request->json()->all();
        $envelope = Envelope::threads($payload);
        // One address takes every profile the Meta app is told of: only this one's is taken
        $ours = ! isset($payload['target_id']) || (string) $payload['target_id'] === (string) $profile->setting('member');

        if ($envelope !== null && $ours && ! ($envelope['type'] === 'reply' && self::own($profile, $envelope))) {
            Ingest::fire(
                "threads:{$profile->ref_id}",
                "{$envelope['type']}:{$envelope['id']}",
                $envelope,
                Listeners::for('threads', $profile->ref_id),
                fn (WorkflowListener $listener) => Envelope::wanted('threads', $listener->settings['on'] ?? null, $envelope['type']),
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

        $run = null;
        Ingest::fire(
            "webhook:{$token}",
            is_scalar($own) && $own !== '' ? (string) $own : (string) Str::uuid(),
            $envelope,
            collect([$listener]),
            started: function (WorkflowRun $started) use (&$run) {
                $run = $started;
            },
        );

        if (($settings['answer'] ?? 'at_once') !== 'result' || $run === null) {
            return response()->json(['ok' => true], 202);
        }

        return $this->answer($run, max(1, min(NodeTypes::MOST_ANSWER_WAIT, (int) ($settings['wait'] ?? 25))));
    }

    /**
     * What a run started by a webhook gives its caller, once it has it: the
     * first "Answer the webhook" step done, or how the run went -- or, the
     * time up first, the run, still going.
     */
    private function answer(WorkflowRun $run, int $seconds): JsonResponse|Response
    {
        $answers = [];

        foreach ((array) ($run->version->definition['nodes'] ?? []) as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'http.respond') {
                $answers[] = (string) $node['key'];
            }
        }
        $until = now()->addSeconds($seconds);

        do {
            $answered = $answers === [] ? null : $run->steps()
                ->whereIn('node_key', $answers)
                ->where('status', NodeStatus::Succeeded->value)
                ->orderBy('id')
                ->first();

            if ($answered !== null) {
                $body = $answered->output['body'] ?? null;
                $status = (int) ($answered->output['status'] ?? 200);
                // JSON written out as text goes as JSON
                $body = is_string($body) && is_array($decoded = json_decode($body, true)) ? $decoded : $body;

                return is_array($body) || $body === null
                    ? response()->json($body ?? new \stdClass, $status)
                    : response((string) $body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
            }

            $run->refresh();

            if ($run->status !== RunStatus::Queued && $run->status !== RunStatus::Running) {
                return response()->json(array_filter([
                    'ok' => $run->status === RunStatus::Succeeded,
                    'run' => $run->ref_id,
                    'status' => $run->status->value,
                    'error' => $run->status === RunStatus::Failed ? $run->steps()->where('status', NodeStatus::Failed->value)->orderBy('id')->first()?->error['message'] ?? null : null,
                ], fn ($part) => $part !== null));
            }

            Sleep::usleep(200_000);
        } while (now()->lt($until));

        return response()->json(['ok' => true, 'run' => $run->ref_id, 'status' => $run->status->value], 202);
    }

    /**
     * A message from a platform of the user's own (a "Custom channel"), in
     * the app's own shape -- one, or several as {"messages": [...]} --
     * signed with the secret the two share. The same message sent twice,
     * by its own id, starts one run.
     */
    public function channel(Request $request, string $connection): JsonResponse
    {
        $channel = Connection::where('ref_id', $connection)->where('service', Services::CHANNEL)->first();

        if ($channel === null || ! ChannelApi::signedBy($request->getContent(), (string) $request->header(ChannelApi::HEADER), $channel)) {
            return response()->json(['ok' => false], 403);
        }

        $body = $request->json()->all();
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [$body];
        $taken = 0;

        foreach ($messages as $message) {
            $envelope = is_array($message) ? Envelope::channel($message, (string) $channel->setting('platform')) : null;

            if ($envelope === null) {
                continue;
            }

            $taken++;
            Ingest::fire(
                "channel:{$channel->ref_id}",
                $envelope['id'] ?: hash('sha256', json_encode($message) ?: ''),
                $envelope,
                Listeners::for('channel', $channel->ref_id),
            );
        }

        return $taken === 0 && $messages !== []
            ? response()->json(['ok' => false, 'error' => 'Each message needs chat.id, and text, files or a location.'], 422)
            : response()->json(['ok' => true, 'taken' => $taken]);
    }

    /**
     * Meta asking, once, when a webhook is set up in its app: only the
     * connection's own verify token is answered, with what Meta sent to be
     * given back.
     */
    private static function asked(Request $request, ?Connection $connection): JsonResponse|Response
    {
        return $connection !== null && $request->query('hub_mode') === 'subscribe' && hash_equals(Meta::verifyToken($connection), (string) $request->query('hub_verify_token'))
            ? response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain'])
            : response()->json(['ok' => false], 403);
    }

    /**
     * Whether Meta sent it: signed with the secret of the Meta app the connection is of.
     */
    private static function fromMeta(Request $request, ?string $secret): bool
    {
        return $secret !== null && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature-256'));
    }

    /**
     * Whether the account wrote it itself.
     *
     * @param  array<string, mixed>  $envelope
     */
    private static function own(Connection $account, array $envelope): bool
    {
        $name = mb_strtolower((string) ($envelope['sender']['name'] ?? ''));

        return $name !== '' && $name === mb_strtolower((string) $account->setting('member_name'));
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
