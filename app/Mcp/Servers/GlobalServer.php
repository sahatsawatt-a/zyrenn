<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Notes\CreateNote;
use App\Mcp\Tools\Notes\DeleteNote;
use App\Mcp\Tools\Notes\GetNote;
use App\Mcp\Tools\Notes\ListFolders;
use App\Mcp\Tools\Notes\ListNotes;
use App\Mcp\Tools\Notes\UpdateNote;
use App\Mcp\Tools\Users\ListUsers;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Zyrenn (admin)')]
#[Version('1.0.0')]
#[Instructions('App-level access to every user\'s notes. Use list-users to find a user, then pass its id as user_id to the note tools. Note bodies are read and written as Markdown.')]
class GlobalServer extends Server
{
    protected function boot(): void
    {
        $this->tools = [
            new ListUsers,
            new ListNotes(global: true),
            new ListFolders(global: true),
            new GetNote(global: true),
            new CreateNote(global: true),
            new UpdateNote(global: true),
            new DeleteNote(global: true),
        ];
    }
}
