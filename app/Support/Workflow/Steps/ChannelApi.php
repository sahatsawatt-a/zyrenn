<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;

/**
 * A platform of the user's own, reached as a "Custom channel": what it
 * sends and what it is sent are signed with the secret the two share -- an
 * HMAC-SHA256 of the body, in hex, after "sha256=", in one header -- so
 * each side knows the other's from anyone else's.
 */
final class ChannelApi
{
    public const HEADER = 'X-Zyrenn-Signature';

    public static function signature(string $body, Connection $channel): string
    {
        return 'sha256='.hash_hmac('sha256', $body, (string) $channel->secret('secret'));
    }

    /**
     * Whether a body came signed with the channel's secret.
     */
    public static function signedBy(string $body, string $given, Connection $channel): bool
    {
        return filled($channel->secret('secret')) && hash_equals(self::signature($body, $channel), $given);
    }
}
