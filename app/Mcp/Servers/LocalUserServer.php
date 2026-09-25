<?php

namespace App\Mcp\Servers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * Stdio variant of the personal server (`php artisan mcp:start zyrenn`).
 *
 * There are no HTTP headers over stdio, so the caller passes the same personal
 * token from Settings → MCP in the MCP_TOKEN environment variable. The token
 * is re-checked on every call, so revoking it cuts off a running session.
 */
class LocalUserServer extends UserServer
{
    protected function boot(): void
    {
        $plainToken = getenv('MCP_TOKEN') ?: null;

        if (! $plainToken || ! static::userForToken($plainToken)) {
            throw new RuntimeException('Set MCP_TOKEN to a valid token from Settings → MCP.');
        }

        Auth::resolveUsersUsing(fn () => static::userForToken($plainToken));

        parent::boot();
    }

    /**
     * Resolves the owner of an unrevoked token that carries the `mcp` ability.
     */
    public static function userForToken(string $plainToken): ?User
    {
        $token = PersonalAccessToken::findToken($plainToken);

        $user = $token?->tokenable;

        if (! $token || ! $token->can('mcp') || ! $user instanceof User) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $user;
    }
}
