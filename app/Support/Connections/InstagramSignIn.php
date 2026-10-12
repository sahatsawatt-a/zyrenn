<?php

namespace App\Support\Connections;

/**
 * Signing in as an Instagram professional account (a business's or a
 * creator's; Instagram gives an app nothing of a personal one), by
 * Instagram's own login.
 */
final class InstagramSignIn extends ProfileSignIn
{
    protected const NAME = 'Instagram';

    protected const SERVICE = Services::INSTAGRAM;

    /** Who it is, posting, and its comments. */
    public const SCOPES = 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments';

    protected const AUTHORIZE = 'https://www.instagram.com/oauth/authorize';

    protected const TRADE = 'https://api.instagram.com/oauth/access_token';

    protected const HOST = 'https://graph.instagram.com';

    protected const GRAPH = Meta::INSTAGRAM;

    protected const KIND = 'ig';

    protected const ID = 'user_id';
}
