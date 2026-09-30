<?php

namespace App\Support\Live;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Throwable;

/**
 * Telling whoever else has something open that it changed. The change is
 * saved already; if the socket server is down, the others simply see it on
 * their next load, so a failure here is reported and never fails the save.
 */
final class Live
{
    /**
     * Send to everyone watching but the one who made the change (the request's
     * X-Socket-ID), or to everyone when it came from elsewhere, like MCP.
     */
    public static function tell(ShouldBroadcast $event): void
    {
        try {
            broadcast($event)->toOthers();
        } catch (Throwable $problem) {
            report($problem);
        }
    }
}
