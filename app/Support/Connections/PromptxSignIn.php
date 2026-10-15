<?php

namespace App\Support\Connections;

use App\Models\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signing a PromptX connection in. PromptX has no key to paste: its account
 * signs in with an email and a password, which is spent once, on saving, for
 * an account token and a refresh token -- the only two things kept. The
 * account token lasts 30 days; the refresh token renews it without the
 * password, and is itself replaced each time it is used.
 *
 * The account token does not reach flows: PromptxApi trades it for a flows
 * token, project by project, whenever a step runs.
 */
final class PromptxSignIn
{
    /** Where accounts sign in, and where the account token is good. */
    public const ACCOUNT = 'https://promptxai.com/zero-service/';

    /**
     * PromptX's own web page's client credential, which it sends with every
     * sign-in; not a secret of anyone's, it is in that page's script.
     */
    private const CLIENT = 'Y2xpZW50YXBwOjY3Tk9sWnBGeFVNZ2FrVE50WUVsckdQU0ZwUTVuS2M5ZXBYM1o1RUdxNmc9OkNybEkwSXYtN1Z0OG4yX3E0QkFfOXJZZzdZWVI4emJhajF5b0xFd045dlU9';

    /** How long before it runs out the account token is renewed. */
    private const RENEW_DAYS = 10;

    /**
     * Spend a password the form gave for the tokens it signs in to, on the
     * connection. Nothing is saved here. Without a password, what it holds stays.
     *
     * @throws ValidationException when PromptX does not take it
     */
    public static function spend(Connection $connection): void
    {
        $password = $connection->secret('password');

        if ($password === null) {
            return;
        }

        $answer = self::signIn((string) $connection->setting('login'), $password);

        if (is_string($answer)) {
            throw ValidationException::withMessages(['secrets.password' => $answer]);
        }

        $connection->secrets = $answer;
    }

    /**
     * Sign in with an email and a password.
     *
     * @return array{access_token: string, refresh_token: string}|string the tokens, or what went wrong
     */
    public static function signIn(string $login, string $password): array|string
    {
        try {
            $response = self::grant(['grant_type' => 'password', 'username' => $login, 'password' => $password, 'deviceID' => (string) Str::uuid()]);
        } catch (ConnectionException) {
            return 'Could not reach PromptX.';
        }

        // A wrong password is answered with a 500 that says so
        if (str_contains(strtolower((string) $response->json('error')), 'invalid username or password') || in_array($response->status(), [400, 401, 403], true)) {
            return 'PromptX did not take that email and password.';
        }

        return self::tokens($response) ?? "PromptX answered with {$response->status()}.";
    }

    /**
     * An account token good for some days yet: the one it holds, or a new
     * one for its refresh token, kept at once -- the refresh token it was
     * renewed with is no use again. A connection only being tried is not
     * renewed.
     *
     * @return string|null null when it is not signed in, or PromptX no longer takes it
     */
    public static function accountToken(Connection $connection): ?string
    {
        $token = $connection->secret('access_token');
        $until = $token === null ? null : self::until($token);

        if ($token !== null && ($until === null || $until->isAfter(now()->addDays(self::RENEW_DAYS)))) {
            return $token;
        }

        // Near its end it is still used while it lasts, should renewing fail
        return self::renew($connection) ?? ($until?->isFuture() ? $token : null);
    }

    /**
     * When a token runs out, as it says itself; null when it does not say.
     */
    public static function until(string $token): ?Carbon
    {
        $exp = self::claims($token)['exp'] ?? null;

        return is_numeric($exp) ? Carbon::createFromTimestamp((int) $exp) : null;
    }

    /**
     * What a token says of itself. Read, not checked: PromptX checks it.
     *
     * @return array<string, mixed>
     */
    public static function claims(string $token): array
    {
        $part = explode('.', $token)[1] ?? '';
        $claims = json_decode((string) base64_decode(strtr($part, '-_', '+/'), true), true);

        return is_array($claims) ? $claims : [];
    }

    /**
     * A new account token for the refresh token, made once among runs at the
     * same time: the row is held while it is asked for, and one renewed by
     * another meanwhile is taken as it is.
     */
    private static function renew(Connection $connection): ?string
    {
        // One only being tried is not kept, so neither would be the refresh token it was renewed with
        if (! $connection->exists) {
            return null;
        }

        return DB::transaction(function () use ($connection) {
            $held = Connection::query()->whereKey($connection->id)->lockForUpdate()->first();

            if ($held === null) {
                return null;
            }

            if ($held->secret('access_token') !== $connection->secret('access_token')) {
                $connection->secrets = $held->secrets;

                return $held->secret('access_token');
            }

            $token = self::renewed($held);

            if ($token !== null) {
                $held->save();
                $connection->secrets = $held->secrets;
            }

            return $token;
        });
    }

    /**
     * The tokens for the refresh token, on the connection; null when there is
     * none, or PromptX no longer takes it.
     */
    private static function renewed(Connection $connection): ?string
    {
        $refresh = $connection->secret('refresh_token');

        if ($refresh === null) {
            return null;
        }

        try {
            $tokens = self::tokens(self::grant(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]));
        } catch (ConnectionException) {
            return null;
        }

        if ($tokens === null) {
            return null;
        }

        $connection->secrets = $tokens;

        return $tokens['access_token'];
    }

    /**
     * @param  array<string, string>  $form
     */
    private static function grant(array $form): Response
    {
        return Http::asForm()->timeout(30)->acceptJson()
            ->post(self::ACCOUNT.'/pmtx/login', [...$form, 'basic_auth' => self::CLIENT]);
    }

    /**
     * @return array{access_token: string, refresh_token: string}|null
     */
    private static function tokens(Response $response): ?array
    {
        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');

        return $response->successful() && is_string($access) && $access !== '' && is_string($refresh) && $refresh !== ''
            ? ['access_token' => $access, 'refresh_token' => $refresh]
            : null;
    }
}
