<?php

namespace App\Support\Connections;

use App\Models\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Signing in as a profile of Instagram's or Threads': the two go the same
 * way, each at addresses of its own. The member says yes, the code is
 * traded for a token good for an hour, and that for one good for 60 days --
 * which, unlike LinkedIn's, can be made new unasked while it still holds,
 * so a connection in use never runs out (refresh).
 */
abstract class ProfileSignIn implements SignsIn
{
    /** What the service is called, its name among the connections' services, and its permissions. */
    protected const NAME = '';

    protected const SERVICE = '';

    public const SCOPES = '';

    /** Where the member says yes, where the code is traded, and where the rest is asked. */
    protected const AUTHORIZE = '';

    protected const TRADE = '';

    protected const HOST = '';

    protected const GRAPH = '';

    /** How its long-lived tokens are named in a call: ig, th. */
    protected const KIND = '';

    /** Which part of "me" names the profile. */
    protected const ID = 'id';

    /** How long before it runs out a token is made new. */
    private const RENEW_DAYS = 10;

    public static function address(Connection $connection, string $state): string
    {
        return static::AUTHORIZE.'?'.http_build_query([
            'client_id' => $connection->setting('client_id'),
            'redirect_uri' => SignIns::callback(static::SERVICE),
            'response_type' => 'code',
            'scope' => static::SCOPES,
            'state' => $state,
        ]);
    }

    public static function grant(Connection $connection, string $code): ?string
    {
        $name = static::NAME;

        try {
            $traded = Http::asForm()->timeout(15)->acceptJson()->post(static::TRADE, [
                'client_id' => $connection->setting('client_id'),
                'client_secret' => $connection->secret('client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => SignIns::callback(static::SERVICE),
                'code' => $code,
            ]);

            // Instagram wraps its answer in a list of one
            $short = $traded->json('data.0.access_token') ?? $traded->json('access_token');

            if (! $traded->successful() || ! is_string($short) || $short === '') {
                return "{$name} did not sign it in: ".Meta::said($traded);
            }

            $kept = Http::timeout(15)->acceptJson()->get(static::HOST.'/access_token', [
                'grant_type' => static::KIND.'_exchange_token',
                'client_secret' => $connection->secret('client_secret'),
                'access_token' => $short,
            ]);
            $token = $kept->json('access_token');

            if (! $kept->successful() || ! is_string($token) || $token === '') {
                return "{$name} signed it in, but would not keep it so: ".Meta::said($kept);
            }

            $who = Http::timeout(15)->acceptJson()->get(static::GRAPH.'/me', ['fields' => static::ID.',username', 'access_token' => $token]);
        } catch (ConnectionException) {
            return "Could not reach {$name}.";
        }

        $id = $who->json(static::ID);

        if (! $who->successful() || ! is_scalar($id) || (string) $id === '') {
            return "{$name} signed it in, but would not say who it is: ".Meta::said($who);
        }

        $connection->settings = [
            ...$connection->settings ?? [],
            'member' => (string) $id,
            'member_name' => (string) ($who->json('username') ?? ''),
            'expires_at' => now()->addSeconds((int) ($kept->json('expires_in') ?? 0))->toIso8601String(),
        ];
        $connection->secrets = [...$connection->secrets ?? [], 'access_token' => $token];

        return null;
    }

    public static function signedIn(Connection $connection): ?array
    {
        $until = $connection->setting('expires_at');

        return filled($connection->secret('access_token')) && filled($connection->setting('member')) && $until !== null
            ? ['name' => '@'.$connection->setting('member_name'), 'until' => Carbon::parse($until)]
            : null;
    }

    public static function refused(string $error, string $description): ?string
    {
        return $error === 'access_denied' ? 'Signing in to '.static::NAME.' was cancelled.' : null;
    }

    /**
     * Make a token new when it is near its end, and save it. Asked before a
     * connection is used; one the service will not renew is left as it is,
     * to run out and say so.
     */
    public static function refresh(Connection $connection): void
    {
        $until = static::signedIn($connection)['until'] ?? null;

        if ($until === null || $until->isPast() || $until->gt(now()->addDays(self::RENEW_DAYS)) || ! $connection->exists) {
            return;
        }

        try {
            $renewed = Http::timeout(15)->acceptJson()->get(static::HOST.'/refresh_access_token', [
                'grant_type' => static::KIND.'_refresh_token',
                'access_token' => $connection->secret('access_token'),
            ]);
        } catch (ConnectionException) {
            return;
        }

        if ($renewed->successful() && is_string($renewed->json('access_token'))) {
            $connection->settings = [...$connection->settings ?? [], 'expires_at' => now()->addSeconds((int) ($renewed->json('expires_in') ?? 0))->toIso8601String()];
            $connection->secrets = [...$connection->secrets ?? [], 'access_token' => $renewed->json('access_token')];
            $connection->save();
        }
    }
}
