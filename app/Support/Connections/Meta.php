<?php

namespace App\Support\Connections;

use Illuminate\Http\Client\Response;

/**
 * What Facebook, Instagram, Threads and WhatsApp share, being Meta's: the
 * version their calls are written against, and how a refusal is said.
 */
final class Meta
{
    /** The Graph API version asked for. Meta answers to one for about two years: move it on before then. */
    public const VERSION = 'v25.0';

    public const FACEBOOK = 'https://graph.facebook.com/'.self::VERSION;

    public const INSTAGRAM = 'https://graph.instagram.com/'.self::VERSION;

    /** Threads counts its versions by itself. */
    public const THREADS = 'https://graph.threads.net/v1.0';

    /**
     * What Meta answered, as far as it says: its own words for the person
     * where it has them, else the ones for whoever wrote the app.
     */
    public static function said(Response $response): string
    {
        $error = $response->json('error');
        $said = is_array($error)
            ? ($error['error_user_msg'] ?? $error['error_data']['details'] ?? $error['message'] ?? null)
            : ($response->json('error_message') ?? $response->json('error_description') ?? $error);

        return is_string($said) && $said !== '' ? rtrim($said, '.').'.' : "it answered with {$response->status()}.";
    }
}
