<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Drive\DeleteFile;
use App\Mcp\Tools\Drive\GetFile;
use App\Mcp\Tools\Drive\ListDrive;
use App\Mcp\Tools\Drive\UpdateFile;
use App\Mcp\Tools\Drive\UploadFile;
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
#[Instructions(<<<'TEXT'
Zyrenn is a personal notes app with a private file Drive attached. This server reaches every user's
content: call list-users first, then pass that user's id as "user_id" to every other tool.

Notes: bodies are read and written as Markdown (get-note, create-note, update-note). Notes live in
folders addressed by path, e.g. "KT Plan/Lakeshore".

Drive: each user's private files (list-drive, get-file, upload-file, update-file, delete-file). Files
are served only to their owner, from a URL like /drive/files/k3x9m2p7qa.

To put a picture in a note, the picture must be in that same user's Drive first -- a note cannot carry
image bytes of its own:

  1. upload-file with the image bytes base64 encoded in "content_base64" and a name like "chart.png".
  2. Take the "markdown" line from the response, e.g. ![chart.png](/drive/files/k3x9m2p7qa).
  3. Put that line in the "markdown" you pass to create-note or update-note, for the same user_id.

An image already on the public web can be embedded with a plain ![alt](https://...) link instead, but
it is not stored with the account and breaks if that site goes away.
TEXT)]
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
            new ListDrive(global: true),
            new GetFile(global: true),
            new UploadFile(global: true),
            new UpdateFile(global: true),
            new DeleteFile(global: true),
        ];
    }
}
