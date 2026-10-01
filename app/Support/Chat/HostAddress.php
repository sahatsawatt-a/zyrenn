<?php

namespace App\Support\Chat;

/**
 * Where "localhost" is, for a connection the user typed.
 *
 * Someone who writes http://localhost:11434 means the machine they run
 * ZyrenN on. From inside a container that is not what the word means -- it
 * means the container -- so there it is read as the machine the container
 * runs on, which is the container's way out: its default gateway.
 * CHAT_LOCALHOST_AS says what to use instead (a name or an address), or
 * "off" to take the word literally.
 */
class HostAddress
{
    /**
     * The address to call for $url: itself, unless it names this machine and
     * the app is in a container.
     */
    public static function reachable(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true)) {
            return $url;
        }

        $instead = config('services.chat.localhost_as');

        if ($instead === 'off') {
            return $url;
        }

        if (! is_string($instead) || $instead === '') {
            $instead = file_exists('/.dockerenv') ? self::gateway() : null;
        }

        // Only the host is swapped, wherever it stands after the scheme and any user:password@
        return $instead === null ? $url : (string) preg_replace('~^([a-z]+://(?:[^/@]*@)?)'.preg_quote($host, '~').'~i', '${1}'.$instead, $url, 1);
    }

    /**
     * The container's way out to the machine it runs on, read from its
     * routing table, or null when it has none.
     */
    public static function gateway(?string $routes = null): ?string
    {
        $routes ??= @file_get_contents('/proc/net/route') ?: '';

        foreach (explode("\n", $routes) as $line) {
            // Interface, destination, gateway ... as hex, the address backwards
            $columns = preg_split('/\s+/', trim($line));

            if (($columns[1] ?? null) === '00000000' && preg_match('/^[0-9A-F]{8}$/i', $columns[2] ?? '')) {
                return inet_ntop(pack('V', hexdec($columns[2]))) ?: null;
            }
        }

        return null;
    }
}
