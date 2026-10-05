<?php

namespace App\Support;

use App\Models\Owner;
use App\Models\Project;

/**
 * Where an owner's page is: a route defined with Route::owned, e.g.
 * "notes.index" -- at /notes for the user's own, at /p/{project}/notes for
 * a project's.
 */
final class OwnerUrl
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function to(Owner $owner, string $name, array $parameters = []): string
    {
        return $owner instanceof Project
            ? route("projects.{$name}", ['project' => $owner, ...$parameters])
            : route($name, $parameters);
    }
}
