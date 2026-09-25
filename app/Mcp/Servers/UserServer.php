<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Notes\CreateNote;
use App\Mcp\Tools\Notes\DeleteNote;
use App\Mcp\Tools\Notes\GetNote;
use App\Mcp\Tools\Notes\ListFolders;
use App\Mcp\Tools\Notes\ListNotes;
use App\Mcp\Tools\Notes\UpdateNote;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Zyrenn (personal)')]
#[Version('1.0.0')]
#[Instructions('Manage the signed-in user\'s own notes. Every tool acts only on the notes of the user who owns the access token. Note bodies are read and written as Markdown.')]
class UserServer extends Server
{
    protected function boot(): void
    {
        $this->tools = [
            new ListNotes,
            new ListFolders,
            new GetNote,
            new CreateNote,
            new UpdateNote,
            new DeleteNote,
        ];
    }
}
