<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Members see a project; owners and editors add to it; owners run it.
 */
class ProjectPolicy
{
    /**
     * Determine whether the user can open the project and what is in it.
     */
    public function view(User $user, Project $project): bool
    {
        return $project->roleOf($user) !== null;
    }

    /**
     * Determine whether the user can make notes, boards, tables, files and
     * folders in the project.
     */
    public function contribute(User $user, Project $project): bool
    {
        return in_array($project->roleOf($user), [Project::OWNER, Project::EDITOR], true);
    }

    /**
     * Determine whether the user can rename the project.
     */
    public function update(User $user, Project $project): bool
    {
        return $project->roleOf($user) === Project::OWNER;
    }

    /**
     * Determine whether the user can delete the project with everything in it.
     */
    public function delete(User $user, Project $project): bool
    {
        return $project->roleOf($user) === Project::OWNER;
    }
}
