<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\Concerns\GetsThing;
use App\Mcp\Tools\NoteTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get a note with its content as Markdown.')]
class GetNote extends NoteTool
{
    use GetsThing;
}
