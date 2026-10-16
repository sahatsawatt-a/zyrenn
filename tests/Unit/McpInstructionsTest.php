<?php

namespace Tests\Unit;

use App\Mcp\Servers\Instructions;
use Tests\TestCase;

/**
 * What the MCP servers tell a client fits where a client keeps it: some keep
 * only the first 4,096 characters, and whatever comes after never arrives.
 */
class McpInstructionsTest extends TestCase
{
    public function test_both_servers_instructions_fit_what_a_client_keeps()
    {
        foreach ([false, true] as $global) {
            $text = Instructions::text(global: $global);

            $this->assertLessThan(4000, mb_strlen($text), ($global ? 'admin' : 'personal').' instructions');
            // Every part is still named, with the way into it
            foreach (['Notes', 'Boards', 'Tables', 'Trips', 'Workflows', 'Browser checks', 'Tickets', 'Product', 'Chats', 'Drive'] as $part) {
                $this->assertStringContainsString($part, $text);
            }
        }
    }
}
