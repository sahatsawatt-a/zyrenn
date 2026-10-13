<?php

namespace App\Support\Connections;

use App\Models\Connection;

/**
 * Signing in as an Instagram professional account (a business's or a
 * creator's; Instagram gives an app nothing of a personal one), one of two
 * ways, as the connection says ("by"):
 *
 * - by Instagram's own login, as Threads does (ProfileSignIn);
 * - by Facebook, as someone who looks after the Page the account is linked
 *   to. Instagram then answers at Facebook's address, with the Page's token
 *   -- which does not run out -- and lets an app do there what it does not
 *   by its own login: take a post down.
 *
 * What a sign-in gave is good only the way it was given ("via"): a
 * connection set to sign in the other way is not signed in yet.
 */
final class InstagramSignIn extends ProfileSignIn
{
    protected const NAME = 'Instagram';

    protected const SERVICE = Services::INSTAGRAM;

    /** Who it is, posting, its comments, and its messages. */
    public const SCOPES = 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments,instagram_business_manage_messages';

    /** By Facebook: who it is and its posts, posting, its comments, taking a post down, and finding the Page it is linked to. */
    public const FACEBOOK_SCOPES = 'instagram_basic,instagram_content_publish,instagram_manage_comments,instagram_manage_contents,pages_show_list,pages_read_engagement,business_management';

    protected const AUTHORIZE = 'https://www.instagram.com/oauth/authorize';

    protected const TRADE = 'https://api.instagram.com/oauth/access_token';

    protected const HOST = 'https://graph.instagram.com';

    protected const GRAPH = Meta::INSTAGRAM;

    protected const KIND = 'ig';

    protected const ID = 'user_id';

    private const INSTAGRAM = 'instagram';

    private const FACEBOOK = 'facebook';

    /**
     * Whether the connection is set to sign in by Facebook.
     */
    public static function byFacebook(Connection $connection): bool
    {
        return $connection->setting('by') === self::FACEBOOK;
    }

    /**
     * Where Instagram answers for the connection: Facebook's address when it
     * signs in by Facebook, its own otherwise.
     */
    public static function graph(Connection $connection): string
    {
        return self::byFacebook($connection) ? Meta::FACEBOOK : Meta::INSTAGRAM;
    }

    /**
     * The account's own address there. Facebook's "me" would be the Page.
     */
    public static function account(Connection $connection): string
    {
        return self::byFacebook($connection) ? Meta::FACEBOOK.'/'.$connection->setting('member') : Meta::INSTAGRAM.'/me';
    }

    public static function address(Connection $connection, string $state): string
    {
        return self::byFacebook($connection)
            ? FacebookSignIn::dialog($connection, $state, self::SERVICE, self::FACEBOOK_SCOPES)
            : parent::address($connection, $state);
    }

    public static function grant(Connection $connection, string $code): ?string
    {
        if (! self::byFacebook($connection)) {
            $failed = parent::grant($connection, $code);

            if ($failed === null) {
                $connection->settings = [...$connection->settings ?? [], 'via' => self::INSTAGRAM];
            }

            return $failed;
        }

        // A Page names the account linked to it one of two ways, by how the two were joined
        $pages = FacebookSignIn::pages($connection, $code, self::SERVICE, 'id,name,access_token,instagram_business_account{id,username},connected_instagram_account{id,username}');

        if (is_string($pages)) {
            return $pages;
        }

        $pages = array_map(fn (array $page) => [...$page, 'instagram' => $page['instagram_business_account'] ?? $page['connected_instagram_account'] ?? null], $pages);
        $linked = array_values(array_filter($pages, fn (array $page) => filled($page['instagram']['id'] ?? null)));

        if (count($linked) !== 1) {
            return match (true) {
                $pages === [] => 'Facebook shared no Page with the app. Sign in again and, where Facebook asks what to share, choose the Page the Instagram account is linked to, and the account.',
                $linked === [] => 'Facebook shared '.self::named($pages, fn (array $page) => '"'.($page['name'] ?? $page['id']).'"', 'the Page', 'these Pages').', and showed no Instagram account linked to '.(count($pages) === 1 ? 'it' : 'any of them').'. Link the account to its Page on Facebook, then sign in again and share both the Page and the account.',
                default => 'Facebook shared '.count($linked).' Instagram accounts: '.self::named($linked, fn (array $page) => '@'.($page['instagram']['username'] ?? $page['instagram']['id'])).'. Sign in again and share only the one to post as.',
            };
        }

        $account = $linked[0]['instagram'];

        // The Page's token does not run out, so there is no day to keep
        $connection->settings = [
            ...array_diff_key($connection->settings ?? [], ['expires_at' => true]),
            'member' => (string) $account['id'],
            'member_name' => (string) ($account['username'] ?? ''),
            'via' => self::FACEBOOK,
            ...filled($linked[0]['signer'] ?? null) ? ['signer' => (string) $linked[0]['signer']] : [],
        ];
        $connection->secrets = [...$connection->secrets ?? [], 'access_token' => (string) $linked[0]['access_token']];

        return null;
    }

    /**
     * Pages or their accounts, said one after another -- with what they are, when told.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  callable(array<string, mixed>): string  $name
     */
    private static function named(array $pages, callable $name, string $one = '', string $several = ''): string
    {
        return ltrim((count($pages) === 1 ? $one : $several).' ').implode(', ', array_map($name, $pages));
    }

    public static function signedIn(Connection $connection): ?array
    {
        // What was given the other way is no sign-in of this one
        if (($connection->setting('via') ?: self::INSTAGRAM) !== ($connection->setting('by') ?: self::INSTAGRAM)) {
            return null;
        }

        if (! self::byFacebook($connection)) {
            return parent::signedIn($connection);
        }

        return filled($connection->secret('access_token')) && filled($connection->setting('member'))
            ? ['name' => '@'.$connection->setting('member_name'), 'until' => null]
            : null;
    }

    public static function refresh(Connection $connection): void
    {
        if (! self::byFacebook($connection)) {
            parent::refresh($connection);
        }
    }
}
