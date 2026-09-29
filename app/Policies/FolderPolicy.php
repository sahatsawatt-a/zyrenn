<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;

/**
 * Every kind of folder, of notes, boards, tables or files: only its owner
 * can open, change or delete it.
 */
class FolderPolicy
{
    /**
     * Determine whether the user can view the folder.
     */
    public function view(User $user, Folder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    /**
     * Determine whether the user can update the folder.
     */
    public function update(User $user, Folder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the folder.
     */
    public function delete(User $user, Folder $folder): bool
    {
        return $folder->user_id === $user->id;
    }
}
