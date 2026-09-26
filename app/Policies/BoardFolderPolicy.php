<?php

namespace App\Policies;

use App\Models\BoardFolder;
use App\Models\User;

class BoardFolderPolicy
{
    /**
     * Determine whether the user can view the folder.
     */
    public function view(User $user, BoardFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    /**
     * Determine whether the user can update the folder.
     */
    public function update(User $user, BoardFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the folder.
     */
    public function delete(User $user, BoardFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }
}
