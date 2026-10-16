<?php

namespace App\Mcp\Servers;

use App\Mcp\Scopes;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Zyrenn (personal)')]
#[Version('1.0.0')]
class UserServer extends Server
{
    protected function boot(): void
    {
        // Only what the key in use lets it see
        $user = Auth::user();
        $this->instructions = Instructions::text(global: false, parts: Scopes::seen($user instanceof User ? $user->currentAccessToken() : null));
        $this->tools = Toolset::tools(global: false);
    }
}
