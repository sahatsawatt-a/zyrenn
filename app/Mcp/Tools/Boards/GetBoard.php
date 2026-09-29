<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Mcp\Tools\Concerns\GetsThing;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get everything on a board: each item with its kind, box, label and colours, and each connector with the items its ends are pinned to. Pass the list back to update-board to change it -- fields left out keep the value they have now, and a picture is reported as "has_picture" rather than its bytes.')]
class GetBoard extends BoardTool
{
    use GetsThing;
}
