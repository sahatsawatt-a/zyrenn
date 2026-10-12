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
        return 'https://www.facebook.com/'.Meta::VERSION.'/dialog/oauth?'.http_build_query([
            'client_id' => $connection->setting('client_id'),
            'redirect_uri' => SignIns::callback(Services::FACEBOOK),
            'state' => $state,
            'response_type' => 'code',
            'scope' => self::SCOPES,
        ]);
    }

    public static function grant(Connection $connection, string $code): ?string
    {
        $app = ['client_id' => $connection->setting('client_id'), 'client_secret' => $connection->secret('client_secret')];

        try {
            $traded = Http::timeout(15)->acceptJson()->get(Meta::FACEBOOK.'/oauth/access_token', [
                ...$app, 'redirect_uri' => SignIns::callback(Services::FACEBOOK), 'code' => $code,
            ]);

            if (! $traded->successful() || ! is_string($traded->json('access_token'))) {
                return 'Facebook did not sign it in: '.Meta::said($traded);
            }

            // A sign-in good for two months, so the Page's token made from it does not run out
            $kept = Http::timeout(15)->acceptJson()->get(Meta::FACEBOOK.'/oauth/access_token', [
                ...$app, 'grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $traded->json('access_token'),
            ]);

            if (! $kept->successful() || ! is_string($kept->json('access_token'))) {
                return 'Facebook signed it in, but would not keep it so: '.Meta::said($kept);
            }

            $looked = Http::timeout(15)->acceptJson()->withToken($kept->json('access_token'))
                ->get(Meta::FACEBOOK.'/me/accounts', ['fields' => 'id,name,access_token', 'limit' => 100]);
        } catch (ConnectionException) {
            return 'Could not reach Facebook.';
        }

        if (! $looked->successful()) {
            return 'Facebook signed it in, but would not say which Pages it looks after: '.Meta::said($looked);
        }

        $pages = array_values(array_filter((array) $looked->json('data'), fn ($page) => is_array($page) && isset($page['id'], $page['access_token'])));
        $page = self::chosen($pages, trim((string) $connection->setting('page')));

        if (is_string($page)) {
            return $page;
        }

        $connection->settings = [...$connection->settings ?? [], 'page_id' => (string) $page['id'], 'page_name' => (string) ($page['name'] ?? '')];
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
