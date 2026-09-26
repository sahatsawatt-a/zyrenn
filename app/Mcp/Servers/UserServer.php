<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\DeleteBoard;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\ListBoardFolders;
use App\Mcp\Tools\Boards\ListBoards;
use App\Mcp\Tools\Boards\UpdateBoard;
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
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Zyrenn (personal)')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Zyrenn is a personal app holding notes, whiteboard boards, and a private file Drive. Every tool here
acts only on the content of the user who owns the access token.

Notes: bodies are read and written as Markdown (get-note, create-note, update-note). Notes live in
folders addressed by path, e.g. "KT Plan/Lakeshore".

Boards: endless whiteboard canvases of shapes, sticky notes, connectors, pictures and 16:9 frames
(list-boards, get-board, create-board, update-board, delete-board). A board is a list of items, each
with a "kind" -- rect, pill, ellipse, triangle, diamond, hexagon, star, sticky, text, frame, image,
"math" for a formula written as LaTeX, the flowchart set (cylinder for a database, parallelogram,
document, process, cloud), "arrow" for a connector and "draw" for freehand ink. Leave an item's x and y out and it is laid out for you. A
connector's "from" and "to" name other items by id and stay pinned to their edges as those shapes are
moved or resized, so a diagram survives being rearranged by hand afterwards. Boards have their own
folder tree, listed by list-board-folders.

Drive: the user's private files (list-drive, get-file, upload-file, update-file, delete-file). Files
are served only to their owner, from a URL like /drive/files/k3x9m2p7qa.

To put a picture in a note or on a board, the picture must be in the Drive first -- neither can carry
image bytes of its own:

  1. upload-file with the image bytes base64 encoded in "content_base64" and a name like "chart.png".
  2. For a note: take the "markdown" line from the response, e.g. ![chart.png](/drive/files/k3x9m2p7qa),
     and put it in the "markdown" you pass to create-note or update-note.
  3. For a board: take the "url" from the response and pass it as an item's "src" with kind "image".

An image already on the public web can be used instead -- ![alt](https://...) in a note, or that URL
as "src" on a board -- but it is not stored with the account and breaks if that site goes away.
TEXT)]
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
            new ListBoards,
            new ListBoardFolders,
            new GetBoard,
            new CreateBoard,
            new UpdateBoard,
            new DeleteBoard,
            new ListDrive,
            new GetFile,
            new UploadFile,
            new UpdateFile,
            new DeleteFile,
        ];
    }
}
