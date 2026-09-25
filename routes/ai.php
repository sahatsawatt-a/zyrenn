<?php

use App\Http\Middleware\AuthenticateGlobalMcp;
use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\LocalGlobalServer;
use App\Mcp\Servers\LocalUserServer;
use App\Mcp\Servers\UserServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Per-user: authenticated with a personal token from Settings → MCP; tools only see that user's data
Mcp::web('/mcp/user', UserServer::class)
    ->middleware(['auth:sanctum', CheckAbilities::class.':mcp', 'throttle:mcp']);

// App-level: authenticated with MCP_GLOBAL_TOKEN; tools choose the target user
Mcp::web('/mcp/global', GlobalServer::class)
    ->middleware([AuthenticateGlobalMcp::class, 'throttle:mcp']);

// Stdio (`php artisan mcp:start <handle>`): the personal one reads its token from MCP_TOKEN
Mcp::local('zyrenn', LocalUserServer::class);
Mcp::local('zyrenn-admin', LocalGlobalServer::class);
