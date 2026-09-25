<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the app-level MCP server with the MCP_GLOBAL_TOKEN bearer token.
 * The server is disabled (404) until a token is configured.
 */
class AuthenticateGlobalMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.mcp.global_token');

        abort_if(blank($token), 404);

        if (! is_string($request->bearerToken()) || ! hash_equals($token, $request->bearerToken())) {
            return response()->json(['message' => 'Unauthenticated.'], 401, [
                'WWW-Authenticate' => 'Bearer realm="mcp-global"',
            ]);
        }

        return $next($request);
    }
}
