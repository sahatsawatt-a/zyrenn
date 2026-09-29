<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\Concerns\ListsFolders;
use App\Mcp\Tools\NoteTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List every note folder as a path (e.g. "KT Plan/Lakeshore"), with how many notes sit directly in it. Pass a path as "folder" to list-notes, create-note or update-note.')]
class ListFolders extends NoteTool
{
    use ListsFolders;
}
