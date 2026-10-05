<?php

namespace App\Mcp\Tools\Trips;

use App\Mcp\Tools\Concerns\ListsThings;
use App\Mcp\Tools\TripTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List trips, most recently edited first, with when each starts and how many days it has. Optionally narrow to one folder, or search titles and the places on the trips.')]
class ListTrips extends TripTool
{
    use ListsThings;
}
