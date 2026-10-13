<?php

namespace App\Support;

/**
 * Whose the app is, as the LICENSE says it: said once here, for the foot of
 * the pages anyone may read and for the head of every page.
 */
final class Copyright
{
    public static function holder(): string
    {
        return (string) config('app.copyright.holder');
    }

    /**
     * The notice: the year it was first published, every year since once
     * there is one, and who holds it.
     */
    public static function line(): string
    {
        $since = (int) config('app.copyright.since');
        $years = now()->year > $since ? "{$since}–".now()->year : (string) $since;

        return "© {$years} ".self::holder().'. All rights reserved.';
    }
}
