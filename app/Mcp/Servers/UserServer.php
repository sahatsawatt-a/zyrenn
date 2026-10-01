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
use App\Mcp\Tools\Drive\RequestUpload;
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
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Zyrenn (personal)')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Zyrenn holds notes, whiteboard boards, tables and a file Drive. Every tool here acts as the user who owns
the access token, on their own content -- or, given a "project" argument, on a project they are in.

Projects: shared spaces with notes, boards, tables and a Drive of their own, which belong to the project
rather than to whoever made them. list-projects shows the user's, with their role in each. Pass a
project's ref_id or name as "project" to any other tool to work in it; leave it out for the user's own.
A viewer can only read a project; its owners and editors can change what is in it.

Notes: bodies are read and written as Markdown (get-note, create-note, update-note, edit-note). Notes
live in folders addressed by path, e.g. "KT Plan/Lakeshore". Every block of a note -- a paragraph,
heading, list, table -- has an id: get-note gives a long note as an outline of them, and reads just
the blocks or section you ask for; edit-note replaces, inserts, moves or deletes blocks by id. For
anything short of rewriting a note, read and change only the part you need.

Boards: endless whiteboard canvases of shapes, sticky notes, connectors, pictures and 16:9 frames
(list-boards, get-board, create-board, update-board, delete-board). A board is a list of items, each
with a "kind" -- rect, pill, ellipse, triangle, diamond, hexagon, star, sticky, text, frame, image,
video, "math" for a formula written as LaTeX, the flowchart set (cylinder for a database, parallelogram,
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

Drive: files (list-drive, get-file, upload-file, request-upload, update-file, delete-file) -- the user's private ones,
or a project's, which its members can all open. Files are served from a URL like /drive/files/k3x9m2p7qa.

To put a picture in a note or on a board, the picture must be in the Drive first -- neither can carry
image bytes of its own. For a project's note or board, upload it to that project's Drive (the same
"project"), or its other members won't be able to see it:

  1. Upload it -- the bytes never go through a tool call:
     - a file on your own disk: request-upload, then run the "curl" command it gives with the file's path;
     - a file on the public web: upload-file with its URL in "source_url".
  2. For a note: take the "markdown" line from the response, e.g. ![chart.png](/drive/files/k3x9m2p7qa),
     and put it in the "markdown" you pass to create-note or update-note.
  3. For a board: take the "url" from the response and pass it as an item's "src" with kind "image".

An image already on the public web can be used instead -- ![alt](https://...) in a note, or that URL
as "src" on a board -- but it is not stored with the account and breaks if that site goes away.

A video (MP4 or WebM) goes the same way and plays where it is put: upload it, then put the "markdown"
line from the response -- <video src="/drive/files/k3x9m2p7qa" title="demo.mp4"></video> -- on a line
of its own in a note, or pass the "url" as "src" with kind "video" on a board.
TEXT)]
class UserServer extends Server
{
    protected function boot(): void
    {
        $this->tools = [
            new ListProjects,
            new ListNotes,
            new ListFolders,
            new GetNote,
            new CreateNote,
            new UpdateNote,
            new EditNote,
            new DeleteNote,
            new ListBoards,
            new ListBoardFolders,
            new GetBoard,
            new CreateBoard,
            new UpdateBoard,
            new DeleteBoard,
            new ListTables,
            new ListTableFolders,
            new GetTable,
            new CreateTable,
            new UpdateTable,
            new DeleteTable,
            new ListDrive,
            new GetFile,
            new UploadFile,
            new RequestUpload,
            new UpdateFile,
            new DeleteFile,
        ];
    }
}
