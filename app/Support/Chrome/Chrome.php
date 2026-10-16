<?php

namespace App\Support\Chrome;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The real browser on the server: the `chrome` service (docker/chrome). It
 * prints notes and boards to PDF, draws boards as PNG, pictures a note's
 * diagrams for a Markdown export, and does browser checks. Everything that
 * asks it for something goes through here: NotePdf, NoteExport, BoardRender,
 * BrowserCheck.
 *
 * It reaches the app at `services.chrome.origin` (`web` on the compose
 * network), not at APP_URL. A page it opens for someone is either a signed
 * link (a board) or the page as that person sees it, signed in with their
 * session cookie (a note).
 */
final class Chrome
{
    /**
     * Ask the browser for something: POST to one of its routes. A refusal is
     * thrown, with what the browser said.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws RuntimeException when it answers anything but 2xx
     */
    public static function post(string $path, array $body, int $timeout = 120): Response
    {
        $response = Http::timeout($timeout)->connectTimeout(10)
            ->post(rtrim((string) config('services.chrome.url'), '/').$path, $body);

        if (! $response->successful()) {
            $said = $response->json('error');

            throw new RuntimeException(is_string($said) && $said !== ''
                ? $said
                : 'The browser answered '.$response->status().': '.mb_substr(trim($response->body()), 0, 500));
        }

        return $response;
    }

    /**
     * A video made into H.264 MP4 by the container's ffmpeg: the one at
     * `$url` (a signed link to it in the Drive, given absolute: false).
     *
     * @throws RuntimeException when it could not be
     */
    public static function mp4(string $url): string
    {
        $bytes = self::post('/mp4', ['url' => self::at($url)], 900)->body();

        if (substr($bytes, 4, 4) !== 'ftyp') {
            throw new RuntimeException('What came back is not an MP4');
        }

        return $bytes;
    }

    /** An address in the app, as the browser reaches it: a path, given absolute: false. */
    public static function at(string $path): string
    {
        return rtrim((string) config('services.chrome.origin'), '/').$path;
    }

    /**
     * The cookies that sign the browser in as whoever sent `$sessionCookie` --
     * none without one. The cookie is set on the host the browser visits,
     * not APP_URL's.
     *
     * @return list<array{name: string, value: string, domain: string, path: string}>
     */
    public static function signedInAs(?string $sessionCookie): array
    {
        if ($sessionCookie === null || $sessionCookie === '') {
            return [];
        }

        return [[
            'name' => (string) config('session.cookie'),
            'value' => $sessionCookie,
            'domain' => (string) parse_url(self::at('/'), PHP_URL_HOST),
            'path' => '/',
        ]];
    }

    /**
     * Whether the browser ended up on the sign-in page: a session that did
     * not carry over is answered with it, which prints as a perfectly good
     * PDF of the wrong thing.
     */
    public static function signedOut(?string $finalUrl): bool
    {
        return preg_match('#/login(\?|$|/)#', (string) $finalUrl) === 1;
    }

    /**
     * The session cookie exactly as the browser sent it.
     *
     * $request->cookie() is no use: EncryptCookies has already swapped in the
     * decrypted session id, and the app only accepts the encrypted form back.
     * Nor is $_COOKIE, which PHP url-decodes -- turning the base64 `+` into a
     * space. So read the raw Cookie header and pass the value on untouched.
     */
    public static function sessionCookie(Request $request): ?string
    {
        $name = (string) config('session.cookie');

        foreach (explode(';', (string) $request->headers->get('cookie')) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);

            if ($key === $name && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
