<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Zyrenn (personal)')]
#[Version('1.0.0')]
class UserServer extends Server
{
    protected function boot(): void
    {
        $this->instructions = Instructions::text(global: false);
        $this->tools = Toolset::tools(global: false);
    }
}
