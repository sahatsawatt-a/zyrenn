<?php

namespace App\Support\Connections;

use App\Models\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * LINE's Messaging API, for a channel connection: where it is and how a
 * request to it is signed. Everything that talks to LINE uses it --
 * workflow steps (LineApi, LineSend, LineFile, LineProfile), the channel's
 * webhook (Hooks) and the check of a new connection (ConnectionCheck) --
 * and reads the answer its own way.
 */
final class Line
{
    private const API = 'https://api.line.me/v2/bot/';

    /** Where LINE takes and gives the bytes of a file. */
    private const DATA = 'https://api-data.line.me/v2/bot/';

    /** An address in the API, from a path such as "message/push". */
    public static function api(string $path): string
    {
        return self::API.ltrim($path, '/');
    }

    /** An address where file bytes go and come, e.g. "message/{id}/content". */
    public static function data(string $path): string
    {
        return self::DATA.ltrim($path, '/');
    }

    /** A request signed as the channel. */
    public static function http(Connection $channel, int $timeout = 15): PendingRequest
    {
        return Http::timeout($timeout)
            ->withToken((string) $channel->secret('channel_access_token'));
    }
}
