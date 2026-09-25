<?php

namespace App\Policies;

use App\Models\NoteFolder;
use App\Models\User;

class NoteFolderPolicy
{
    /**
     * Determine whether the user can view the folder. Only the owner can.
     */
    public function view(User $user, NoteFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    /**
     * Determine whether the user can update the folder.
     */
    public function update(User $user, NoteFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the folder.
     */
    public function delete(User $user, NoteFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }
}
