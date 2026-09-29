<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\Concerns\ListsThings;
use App\Mcp\Tools\NoteTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List notes, most recently edited first. Optionally narrow to one folder, or search titles and note text.')]
class ListNotes extends NoteTool
{
    use ListsThings;
}
