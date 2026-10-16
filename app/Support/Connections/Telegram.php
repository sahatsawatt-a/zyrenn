<?php

namespace App\Support\Connections;

use App\Models\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Telegram's Bot API, for a bot connection: where each method is and how a
 * request to it goes. Everything that talks to Telegram uses it -- workflow
 * steps (TelegramApi, TelegramFile), the bot's webhook and polling (Hooks,
 * TelegramPoll) and the check of a new connection (ConnectionCheck) -- and
 * reads the answer its own way. The bot's token is part of each address, so
 * an address is never logged or shown.
 */
final class Telegram
{
    private const API = 'https://api.telegram.org';

    /** The address of one of the bot's methods, e.g. "sendMessage". */
    public static function method(Connection|string $bot, string $method): string
    {
        return self::API.'/bot'.self::token($bot).'/'.$method;
    }

    /** The address of a file the bot was sent, from getFile's file_path. */
    public static function file(Connection|string $bot, string $path): string
    {
        return self::API.'/file/bot'.self::token($bot).'/'.$path;
    }

    /** A request to Telegram. */
    public static function http(int $timeout = 20): PendingRequest
    {
        return Http::timeout($timeout);
    }

    /**
     * Call one of the bot's methods; Telegram's answer as it came.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function post(Connection|string $bot, string $method, array $payload = [], int $timeout = 20): Response
    {
        return self::http($timeout)->post(self::method($bot, $method), $payload);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public static function get(Connection|string $bot, string $method, array $query = [], int $timeout = 20): Response
    {
        return self::http($timeout)->get(self::method($bot, $method), $query);
    }

    private static function token(Connection|string $bot): string
    {
        return $bot instanceof Connection ? (string) $bot->secret('bot_token') : $bot;
    }
}
