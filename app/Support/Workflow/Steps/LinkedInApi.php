<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Support\Connections\LinkedInSignIn;
use App\Support\Workflow\References;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What every LinkedIn step shares: the member it acts as, and LinkedIn's
 * answer read the same way each time.
 *
 * A member is signed in for 60 days at a time (LinkedInSignIn), so a step
 * says so plainly when that has run out, rather than passing on LinkedIn's
 * 401. LinkedIn has no way to say "this is a repeat" either; it does refuse
 * a post whose words are the same as one just made.
 *
 * LinkedIn has two sets of calls. The older (v2) posts words, a link, a
 * picture or a video, comments, likes and deletes. The newer (rest) is
 * asked for by the month it was written against, which LinkedIn answers to
 * for a year: polls, several pictures, documents and reposts are only
 * there. A step uses the one that has its call.
 */
final class LinkedInApi
{
    /** The month the newer calls are written against. LinkedIn drops one after a year: move it on before then. */
    public const VERSION = '202609';

    /**
     * The step's member: one of the owner's LinkedIn connections, signed in.
     *
     * @throws StepFailed
     */
    public static function account(StepContext $step): Connection
    {
        $account = References::connection($step->owner(), (string) $step->setting('connection'), ['linkedin'])
            ?? throw new StepFailed('Its LinkedIn connection is gone, or is not this workflow\'s.');
        $until = LinkedInSignIn::until($account);

        if ($until === null || blank($account->setting('member'))) {
            throw new StepFailed("\"{$account->name}\" is not signed in to LinkedIn yet: sign in on the connection.");
        }

        if ($until->isPast()) {
            throw new StepFailed("The LinkedIn sign-in of \"{$account->name}\" ran out on {$until->toFormattedDayDateString()}: sign in again on the connection.");
        }

        return $account;
    }

    /**
     * The member as LinkedIn names them in what they write.
     */
    public static function author(Connection $account): string
    {
        return 'urn:li:person:'.$account->setting('member');
    }

    /**
     * A request as the member.
     */
    public static function as(Connection $account, int $timeout = 20): PendingRequest
    {
        return Http::timeout($timeout)->acceptJson()
            ->withToken($account->secret('access_token') ?? '')
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0']);
    }

    /**
     * A request as the member, to the newer calls.
     */
    public static function newer(Connection $account, int $timeout = 20): PendingRequest
    {
        return self::as($account, $timeout)->withHeaders(['LinkedIn-Version' => self::VERSION]);
    }

    /**
     * A call that asks, or sets something to what it would be anyway: made,
     * and made again if need be.
     *
     * @param  callable(): Response  $ask
     *
     * @throws StepFailed
     */
    public static function call(callable $ask): Response
    {
        try {
            return self::result($ask());
        } catch (ConnectionException) {
            throw new StepFailed('Could not reach LinkedIn.', retryable: true);
        }
    }

    /**
     * A call that leaves something new on LinkedIn, recorded so it is never
     * simply made twice.
     *
     * @param  callable(): Response  $send
     *
     * @throws StepFailed
     */
    public static function send(StepContext $step, string $target, callable $send): Response
    {
        return self::result($step->deliver('linkedin', $target, null, function () use ($send) {
            try {
                return $send();
            } catch (ConnectionException $e) {
                throw StepFailed::unreached($e, 'LinkedIn');
            }
        }));
    }

    /**
     * Put a file of the Drive's where LinkedIn said to, read as it goes
     * rather than held whole.
     *
     * @param  array<string, string>  $headers  what LinkedIn asked to be sent with it
     * @param  bool  $asMember  false where the place itself is the key
     *
     * @throws StepFailed
     */
    public static function put(Connection $account, string $to, DriveFile $file, array $headers = [], bool $asMember = true): void
    {
        $bytes = fopen(DriveSteps::path($file), 'r');

        if ($bytes === false) {
            throw new StepFailed("\"{$file->name}\" is in the Drive, but could not be read.");
        }

        // A video's place is its own key: LinkedIn takes it without the member's token, and with what it asked for
        $request = $asMember ? self::as($account, 300) : Http::timeout(300);

        self::call(fn () => $request->withHeaders($headers)->withBody(Utils::streamFor($bytes), $file->mime ?: 'application/octet-stream')->put($to));
    }

    /**
     * The post a setting names, as LinkedIn names it: its id as a step gave
     * it (urn:li:share:…), or its link, which carries the same.
     *
     * @throws StepFailed
     */
    public static function post(StepContext $step, string $setting = 'post'): string
    {
        $named = trim($step->text($setting));

        return match (true) {
            preg_match('/urn:li:(share|ugcPost|activity):\d+/', rawurldecode($named), $found) === 1 => $found[0],
            // As a post's own page is addressed: …/posts/ada_some-words-activity-7123…-AbCd
            preg_match('/-(share|ugcPost|activity)-(\d+)/', $named, $found) === 1 => "urn:li:{$found[1]}:{$found[2]}",
            $named === '' => throw new StepFailed('There is no post: its id, as "Post on LinkedIn" gives it, or its link.'),
            default => throw new StepFailed('That is not a LinkedIn post: its id (urn:li:share:…), or its link.'),
        };
    }

    /**
     * Words as the newer calls take them: they read some marks as their own
     * (a mention, a template), so each is written with a backslash before it
     * to stand for itself -- but for a # with a word after it, which is the
     * hashtag it looks like.
     */
    public static function little(string $text): string
    {
        return (string) preg_replace('/[\\\\|{}@\[\]()<>*_~]|#(?![\p{L}\p{N}])/u', '\\\\$0', $text);
    }

    /**
     * What a newer call's answer names the thing it made.
     */
    public static function made(Response $response): string
    {
        return (string) ($response->header('x-restli-id') ?: $response->json('id'));
    }

    /**
     * LinkedIn's answer, or why there is none worth having.
     *
     * @throws StepFailed
     */
    public static function result(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        throw match (true) {
            $response->status() === 401 => new StepFailed('LinkedIn no longer takes this sign-in: sign in again on the connection.'),
            $response->status() === 429 => new StepFailed('LinkedIn asked to slow down: a member posts through an app at most 150 times a day.', retryable: true, retryAfter: 60),
            default => new StepFailed('LinkedIn said: '.LinkedInSignIn::said($response), retryable: $response->serverError()),
        };
    }
}
