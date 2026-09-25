<?php

namespace App\Mcp\Servers;

use RuntimeException;

/**
 * Stdio variant of the app-level server (`php artisan mcp:start zyrenn-admin`).
 *
 * Whoever can run artisan already has full database access, so no token is
 * needed, but it stays disabled whenever the global server is (no MCP_GLOBAL_TOKEN).
 */
class LocalGlobalServer extends GlobalServer
{
    protected function boot(): void
    {
        if (blank(config('services.mcp.global_token'))) {
            throw new RuntimeException('The global MCP server is disabled; set MCP_GLOBAL_TOKEN to enable it.');
        }

        parent::boot();
    }
}
