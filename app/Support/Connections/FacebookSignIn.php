<?php

namespace App\Support\Connections;

use App\Models\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Signing a Facebook connection in. Facebook lets an app post only as a
 * Page, never as a person: someone who looks after the Page signs in, and
 * what is kept is the Page's own token -- which, made from a long-lived
 * sign-in, does not run out until its maker's password changes or the app is
 * taken off.
 */
final class FacebookSignIn implements SignsIn
{
    /** Finding the Pages, posting as one, answering under its posts, and reading what people wrote there. */
    public const SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement,pages_read_user_content';

    public static function address(Connection $connection, string $state): string
    {
        return self::dialog($connection, $state, Services::FACEBOOK, self::SCOPES);
    }

    /**
     * Facebook's own page for saying yes, for the service that asks: an
     * Instagram connection may sign in here too (InstagramSignIn).
     */
    public static function dialog(Connection $connection, string $state, string $service, string $scopes): string
    {
        return 'https://www.facebook.com/'.Meta::VERSION.'/dialog/oauth?'.http_build_query([
            'client_id' => $connection->setting('client_id'),
            'redirect_uri' => SignIns::callback($service),
            'state' => $state,
            'response_type' => 'code',
            'scope' => $scopes,
        ]);
    }

    public static function grant(Connection $connection, string $code): ?string
    {
        $pages = self::pages($connection, $code, Services::FACEBOOK, 'id,name,access_token');

        if (is_string($pages)) {
            return $pages;
        }

        $page = self::chosen($pages, trim((string) $connection->setting('page')));

        if (is_string($page)) {
            return $page;
        }

        $connection->settings = [...$connection->settings ?? [], 'page_id' => (string) $page['id'], 'page_name' => (string) ($page['name'] ?? ''), ...filled($page['signer'] ?? null) ? ['signer' => (string) $page['signer']] : []];
        $connection->secrets = [...$connection->secrets ?? [], 'page_token' => (string) $page['access_token']];

        return null;
    }

    public static function signedIn(Connection $connection): ?array
    {
        return filled($connection->secret('page_token')) && filled($connection->setting('page_id'))
            ? ['name' => (string) $connection->setting('page_name'), 'until' => null]
            : null;
    }

    public static function refused(string $error, string $description): ?string
    {
        return $error === 'access_denied' ? 'Signing in to Facebook was cancelled.' : null;
    }

    /**
     * The Pages whoever signed in looks after and shared with the app, each
     * with its own token: the code is traded for a sign-in, that for one
     * good for two months, and the Pages' tokens made from it do not run out.
     *
     * @return list<array<string, mixed>>|string the Pages, or what went wrong
     */
    public static function pages(Connection $connection, string $code, string $service, string $fields): array|string
    {
        $app = ['client_id' => $connection->setting('client_id'), 'client_secret' => $connection->secret('client_secret')];

        try {
            $traded = Http::timeout(15)->acceptJson()->get(Meta::FACEBOOK.'/oauth/access_token', [
                ...$app, 'redirect_uri' => SignIns::callback($service), 'code' => $code,
            ]);

            if (! $traded->successful() || ! is_string($traded->json('access_token'))) {
                return 'Facebook did not sign it in: '.Meta::said($traded);
            }

            $kept = Http::timeout(15)->acceptJson()->get(Meta::FACEBOOK.'/oauth/access_token', [
                ...$app, 'grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $traded->json('access_token'),
            ]);

            if (! $kept->successful() || ! is_string($kept->json('access_token'))) {
                return 'Facebook signed it in, but would not keep it so: '.Meta::said($kept);
            }

            $looked = Http::timeout(15)->acceptJson()->withToken($kept->json('access_token'))
                ->get(Meta::FACEBOOK.'/me/accounts', ['fields' => $fields, 'limit' => 100]);
        } catch (ConnectionException) {
            return 'Could not reach Facebook.';
        }

        if (! $looked->successful()) {
            return 'Facebook signed it in, but would not say which Pages it looks after: '.Meta::said($looked);
        }

        // Who signed in, by the id Facebook gives this app for them: how word from Facebook that they took the app off is matched (Withdrawal)
        $signer = rescue(fn () => Http::timeout(15)->acceptJson()->withToken($kept->json('access_token'))->get(Meta::FACEBOOK.'/me', ['fields' => 'id'])->json('id'), null, false);
        $pages = array_filter((array) $looked->json('data'), fn ($page) => is_array($page) && isset($page['id'], $page['access_token']));

        return array_values(array_map(fn (array $page) => [...$page, 'signer' => is_scalar($signer) ? (string) $signer : ''], $pages));
    }

    /**
     * The Page to post as, of those the member looks after: the one the
     * connection names, or the only one there is.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>|string the Page, or why none could be taken
     */
    private static function chosen(array $pages, string $named): array|string
    {
        $names = implode(', ', array_map(fn (array $page) => '"'.($page['name'] ?? $page['id']).'"', $pages));

        if ($pages === []) {
            return 'This Facebook account looks after no Page, or shared none with the app. An app posts only as a Page: sign in again and choose one.';
        }

        if ($named === '') {
            return count($pages) === 1
                ? $pages[0]
                : 'This account looks after '.count($pages)." Pages: {$names}. Write the one to post as in \"Page\" on the connection, then sign in again.";
        }

        foreach ($pages as $page) {
            if ((string) $page['id'] === $named || mb_strtolower((string) ($page['name'] ?? '')) === mb_strtolower($named)) {
                return $page;
            }
        }

        return "There is no Page \"{$named}\" among those shared with the app: {$names}.";
    }
}
