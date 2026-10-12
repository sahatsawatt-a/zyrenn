<?php

namespace App\Support\Connections;

use App\Models\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Signing a LinkedIn connection in. LinkedIn gives no token to paste: its
 * member is sent to LinkedIn with the app's client id, says yes there, and
 * comes back with a code that is traded -- with the client secret -- for a
 * token to post as them.
 *
 * The token is good for 60 days and, for an app of one's own, can't be
 * renewed unasked: the member signs in again, which LinkedIn passes without
 * a question while they are still signed in there.
 */
final class LinkedInSignIn implements SignsIn
{
    /** Who they are (OpenID Connect), and posting as them (Share on LinkedIn). */
    public const SCOPES = 'openid profile w_member_social';

    /**
     * Where LinkedIn sends its member back (SignIns::callback).
     */
    public static function callback(): string
    {
        return SignIns::callback(Services::LINKEDIN);
    }

    /**
     * Where the member is sent to say yes.
     */
    public static function address(Connection $connection, string $state): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $connection->setting('client_id'),
            'redirect_uri' => self::callback(),
            'state' => $state,
            'scope' => self::SCOPES,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Trade the code LinkedIn sent back for a token, and put it on the
     * connection with who it is of and until when. Nothing is saved here.
     *
     * @return string|null what went wrong, in words for the page; null when it is signed in
     */
    public static function grant(Connection $connection, string $code): ?string
    {
        try {
            $traded = Http::asForm()->timeout(15)->acceptJson()->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => self::callback(),
                'client_id' => $connection->setting('client_id'),
                'client_secret' => $connection->secret('client_secret'),
            ]);

            $token = $traded->json('access_token');

            if (! $traded->successful() || ! is_string($token) || $token === '') {
                return 'LinkedIn did not sign it in: '.self::said($traded);
            }

            $who = self::who($token);
        } catch (ConnectionException) {
            return 'Could not reach LinkedIn.';
        }

        $member = $who->json('sub');

        if (! $who->successful() || ! is_string($member) || $member === '') {
            return 'LinkedIn signed it in, but would not say who it is: '.self::said($who);
        }

        $connection->settings = [
            ...$connection->settings ?? [],
            'member' => $member,
            'member_name' => (string) ($who->json('name') ?? ''),
            'expires_at' => now()->addSeconds((int) ($traded->json('expires_in') ?? 0))->toIso8601String(),
        ];
        $connection->secrets = [...$connection->secrets ?? [], 'access_token' => $token];

        return null;
    }

    /**
     * Who a token is of, asked of LinkedIn.
     *
     * @throws ConnectionException
     */
    public static function who(string $token): Response
    {
        return Http::withoutRedirecting()->timeout(10)->acceptJson()->withToken($token)
            ->get('https://api.linkedin.com/v2/userinfo');
    }

    public static function signedIn(Connection $connection): ?array
    {
        $until = self::until($connection);

        return $until === null ? null : ['name' => (string) $connection->setting('member_name'), 'until' => $until];
    }

    public static function refused(string $error, string $description): ?string
    {
        return match (true) {
            in_array($error, ['user_cancelled_login', 'user_cancelled_authorize'], true) => 'Signing in to LinkedIn was cancelled.',
            $error === 'unauthorized_scope_error' => 'LinkedIn does not let this app post yet: add the products "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect" on the app\'s Products page, then sign in again.',
            default => null,
        };
    }

    /**
     * When the sign-in runs out, or null when it is not signed in.
     */
    public static function until(Connection $connection): ?Carbon
    {
        $until = $connection->setting('expires_at');

        return filled($connection->secret('access_token')) && $until !== null ? Carbon::parse($until) : null;
    }

    /**
     * What LinkedIn answered, as far as it says.
     */
    public static function said(Response $response): string
    {
        $said = $response->json('error_description') ?? $response->json('message') ?? $response->json('error');

        return is_string($said) && $said !== '' ? rtrim($said, '.').'.' : "it answered with {$response->status()}.";
    }
}
