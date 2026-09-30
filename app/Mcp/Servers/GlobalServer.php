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
use App\Mcp\Tools\Notes\EditNote;
use App\Mcp\Tools\Notes\GetNote;
use App\Mcp\Tools\Notes\ListFolders;
use App\Mcp\Tools\Notes\ListNotes;
use App\Mcp\Tools\Notes\UpdateNote;
use App\Mcp\Tools\Projects\ListProjects;
use App\Mcp\Tools\Tables\CreateTable;
use App\Mcp\Tools\Tables\DeleteTable;
use App\Mcp\Tools\Tables\GetTable;
use App\Mcp\Tools\Tables\ListTableFolders;
use App\Mcp\Tools\Tables\ListTables;
use App\Mcp\Tools\Tables\UpdateTable;
use App\Mcp\Tools\Users\ListUsers;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Zyrenn (admin)')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Zyrenn holds notes, whiteboard boards, tables and a file Drive. This server can act as any user: call
list-users first, then pass that user's id as "user_id" to every other tool.

Projects: shared spaces with notes, boards, tables and a Drive of their own, which belong to the project
rather than to whoever made them. list-projects shows the ones a user is in, with their role in each.
Pass a project's ref_id or name as "project" to any other tool to work in it as that user; leave it out
for the user's own content. A viewer can only read a project; its owners and editors can change it.

Notes: bodies are read and written as Markdown (get-note, create-note, update-note, edit-note). Notes
live in folders addressed by path, e.g. "KT Plan/Lakeshore". Every block of a note -- a paragraph,
heading, list, table -- has an id: get-note gives a long note as an outline of them, and reads just
the blocks or section you ask for; edit-note replaces, inserts, moves or deletes blocks by id. For
anything short of rewriting a note, read and change only the part you need.

Boards: endless whiteboard canvases of shapes, sticky notes, connectors, pictures and 16:9 frames
(list-boards, get-board, create-board, update-board, delete-board). A board is a list of items, each
with a "kind" -- rect, pill, ellipse, triangle, diamond, hexagon, star, sticky, text, frame, image,
"math" for a formula written as LaTeX, the flowchart set (cylinder for a database, parallelogram,
document, process, cloud), "arrow" for a connector and "draw" for freehand ink. Leave an item's x and y out and it is laid out for you. A
connector's "from" and "to" name other items by id and stay pinned to their edges as those shapes are
moved or resized, so a diagram survives being rearranged by hand afterwards. Boards have their own
folder tree, listed by list-board-folders. get-board lists a board's frames, reads one frame with
"frame", and gives a big board in outline; update-board's add_items, update_items and delete_items
change items by id, sending only what changes -- prefer them to rewriting "items" whole.

Tables: rows and columns, each column of one kind -- varchar, text, integer, numeric, boolean,
select, multi_select, date, email, url, phone, currency, percent, rating, user (list-tables,
get-table, create-table, update-table, delete-table). A row is written as an object of values keyed
by column label, e.g. {"Owner": "Ada", "Budget": 300}; update-table changes or deletes rows by the
"id" get-table shows. Tables have their own folder tree, listed by list-table-folders. get-table reads
a page of rows at a time ("limit", "offset"), and can keep only rows matching a "search" and only the
"columns" you name -- read what you need rather than the whole table.

Drive: files (list-drive, get-file, upload-file, update-file, delete-file) -- each user's private ones,
or a project's, which its members can all open. Files are served from a URL like /drive/files/k3x9m2p7qa.

To put a picture in a note or on a board, the picture must be in the same Drive first -- the same
user_id, and for a project's note or board the same "project" -- as neither can carry image bytes of
its own:

  1. upload-file with the image bytes base64 encoded in "content_base64" and a name like "chart.png".
  2. For a note: take the "markdown" line from the response, e.g. ![chart.png](/drive/files/k3x9m2p7qa),
     and put it in the "markdown" you pass to create-note or update-note, for the same user_id and project.
  3. For a board: take the "url" from the response and pass it as an item's "src" with kind "image".

An image already on the public web can be used instead -- ![alt](https://...) in a note, or that URL
as "src" on a board -- but it is not stored with the account and breaks if that site goes away.
TEXT)]
class GlobalServer extends Server
{
    protected function boot(): void
    {
        $this->tools = [
            new ListUsers,
            new ListProjects(global: true),
            new ListNotes(global: true),
            new ListFolders(global: true),
            new GetNote(global: true),
            new CreateNote(global: true),
            new UpdateNote(global: true),
            new EditNote(global: true),
            new DeleteNote(global: true),
            new ListBoards(global: true),
            new ListBoardFolders(global: true),
            new GetBoard(global: true),
            new CreateBoard(global: true),
            new UpdateBoard(global: true),
            new DeleteBoard(global: true),
            new ListTables(global: true),
            new ListTableFolders(global: true),
            new GetTable(global: true),
            new CreateTable(global: true),
            new UpdateTable(global: true),
            new DeleteTable(global: true),
            new ListDrive(global: true),
            new GetFile(global: true),
            new UploadFile(global: true),
            new UpdateFile(global: true),
            new DeleteFile(global: true),
        ];
    }
}
