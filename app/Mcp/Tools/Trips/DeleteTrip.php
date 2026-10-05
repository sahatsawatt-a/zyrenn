<?php

namespace App\Mcp\Tools\Trips;

use App\Mcp\Tools\Concerns\DeletesThing;
use App\Mcp\Tools\TripTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Permanently delete a trip: its days, hotels and flights. Saved places it used stay saved.')]
class DeleteTrip extends TripTool
{
    use DeletesThing;
}
