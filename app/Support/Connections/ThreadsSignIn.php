<?php

namespace App\Support\Connections;

/**
 * Signing in as a Threads profile -- anyone's, not only a business's.
 */
final class ThreadsSignIn extends ProfileSignIn
{
    protected const NAME = 'Threads';

    protected const SERVICE = Services::THREADS;

    /** Who it is, posting, answering and reading answers, and deleting. */
    public const SCOPES = 'threads_basic,threads_content_publish,threads_manage_replies,threads_read_replies,threads_delete';

    protected const AUTHORIZE = 'https://threads.com/oauth/authorize';

    protected const TRADE = 'https://graph.threads.net/oauth/access_token';

    protected const HOST = 'https://graph.threads.net';

    protected const GRAPH = Meta::THREADS;

    protected const KIND = 'th';
}
