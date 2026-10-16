<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Users\ListUsers;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Zyrenn (admin)')]
#[Version('1.0.0')]
class GlobalServer extends Server
{
    protected function boot(): void
    {
        $this->instructions = Instructions::text(global: true);
        $this->tools = [new ListUsers, ...Toolset::tools(global: true)];
    }
}
