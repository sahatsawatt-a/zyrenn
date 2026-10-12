<?php

namespace App\Support\Connections;

use App\Models\Connection;
use Illuminate\Support\Carbon;

/**
 * A service whose member signs in on its own pages rather than handing out
 * a token to paste: where they are sent, what comes back, and how the
 * connection then stands. Nothing here saves; the controller does.
 */
interface SignsIn
{
    /**
     * Where the member is sent to say yes.
     */
    public static function address(Connection $connection, string $state): string;

    /**
     * Trade the code the service sent back for what it gives, and put that
     * on the connection.
     *
     * @return string|null what went wrong, in words for the page; null when it is signed in
     */
    public static function grant(Connection $connection, string $code): ?string;

    /**
     * Who the connection is signed in as, and until when if that runs out;
     * null when it is not signed in.
     *
     * @return array{name: string, until: Carbon|null}|null
     */
    public static function signedIn(Connection $connection): ?array;

    /**
     * Why the service sent its member back with nothing, where it says in a
     * way worth putting better; null for what it said itself.
     */
    public static function refused(string $error, string $description): ?string;
}
