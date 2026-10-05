<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Owner;
use App\Models\Project;
use App\Support\OwnerUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * For the routes registered with Route::owned(): whose things a request is
 * about -- the project in the URL, or else the user's own -- and the way back
 * to them.
 */
trait ActsForOwner
{
    /**
     * The owner the request acts for. In a project the user must be allowed
     * the ability: "view" to look, "contribute" to make something.
     */
    protected function owner(Request $request, string $ability = 'view'): Owner
    {
        $project = $request->route('project');

        if (! $project instanceof Project) {
            return $request->user();
        }

        Gate::authorize($ability, $project);

        return $project;
    }

    /**
     * The URL of an owned route for this owner, e.g. "notes.index" -- at
     * /notes for the user's own, at /p/{project}/notes for a project's.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected static function ownerRoute(Owner $owner, string $name, array $parameters = []): string
    {
        return OwnerUrl::to($owner, $name, $parameters);
    }
}
