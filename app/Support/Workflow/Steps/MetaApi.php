<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\Meta;
use App\Support\Connections\ProfileSignIn;
use App\Support\Connections\SignIns;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What every step of Facebook's, Instagram's, Threads' and WhatsApp's
 * shares, the four being Meta's and answering alike: the connection a step
 * acts through, a call made as it, and Meta's answer read the same way each
 * time.
 *
 * None of them can be told "this is a repeat", so a call that leaves
 * something new is recorded (send) and never simply made twice; one that
 * only asks, or sets something to what it would be anyway, is simply made
 * (call).
 */
final class MetaApi
{
    /** Meta's own numbers for "too many, too fast". */
    private const BUSY = [4, 17, 32, 341, 613, 80001, 80002, 80004, 80006, 130429, 131048, 131056];

    /**
     * The step's connection: one of the owner's, of this service, and --
     * where the service is signed in to -- signed in still. A sign-in that
     * can be made new is, when it is near its end.
     *
     * @throws StepFailed
     */
    public static function account(StepContext $step, string $service): Connection
    {
        $account = References::connection($step->owner(), (string) $step->setting('connection'), [$service])
            ?? throw new StepFailed('Its connection is gone, or is not this workflow\'s.');
        $signIn = SignIns::for($service);

        if ($signIn === null) {
            return $account;
        }

        if (is_subclass_of($signIn, ProfileSignIn::class)) {
            $signIn::refresh($account);
        }

        $who = $signIn::signedIn($account);
        $until = $who['until'] ?? null;

        return match (true) {
            $who === null => throw new StepFailed("\"{$account->name}\" is not signed in to {$account->label()} yet: sign in on the connection."),
            $until?->isPast() === true => throw new StepFailed("The {$account->label()} sign-in of \"{$account->name}\" ran out on {$until->toFormattedDayDateString()}: sign in again on the connection."),
            default => $account,
        };
    }

    /**
     * A request carrying a token.
     */
    public static function as(?string $token, int $timeout = 20): PendingRequest
    {
        return Http::timeout($timeout)->acceptJson()->withToken((string) $token);
    }

    /**
     * A call that asks, or sets something to what it would be anyway.
     *
     * @param  callable(): Response  $ask
     *
     * @throws StepFailed
     */
    public static function call(string $service, callable $ask): Response
    {
        try {
            return self::result($ask(), $service);
        } catch (ConnectionException) {
            throw new StepFailed("Could not reach {$service}.", retryable: true);
        }
    }

    /**
     * A call that leaves something new, recorded so it is never simply made twice.
     *
     * @param  callable(): Response  $send
     *
     * @throws StepFailed
     */
    public static function send(StepContext $step, string $service, string $target, callable $send): Response
    {
        return self::result($step->deliver(strtolower($service), $target, null, function () use ($send, $service) {
            try {
                return $send();
            } catch (ConnectionException $e) {
                throw StepFailed::unreached($e, $service);
            }
        }), $service);
    }

    /**
     * Meta's answer, or why there is none worth having.
     *
     * @throws StepFailed
     */
    public static function result(Response $response, string $service): Response
    {
        $error = $response->json('error');

        if ($response->successful() && $error === null) {
            return $response;
        }

        $code = is_array($error) ? ($error['code'] ?? null) : null;

        throw match (true) {
            $response->status() === 401 || $code === 190 => new StepFailed("{$service} no longer takes this connection's sign-in or token: renew it on the connection."),
            $response->status() === 429 || in_array($code, self::BUSY, true) => new StepFailed("{$service} asked to slow down.", retryable: true, retryAfter: 60),
            // Outside the day after their last message, only a template may be sent
            $code === 131047 => new StepFailed('WhatsApp takes words of one\'s own only within 24 hours of the other\'s last message: "Send a template on WhatsApp" reaches them after that.'),
            default => new StepFailed("{$service} said: ".Meta::said($response), retryable: $response->serverError() || (is_array($error) && ($error['is_transient'] ?? false) === true)),
        };
    }

    /**
     * How many of something a step asks for, within what one call gives.
     */
    public static function count(StepContext $step, int $usual = 10, int $most = 100): int
    {
        return max(1, min($most, (int) ($step->setting('count') ?: $usual)));
    }

    /**
     * A setting that has to come to something.
     *
     * @throws StepFailed
     */
    public static function needs(StepContext $step, string $setting, string $what): string
    {
        $value = trim($step->text($setting));

        return $value !== '' ? $value : throw new StepFailed("There is no {$what}.");
    }

    /**
     * Something of the service's that a setting names by its id -- or by an
     * address that ends in it, as a step gives one out.
     *
     * @throws StepFailed
     */
    public static function thing(StepContext $step, string $setting, string $what): string
    {
        $named = self::needs($step, $setting, $what);
        $id = preg_match('#^https?://#i', $named) === 1 ? basename(rtrim((string) parse_url($named, PHP_URL_PATH), '/')) : $named;

        return preg_match('/^[A-Za-z0-9_.:-]+$/', $id) === 1 ? $id : throw new StepFailed("That is not the id of a {$what}, as a step gives it.");
    }
}
