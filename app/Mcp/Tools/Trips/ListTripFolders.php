<?php

namespace App\Mcp\Tools\Trips;

use App\Mcp\Tools\Concerns\ListsFolders;
use App\Mcp\Tools\TripTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List every trip folder as a path (e.g. "2026/Japan"), with how many trips sit directly in it. Trip folders are their own tree, separate from note, board and table folders. Pass a path as "folder" to list-trips, create-trip or update-trip.')]
class ListTripFolders extends TripTool
{
    use ListsFolders;
}
