<?php

namespace App\Policies;

use App\Models\Board\Board;
use App\Models\Drive\DriveFile;
use App\Models\Folder;
use App\Models\Note\Note;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;

/**
 * Notes, boards, tables, Drive files and every kind of folder. A user's own
 * are theirs alone; a project's can be seen by its members and changed by
 * its owners and editors.
 */
class ContentPolicy
{
    /**
     * Determine whether the user can view the thing.
     */
    public function view(User $user, Note|Board|Table|DriveFile|Folder $thing): bool
    {
        return $this->role($user, $thing) !== null;
    }

    /**
     * Determine whether the user can update the thing.
     */
    public function update(User $user, Note|Board|Table|DriveFile|Folder $thing): bool
    {
        return $this->canChange($user, $thing);
    }

    /**
     * Determine whether the user can delete the thing.
     */
    public function delete(User $user, Note|Board|Table|DriveFile|Folder $thing): bool
    {
        return $this->canChange($user, $thing);
    }

    private function canChange(User $user, Note|Board|Table|DriveFile|Folder $thing): bool
    {
        return in_array($this->role($user, $thing), [Project::OWNER, Project::EDITOR], true);
    }

    /**
     * The user's role over the thing, or null when they have none. Someone's
     * own things are theirs to do anything with, as a project's owner can.
     */
    private function role(User $user, Note|Board|Table|DriveFile|Folder $thing): ?string
    {
        if ($thing->user_id !== null) {
            return $thing->user_id === $user->id ? Project::OWNER : null;
        }

        return $thing->project?->roleOf($user);
    }
}
