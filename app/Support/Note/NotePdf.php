<?php

namespace App\Support\Note;

use App\Models\Note\Note;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A note as a PDF, printed by a real browser looking at the real note page.
 *
 * Nothing re-renders the note: the `chrome` service (docker/chrome) opens the
 * note's own page and prints what it drew, so diagrams, formulae and boards are
 * the real ones. It signs in as the caller, with their session cookie, rather
 * than through a route of its own -- a note is only ever shown to its owner.
 */
class NotePdf
{
    /**
     * How the note looks on paper: `simple` as it does on screen, `report` as
     * a formal document. The page is opened with ?print={style}, which the
     * layout turns into `data-print-style` on <html> for print.css to style.
     */
    public const STYLES = ['simple', 'report'];

    /** Page margins, kept in step with the width the printer lays the page out at. */
    private const MARGIN = ['top' => '16mm', 'right' => '14mm', 'bottom' => '16mm', 'left' => '14mm'];

    /**
     * Print the note to PDF bytes.
     *
     * $sessionCookie must be the cookie exactly as the browser sent it -- see
     * rawSessionCookie(). $warning comes back set when the PDF came out
     * incomplete (a picture that did not load, a page still drawing), which is
     * worth telling the user but not worth refusing the export over.
     *
     * @throws RuntimeException when what came back is not a PDF of the note
     */
    public static function bytes(Note $note, string $style, ?string $sessionCookie, ?string &$warning = null): string
    {
        if (! in_array($style, self::STYLES, true)) {
            throw new RuntimeException("There is no PDF style called '{$style}'");
        }

        $origin = rtrim((string) config('services.chrome.origin'), '/');

        $cookies = [];
        if (is_string($sessionCookie) && $sessionCookie !== '') {
            $cookies[] = [
                'name' => (string) config('session.cookie'),
                'value' => $sessionCookie,
                // Set on the host Chrome actually visits, not APP_URL's
                'domain' => (string) parse_url($origin, PHP_URL_HOST),
                'path' => '/',
            ];
        }

        $response = Http::timeout(120)->connectTimeout(10)->post(
            rtrim((string) config('services.chrome.url'), '/').'/pdf',
            [
                'url' => $origin.route('notes.show', ['note' => $note, 'print' => $style], absolute: false),
                'cookies' => $cookies,
                'format' => 'A4',
                'margin' => self::MARGIN,
            ],
        );

        if (! $response->successful()) {
            throw new RuntimeException('The PDF printer answered '.$response->status().': '.mb_substr(trim($response->body()), 0, 500));
        }

        // A cookie that did not sign in is answered with the login page, which
        // prints as a perfectly good PDF of the wrong thing
        if (preg_match('#/login(\?|$|/)#', (string) $response->header('X-Pdf-Final-Url'))) {
            throw new RuntimeException('The PDF printer was sent to the sign-in page: the session did not carry over');
        }

        $body = $response->body();

        if (! str_starts_with($body, '%PDF-')) {
            throw new RuntimeException('The PDF printer answered '.strlen($body).' bytes that are not a PDF');
        }

        $broken = (int) $response->header('X-Pdf-Broken');

        $warning = match (true) {
            $response->header('X-Pdf-Painted') === '0' => 'The note was still drawing when it printed, so something may be missing.',
            $broken === 1 => 'One picture did not load and is blank in the PDF.',
            $broken > 1 => "{$broken} pictures did not load and are blank in the PDF.",
            default => null,
        };

        return $body;
    }

    /**
     * The session cookie exactly as the browser sent it.
     *
     * $request->cookie() is no use: EncryptCookies has already swapped in the
     * decrypted session id, and the app only accepts the encrypted form back.
     * Nor is $_COOKIE, which PHP url-decodes -- turning the base64 `+` into a
     * space. So read the raw Cookie header and pass the value on untouched.
     */
    public static function rawSessionCookie(Request $request): ?string
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
